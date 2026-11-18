<?php
/**
 * Keeps a type=order recovery row in sync with the WooCommerce order status.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on order creation and on every status change, in the customer's request: no HTTP and at most
 * two queries (one lookup, one write) on the normal path. An extra insert happens only when a
 * recovery is attributed. Decisions that need fresh data are taken later, at send time.
 */
final class OrderSync {

	/**
	 * Registers the status listener through the existing order-hooks adapter.
	 */
	public function register(): void {
		OrderHooks::register_status_changed( array( $this, 'on_status_changed' ) );
	}

	/**
	 * Listener for woocommerce_order_status_changed.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order passed by WooCommerce.
	 */
	public function on_status_changed( int $order_id, string $from, string $to, $order = null ): void {
		unset( $from, $to );
		$order = $order instanceof \WC_Order ? $order : OrderHooks::load_order( $order_id );
		if ( null !== $order ) {
			self::sync_order( $order );
		}
	}

	/**
	 * Syncs one order with the current request context.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function sync_order( \WC_Order $order ): void {
		$recovered = self::apply( OrderHooks::facts( $order ), OrderHooks::paid_statuses(), CartHooks::session_key(), time(), get_current_user_id() );
		if ( null !== $recovered ) {
			$order->update_meta_data( '_motored_recovered_cart_id', (string) $recovered );
			$order->save_meta_data();
		}
	}

	/**
	 * Applies the order facts to the recovery table.
	 *
	 * @param array<string,mixed> $o           Facts (see OrderHooks::facts()).
	 * @param array<int,string>   $paid        Paid statuses.
	 * @param string              $session_key Shopper session ('' when unknown).
	 * @param int                 $now         Unix time.
	 * @param int                 $actor_id    Acting user (0 = system/cron/gateway).
	 * @return int|null Row id when this call recorded a recovery (order paid after our messages).
	 */
	public static function apply( array $o, array $paid, string $session_key, int $now, int $actor_id ): ?int {
		$variant  = OrderCase::of_status( (string) $o['status'], $paid );
		$order_id = (int) $o['order_id'];
		if ( null === $variant || $order_id <= 0 ) {
			return null;
		}

		$gmt = gmdate( 'Y-m-d H:i:s', $now );
		$row = Repository::find_for_order( $order_id, $session_key, strtolower( trim( (string) $o['email'] ) ), gmdate( 'Y-m-d H:i:s', $now - 86400 ) );

		if ( OrderCase::PAID === $variant ) {
			return null === $row || Status::ACTIVE !== $row['status'] ? null : self::resolve_paid( $row, $order_id, $gmt );
		}
		if ( OrderCase::CANCELLED === $variant ) {
			// Cancelled by a person: stop. Cancelled by a timeout (no user): the send-time fallback decides.
			if ( null !== $row && 'order' === $row['type'] && Status::ACTIVE === $row['status'] && $actor_id > 0 ) {
				self::expire( $row['id'], 'cancelled_manually' );
			}
			return null;
		}

		if ( ! Config::enabled() || ! Config::order_enabled() ) {
			return null;
		}
		if ( null !== $row && Status::ACTIVE !== $row['status'] ) {
			return null; // Finished, expired or opted out: never restart.
		}

		$cc   = OrderHooks::country_calling_code( (string) $o['country'] );
		$data = array(
			'email'      => CaptureValidator::email( (string) $o['email'] ),
			'phone'      => CaptureValidator::phone( (string) $o['phone'], '' !== $cc ? $cc : WahaConfig::country_code() ),
			'first_name' => CaptureValidator::name( (string) $o['first_name'] ),
		);
		$next = OrderRecovery::next_action_ts( $variant, null === $row ? 0 : (int) $row['step'], $now );
		$base = array(
			'type'              => 'order',
			'order_id'          => $order_id,
			'currency'          => substr( (string) $o['currency'], 0, 10 ),
			'total'             => number_format( (float) $o['total'], 4, '.', '' ),
			'last_activity_gmt' => $gmt,
			'updated_gmt'       => $gmt,
		);
		foreach ( $data as $key => $value ) {
			if ( '' !== $value ) {
				$base[ $key ] = $value;
			}
		}

		if ( null === $row ) {
			if ( null === $next || ( '' === $data['email'] && '' === $data['phone'] ) ) {
				return null; // Nothing to send or nobody to send to.
			}
			$snapshot = Snapshot::from_cart( (array) $o['lines'] );
			Repository::insert(
				array_merge(
					$base,
					array(
						'user_id'         => (int) ( $o['user_id'] ?? 0 ),
						'status'          => Status::ACTIVE,
						'cart_hash'       => $snapshot['hash'],
						'items'           => (string) wp_json_encode( $snapshot['items'] ),
						'next_action_gmt' => gmdate( 'Y-m-d H:i:s', $next ),
						'created_gmt'     => $gmt,
						'last_event_gmt'  => $gmt,
					)
				)
			);
			return null;
		}

		// Existing order row, or the active cart of the same session/e-mail: same row, a single sequence.
		if ( ! $row['has_items'] ) {
			$snapshot          = Snapshot::from_cart( (array) $o['lines'] );
			$base['items']     = (string) wp_json_encode( $snapshot['items'] );
			$base['cart_hash'] = $snapshot['hash'];
		}
		$base['next_action_gmt'] = null === $next ? null : gmdate( 'Y-m-d H:i:s', $next );
		if ( null === $next ) {
			$base['status'] = Status::EXPIRED; // The sequence already used every enabled step.
		}
		Repository::update( $row['id'], $base );
		return null;
	}

	/**
	 * The order was paid: converted, or recovered when our messages preceded the payment. Stops pending sends
	 * (next_action cleared; queued jobs find the row inactive and skip).
	 *
	 * @param array<string,mixed> $row      Row (step, sent counters).
	 * @param int                 $order_id Paid order.
	 * @param string              $gmt      GMT datetime.
	 * @return int|null Row id when recovered.
	 */
	public static function resolve_paid( array $row, int $order_id, string $gmt ): ?int {
		$id         = (int) $row['id'];
		$resolution = OrderRecovery::paid_resolution( $row, $order_id, $gmt );
		Repository::update( $id, $resolution['fields'] );
		if ( ! $resolution['recovered'] ) {
			return null;
		}
		History::log( $id, 'recovered', 'none', 'system', 0, 'ok', 'order_id=' . $order_id, false );
		Cache::delete( 'summary', CaptureLimiter::BUCKET );
		return $id;
	}

	/**
	 * Ends a sequence without recovery.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $reason      Short reason (no PII).
	 */
	public static function expire( int $recovery_id, string $reason ): void {
		Repository::update(
			$recovery_id,
			array(
				'status'          => Status::EXPIRED,
				'next_action_gmt' => null,
			)
		);
		History::log( $recovery_id, 'expired', 'none', 'system', 0, 'ok', $reason );
	}
}
