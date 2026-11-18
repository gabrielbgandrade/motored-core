<?php
/**
 * Send-time logic for type=order recovery rows.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Recovery\Emails\AbandonedCartEmail;
use Motored\Core\Modules\Recovery\Emails\PendingPaymentEmail;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Branch of RecoverySenderHandler for orders. ALWAYS reloads the order before sending (the payment
 * may have been confirmed by the gateway in the meantime), then reuses the window, suppression,
 * per-number limits, WhatsApp -> e-mail fallback and recovery links of the cart flow.
 */
final class OrderStepHandler {

	/**
	 * Builds the WhatsApp message for the step, or sends the e-mail / closes the row. Returns null
	 * when nothing is sent through WhatsApp.
	 *
	 * @param array<string,mixed> $row  Recovery row (type = order).
	 * @param string|null         $step Step number as string.
	 */
	public function handle( array $row, ?string $step ): ?Message {
		$id       = (int) $row['id'];
		$step_num = is_numeric( $step ) ? (int) $step : (int) $row['step'] + 1;
		if ( $step_num < 1 || $step_num > OrderCase::STEPS ) {
			OrderSync::expire( $id, 'sequence_finished' );
			return null;
		}
		if ( ! RecoverySenderHandler::within_window( $id ) ) {
			return null;
		}

		$order    = OrderHooks::load_order( (int) $row['order_id'] );
		$decision = OrderRecovery::decide( $row, null === $order ? null : OrderHooks::facts( $order ), OrderHooks::paid_statuses(), Config::order_cancelled_fallback() );
		if ( 'paid' === $decision['action'] ) {
			OrderSync::resolve_paid( $row, (int) $row['order_id'], gmdate( 'Y-m-d H:i:s' ) );
			return null;
		}
		if ( null === $order || ! in_array( $decision['action'], array( 'send', 'restore' ), true ) ) {
			OrderSync::expire( $id, $decision['reason'] );
			return null;
		}

		$variant = $decision['case'];
		$cfg     = OrderRecovery::step_config( $variant, $step_num );
		if ( 'off' === $cfg['channel'] ) {
			RecoveryResultHandler::advance_step( $id, $step_num, 'whatsapp', false );
			return null;
		}
		if ( 'email' === $cfg['channel'] ) {
			self::deliver_email( $row, $order, $variant, $step_num, false );
			return null;
		}

		$phone = (string) $row['phone'];
		$norm  = '' === $phone ? null : Phone::normalize( $phone, WahaConfig::country_code() );
		if ( null === $norm || Suppression::is_suppressed( $phone, 'whatsapp' ) || ! MessageLimiter::can_send( $phone, Config::limit_24h(), Config::limit_week() ) ) {
			if ( ! ( Config::fallback_email() && self::deliver_email( $row, $order, $variant, $step_num, true ) ) ) {
				RecoveryResultHandler::advance_step( $id, $step_num, 'whatsapp', false );
			}
			return null;
		}

		$text = Renderer::render( $cfg['text'], self::vars( $row, $order, $variant ) );
		if ( '' === $text ) {
			RecoveryResultHandler::advance_step( $id, $step_num, 'whatsapp', false );
			return null;
		}
		MessageLimiter::hit( $phone );
		return new Message( $norm, $text );
	}

	/**
	 * E-mail used when WhatsApp fails permanently (called by RecoveryResultHandler).
	 *
	 * @param array<string,mixed> $row      Recovery row.
	 * @param int                 $step_num Step.
	 * @param bool                $fallback Whether this is a fallback from WhatsApp.
	 */
	public static function send_email( array $row, int $step_num, bool $fallback ): bool {
		$order    = OrderHooks::load_order( (int) $row['order_id'] );
		$decision = OrderRecovery::decide( $row, null === $order ? null : OrderHooks::facts( $order ), OrderHooks::paid_statuses(), Config::order_cancelled_fallback() );
		if ( null === $order || ! in_array( $decision['action'], array( 'send', 'restore' ), true ) ) {
			return false;
		}
		return self::deliver_email( $row, $order, $decision['case'], $step_num, $fallback );
	}

