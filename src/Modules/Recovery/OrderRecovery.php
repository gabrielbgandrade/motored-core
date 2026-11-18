<?php
/**
 * Pure rules of the order recovery sequence.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling, attribution and decision rules with no I/O (unit tested).
 */
final class OrderRecovery {

	public const EMAIL_PENDING_PAYMENT = 'pending_payment';
	public const EMAIL_ABANDONED_CART  = 'abandoned_cart';

	/**
	 * Unix time of the next step for a case, counted from $base_ts (the status event or last activity).
	 *
	 * @param string $variant         Open case (pending, failed, on_hold).
	 * @param int    $current_step Steps already processed.
	 * @param int    $base_ts      Reference time.
	 * @return int|null Null when no enabled step is left.
	 */
	public static function next_action_ts( string $variant, int $current_step, int $base_ts ): ?int {
		$next = Config::order_next_step_num( $variant, $current_step );
		return null === $next ? null : $base_ts + ( Config::order_step( $variant, $next )['delay'] * 60 );
	}

	/**
	 * Row fields when the order is paid. Recovered only when a message was actually delivered
	 * (attribution); otherwise the order simply converted.
	 *
	 * @param array<string,mixed> $row      Recovery row (step, wa_sent_count, email_sent_count).
	 * @param int                 $order_id Paid order.
	 * @param string              $now      GMT datetime.
	 * @return array{recovered:bool,fields:array<string,mixed>}
	 */
	public static function paid_resolution( array $row, int $order_id, string $now ): array {
		$sent      = (int) ( $row['wa_sent_count'] ?? 0 ) + (int) ( $row['email_sent_count'] ?? 0 );
		$recovered = $sent > 0 && (int) ( $row['step'] ?? 0 ) > 0;
		$fields    = array(
			'status'          => $recovered ? Status::RECOVERED : Status::CONVERTED,
			'order_id'        => $order_id,
			'next_action_gmt' => null,
			'last_event_gmt'  => $now,
			'updated_gmt'     => $now,
		);
		if ( $recovered ) {
			$fields['recovered_order_id'] = $order_id;
		}
		return array(
			'recovered' => $recovered,
			'fields'    => $fields,
		);
	}

	/**
	 * Decides what to do at send time from fresh order facts.
	 *
	 * Actions: send, paid, restore (cancelled order, new cart from the recovery link), expire.
	 * WooCommerce's needs_payment() is false for on-hold orders by design, so it only gates pending/failed.
	 *
	 * @param array<string,mixed>      $row      Recovery row.
	 * @param array<string,mixed>|null $facts    OrderHooks::facts() or null when the order is gone.
	 * @param array<int,string>        $paid     Paid statuses.
	 * @param string                   $fallback "restore" or "expire" for cancelled orders.
	 * @return array{action:string,case:?string,reason:string}
	 */
	public static function decide( array $row, ?array $facts, array $paid, string $fallback ): array {
		if ( null === $facts ) {
			return self::outcome( 'expire', null, 'order_missing' );
		}
		if ( 'trash' === strtolower( (string) preg_replace( '/^wc-/i', '', (string) $facts['status'] ) ) ) {
			return self::outcome( 'expire', OrderCase::CANCELLED, 'order_trashed' ); // Removed on purpose: never message.
		}
		$variant = OrderCase::of_status( (string) $facts['status'], $paid );
		if ( OrderCase::PAID === $variant ) {
			return self::outcome( 'paid', $variant, 'paid' );
		}
		if ( OrderCase::CANCELLED === $variant ) {
			$has_items = '' !== trim( (string) ( $row['items'] ?? '' ) );
			return 'restore' === $fallback && $has_items
				? self::outcome( 'restore', $variant, 'cancelled' )
				: self::outcome( 'expire', $variant, 'cancelled' );
		}
		if ( null === $variant ) {
			return self::outcome( 'expire', null, 'status_changed' );
		}
		if ( OrderCase::ON_HOLD !== $variant && true !== ( $facts['needs_payment'] ?? false ) ) {
			return self::outcome( 'expire', $variant, 'no_payment_needed' );
		}
		return self::outcome( 'send', $variant, 'open' );
	}

	/**
	 * Which WooCommerce e-mail carries the step.
	 *
	 * @param string|null $variant Case.
	 */
	public static function email_kind( ?string $variant ): string {
		return OrderCase::CANCELLED === $variant ? self::EMAIL_ABANDONED_CART : self::EMAIL_PENDING_PAYMENT;
	}

	/**
	 * Config of the step to send: cancelled orders reuse the pending channels with their own text.
	 *
	 * @param string|null $variant Case.
	 * @param int         $step Step number.
	 * @return array{channel:string,delay:int,text:string}
	 */
	public static function step_config( ?string $variant, int $step ): array {
		if ( OrderCase::CANCELLED === $variant ) {
			$config         = Config::order_step( OrderCase::PENDING, $step );
			$config['text'] = Config::order_cancelled_text();
			return $config;
		}
		return Config::order_step( OrderCase::is_open( $variant ) ? (string) $variant : OrderCase::PENDING, $step );
	}

	/**
	 * Builds a decision.
	 *
	 * @param string      $action Action.
	 * @param string|null $variant   Case.
	 * @param string      $reason Short reason (history detail).
	 * @return array{action:string,case:?string,reason:string}
	 */
	private static function outcome( string $action, ?string $variant, string $reason ): array {
		return array(
			'action' => $action,
			'case'   => $variant,
			'reason' => $reason,
		);
	}
}
