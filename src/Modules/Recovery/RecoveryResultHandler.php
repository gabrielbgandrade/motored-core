<?php
/**
 * Result handler for recovery messages (Waha result hook and email outcome).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\Waha\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Updates recovery row counters, records event history, and calculates the next step / expiration.
 */
final class RecoveryResultHandler {

	public const KIND = 'recovery_step';

	/**
	 * Listener for `motored_waha_result`.
	 *
	 * @param string      $kind   Job kind.
	 * @param int         $ref_id Recovery row ID.
	 * @param string|null $step   Step number ('1', '2', '3').
	 * @param Result      $result Result from Waha\Sender.
	 */
	public static function on_result( string $kind, int $ref_id, ?string $step, Result $result ): void {
		if ( self::KIND !== $kind || $ref_id <= 0 ) {
			return;
		}

		// Skipped by handler (e.g. out of stock, already converted, outside window).
		$step_num = is_numeric( $step ) ? (int) $step : 1;

		if ( ! $result->ok && 'skipped' === $result->code ) {
			self::release_skipped( $ref_id, $step_num );
			return;
		}

		if ( $result->ok ) {
			History::log( $ref_id, 'sent', 'whatsapp', 'auto', 0, 'ok', 'step=' . $step_num );
			OrderNote::record( $ref_id, 'whatsapp', 'auto', true );
			self::advance_step( $ref_id, $step_num, 'whatsapp', true );
			return;
		}

		// WhatsApp failed.
		$short_detail = 'step=' . $step_num . ' ' . substr( $result->code, 0, 50 );
		History::log( $ref_id, 'failed', 'whatsapp', 'auto', 0, 'failed', $short_detail );
		OrderNote::record( $ref_id, 'whatsapp', 'auto', false );

		// Check if we should fallback to email.
		if ( Config::fallback_email() ) {
			$row = Repository::get( $ref_id );
			if ( null !== $row && Status::ACTIVE === $row['status'] && ! empty( $row['email'] ) ) {
				$token        = RecoveryToken::get_or_generate( $ref_id, Config::token_validity_days() );
				$recovery_url = RecoveryToken::recovery_url( $ref_id, $token );
				$optout_url   = RecoveryToken::optout_url( $ref_id );

				$sender_handler = new RecoverySenderHandler();
				$sent           = $sender_handler->send_email_step( $row, $step_num, $recovery_url, $optout_url, true );
				if ( $sent ) {
					return;
				}
			}
		}

		// Failed and no fallback succeeded: advance past this step anyway to prevent stuck queue.
		self::advance_step( $ref_id, $step_num, 'whatsapp', false );
	}

	/**
	 * The scanner clears next_action_gmt when it claims a row. A skipped step that neither rescheduled the row
	 * (sending window), closed it (converted/paid/expired) nor advanced it (e-mail sent) would leave it active
	 * and never scanned again: move it past the step so the sequence continues or ends.
	 *
	 * @param int $ref_id   Recovery id.
	 * @param int $step_num Skipped step.
	 */
	private static function release_skipped( int $ref_id, int $step_num ): void {
		$row = Repository::get( $ref_id );
		if ( null === $row || Status::ACTIVE !== $row['status'] || ! empty( $row['next_action_gmt'] ) ) {
			return;
		}
		self::advance_step( $ref_id, $step_num, 'whatsapp', false );
	}

	/**
	 * Advances the row past the completed step, increments sent counts if successful,
	 * schedules the next step or marks the row expired.
	 *
	 * @param int    $recovery_id    Recovery row ID.
	 * @param int    $completed_step Step number just processed (1, 2, 3).
	 * @param string $channel        'whatsapp' or 'email'.
	 * @param bool   $success        Whether the step was delivered successfully.
	 */
	public static function advance_step( int $recovery_id, int $completed_step, string $channel, bool $success ): void {
		if ( $recovery_id <= 0 ) {
			return;
		}

		$row = Repository::get( $recovery_id );
		if ( null === $row || Status::ACTIVE !== $row['status'] ) {
			return;
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$fields = array(
			'step'           => (string) max( (int) $row['step'], $completed_step ),
			'last_event_gmt' => $now,
		);

		if ( $success ) {
			if ( 'whatsapp' === $channel ) {
				$fields['wa_sent_count'] = (int) $row['wa_sent_count'] + 1;
			} elseif ( 'email' === $channel ) {
				$fields['email_sent_count'] = (int) $row['email_sent_count'] + 1;
			}
		}

		$activity_ts = Status::timestamp( (string) $row['last_activity_gmt'] ) ?? time();
		if ( 'order' === (string) ( $row['type'] ?? 'cart' ) ) {
			$variant   = OrderStepHandler::current_case( $row );
			$due_ts    = null === $variant ? null : OrderRecovery::next_action_ts( $variant, $completed_step, $activity_ts );
			$target_ts = null === $due_ts ? null : max( time() + 60, $due_ts );
		} else {
			$next_step = Config::next_step_num( $completed_step );
			$target_ts = null === $next_step ? null : max( time() + 60, $activity_ts + ( Config::step( $next_step )['delay'] * 60 ) );
		}

		if ( null !== $target_ts ) {
			$fields['next_action_gmt'] = gmdate( 'Y-m-d H:i:s', $target_ts );
		} else {
			$fields['status']          = Status::EXPIRED;
			$fields['next_action_gmt'] = null;
			History::log( $recovery_id, 'expired', 'none', 'system', 0, 'ok', 'sequence_finished' );
		}

		Repository::update( $recovery_id, $fields );
	}
}