	/**
	 * Case used to time the next step (cancelled orders keep the pending timing; null = stop).
	 *
	 * @param array<string,mixed> $row Recovery row.
	 */
	public static function current_case( array $row ): ?string {
		$order = OrderHooks::load_order( (int) $row['order_id'] );
		if ( null === $order ) {
			return null;
		}
		$variant = OrderCase::of_status( (string) $order->get_status(), OrderHooks::paid_statuses() );
		if ( OrderCase::CANCELLED === $variant ) {
			return OrderCase::PENDING;
		}
		return OrderCase::is_open( $variant ) ? $variant : null;
	}

	/**
	 * Text of the first step for the order's current case, for the manual WhatsApp actions.
	 *
	 * @param array<string,mixed> $row Recovery row.
	 */
	public static function manual_text( array $row ): ?string {
		$order = OrderHooks::load_order( (int) $row['order_id'] );
		if ( null === $order ) {
			return null;
		}
		$variant = OrderCase::of_status( (string) $order->get_status(), OrderHooks::paid_statuses() );
		$variant = OrderCase::is_open( $variant ) || OrderCase::CANCELLED === $variant ? $variant : OrderCase::PENDING;
		$text    = Renderer::render( OrderRecovery::step_config( $variant, 1 )['text'], self::vars( $row, $order, $variant ) );
		return '' === $text ? null : $text;
	}

	/**
	 * Template variables of an order message.
	 *
	 * @param array<string,mixed> $row   Recovery row.
	 * @param \WC_Order           $order Fresh order.
	 * @param string|null         $variant  Case.
	 * @return array<string,string>
	 */
	public static function vars( array $row, \WC_Order $order, ?string $variant ): array {
		$id    = (int) $row['id'];
		$token = RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		return array(
			'first_name'   => (string) $row['first_name'],
			'order_number' => (string) $order->get_order_number(),
			'order_total'  => OrderHooks::format_price_html( (string) $order->get_total(), (string) $order->get_currency() ),
			'order_items'  => Renderer::format_items( OrderHooks::item_names( $order ), 3 ),
			'pay_url'      => OrderHooks::pay_url( $order, OrderCase::ON_HOLD === $variant ),
			'recovery_url' => RecoveryToken::recovery_url( $id, $token ),
			'optout_url'   => RecoveryToken::optout_url( $id ),
			'store_name'   => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Sends the e-mail of the step and records history, order note and progress.
	 *
	 * @param array<string,mixed> $row      Recovery row.
	 * @param \WC_Order           $order    Fresh order.
	 * @param string|null         $variant     Case.
	 * @param int                 $step_num Step.
	 * @param bool                $fallback Whether this is a fallback from WhatsApp.
	 */
	private static function deliver_email( array $row, \WC_Order $order, ?string $variant, int $step_num, bool $fallback ): bool {
		$id    = (int) $row['id'];
		$email = (string) $row['email'];
		if ( '' === $email || Suppression::is_suppressed( $email, 'email' ) ) {
			if ( ! $fallback ) {
				RecoveryResultHandler::advance_step( $id, $step_num, 'email', false );
			}
			return false;
		}

		$vars   = self::vars( $row, $order, $variant );
		$detail = ( $fallback ? 'fallback_step=' : 'step=' ) . $step_num;
		if ( OrderRecovery::EMAIL_ABANDONED_CART === OrderRecovery::email_kind( $variant ) ) {
			$mailer = new AbandonedCartEmail();
			$sent   = $mailer->trigger_recovery( $row, $vars['recovery_url'], $vars['optout_url'] );
		} else {
			$mailer = new PendingPaymentEmail();
			$sent   = $mailer->trigger_order( $row, $order, $vars['pay_url'], $vars['optout_url'] );
		}

		History::log( $id, $sent ? 'sent' : 'failed', 'email', 'auto', 0, $sent ? 'ok' : 'failed', $detail );
		OrderNote::record( $id, 'email', 'auto', $sent );
		if ( $sent || ! $fallback ) {
			RecoveryResultHandler::advance_step( $id, $step_num, 'email', $sent );
		}
		return $sent;
	}
}
