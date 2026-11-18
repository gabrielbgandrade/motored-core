<?php
/**
 * Send-time handler registered with Waha\Sender for kind "recovery_step".
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Integration\WooCommerce\ProductLookup;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Recovery\Emails\AbandonedCartEmail;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Validates candidates at send time (window, active status, recent order, stock, suppression, limits)
 * and produces the WhatsApp Message or triggers email.
 */
final class RecoverySenderHandler {

	/**
	 * Callback registered with Sender::register_handler('recovery_step', ...).
	 *
	 * @param int         $recovery_id Recovery row ID.
	 * @param string|null $step        Step number as string ('1', '2', '3').
	 * @return Message|null
	 */
	public function handle( int $recovery_id, ?string $step ): ?Message {
		if ( $recovery_id <= 0 ) {
			return null;
		}

		$row = Repository::get( $recovery_id );
		if ( null === $row || Status::ACTIVE !== $row['status'] ) {
			return null;
		}

		if ( 'order' === (string) $row['type'] ) {
			return ( new OrderStepHandler() )->handle( $row, $step );
		}

		// 1. Sending window check.
		if ( ! self::within_window( $recovery_id ) ) {
			return null;
		}

		// 2. Check if an order already exists since last activity.
		$recent_order = CartHooks::find_order_after(
			(string) $row['email'],
			(int) $row['user_id'],
			(string) $row['last_activity_gmt']
		);
		if ( null !== $recent_order && $recent_order > 0 ) {
			// Bought in another session: stop the cart sequence. Attribution only happens when an order is paid (OrderSync).
			$closed = Repository::update_if_status(
				$recovery_id,
				Status::ACTIVE,
				array(
					'status'          => Status::CONVERTED,
					'order_id'        => $recent_order,
					'next_action_gmt' => null,
					'updated_gmt'     => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( $closed ) {
				History::log( $recovery_id, 'order_created', 'none', 'system', 0, 'ok', 'order_id=' . $recent_order );
			}
			return null;
		}

		// 3. Stock check: skip if all items are out of stock.
		$items_data     = Snapshot::decode( (string) $row['items'] );
		$resolved_items = ProductLookup::resolve( $items_data );
		$available      = array_filter( $resolved_items, static fn( $i ) => ProductLookup::IN_STOCK === $i['stock'] );
		if ( array() === $available ) {
			return null;
		}

		// 4. Resolve step config.
		$step_num = is_numeric( $step ) ? (int) $step : Config::next_step_num( (string) $row['step'] );
		if ( null === $step_num || $step_num < 1 || $step_num > 3 ) {
			return null;
		}
		$step_cfg = Config::step( $step_num );
		$channel  = $step_cfg['channel'];
		if ( 'off' === $channel ) {
			return null;
		}

		// 5. Build URLs.
		$token        = RecoveryToken::get_or_generate( $recovery_id, Config::token_validity_days() );
		$recovery_url = RecoveryToken::recovery_url( $recovery_id, $token );
		$optout_url   = RecoveryToken::optout_url( $recovery_id );

		// 6. Handle WhatsApp channel.
		if ( 'whatsapp' === $channel ) {
			$phone = (string) $row['phone'];

			// Suppression check.
			if ( Suppression::is_suppressed( $phone, 'whatsapp' ) ) {
				return $this->maybe_fallback_email( $row, $step_num, $recovery_url, $optout_url );
			}

			// Soft message limit per phone.
			if ( ! MessageLimiter::can_send( $phone, Config::limit_24h(), Config::limit_week() ) ) {
				return $this->maybe_fallback_email( $row, $step_num, $recovery_url, $optout_url );
			}

			// Phone normalization.
			$normalized_phone = Phone::normalize( $phone, WahaConfig::country_code() );
			if ( null === $normalized_phone ) {
				return $this->maybe_fallback_email( $row, $step_num, $recovery_url, $optout_url );
			}

			// Format message.
			$items_formatted = Renderer::format_items( $resolved_items, 3 );
			$total_formatted = Renderer::format_price( (string) $row['total'] );
			$vars            = array(
				'first_name'   => (string) $row['first_name'],
				'cart_items'   => $items_formatted,
				'cart_total'   => $total_formatted,
				'recovery_url' => $recovery_url,
				'optout_url'   => $optout_url,
				'store_name'   => get_bloginfo( 'name' ),
			);
			$text            = Renderer::render( $step_cfg['text'], $vars );
			if ( '' === $text ) {
				return null;
			}

			MessageLimiter::hit( $phone );
			return new Message( $normalized_phone, $text );
		}

		// 7. Handle Email channel.
		if ( 'email' === $channel ) {
			$this->send_email_step( $row, $step_num, $recovery_url, $optout_url, false );
			return null;
		}

		return null;
	}

	/**
	 * Sending window check shared by carts and orders: outside the window the row is rescheduled
	 * to the next window start.
	 *
	 * @param int $recovery_id Recovery row ID.
	 */
	public static function within_window( int $recovery_id ): bool {
		$now = time();
		if ( Schedule::is_inside_window( $now, Config::window_start(), Config::window_end() ) ) {
			return true;
		}
		$next_start = Schedule::next_window_start( $now, Config::window_start(), Config::window_end() );
		Repository::update( $recovery_id, array( 'next_action_gmt' => gmdate( 'Y-m-d H:i:s', $next_start ) ) );
		return false;
	}

	/**
	 * Tries email fallback when WhatsApp cannot be sent.
	 *
	 * @param array<string,mixed> $row          Recovery row.
	 * @param int                 $step_num     Step number.
	 * @param string              $recovery_url Recovery URL.
	 * @param string              $optout_url   Opt-out URL.
	 */
	private function maybe_fallback_email( array $row, int $step_num, string $recovery_url, string $optout_url ): null {
		if ( ! Config::fallback_email() || empty( $row['email'] ) ) {
			return null;
		}

		$this->send_email_step( $row, $step_num, $recovery_url, $optout_url, true );
		return null;
	}

	/**
	 * Sends recovery email and updates progress.
	 *
	 * @param array<string,mixed> $row          Recovery row.
	 * @param int                 $step_num     Step number.
	 * @param string              $recovery_url Recovery URL.
	 * @param string              $optout_url   Opt-out URL.
	 * @param bool                $is_fallback  Whether this was a fallback.
	 */
	public function send_email_step( array $row, int $step_num, string $recovery_url, string $optout_url, bool $is_fallback ): bool {
		if ( 'order' === (string) ( $row['type'] ?? 'cart' ) ) {
			return OrderStepHandler::send_email( $row, $step_num, $is_fallback );
		}
		$email = (string) $row['email'];
		if ( '' === $email || Suppression::is_suppressed( $email, 'email' ) ) {
			return false;
		}

		if ( ! class_exists( AbandonedCartEmail::class ) ) {
			require_once __DIR__ . '/Emails/AbandonedCartEmail.php';
		}

		$mailer = new AbandonedCartEmail();
		$sent   = $mailer->trigger_recovery( $row, $recovery_url, $optout_url );
		$id     = (int) $row['id'];
		$detail = $is_fallback ? 'fallback_step=' . $step_num : 'step=' . $step_num;

		if ( $sent ) {
			History::log( $id, 'sent', 'email', 'auto', 0, 'ok', $detail );
			RecoveryResultHandler::advance_step( $id, $step_num, 'email', true );
			return true;
		}

		History::log( $id, 'failed', 'email', 'auto', 0, 'failed', $detail );
		return false;
	}
}
