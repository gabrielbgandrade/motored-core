<?php
/**
 * Schedules the review request when an order enters the trigger status.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Modules\Recovery\Config as RecoveryConfig;
use Motored\Core\Modules\Waha\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Listener of woocommerce_order_status_changed. Cost on a matching change: one meta check and one
 * Action Scheduler insert (the Sender job, args = ids only) plus one meta write; no HTTP.
 */
final class Scheduler {

	public const KIND = 'review_request';
	public const META = '_motored_review_req';

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $enqueuer fn( int $order_id, int $delay ): bool (tests).
	 * @param \Closure|null $clock    Returns unix time (tests).
	 */
	public function __construct( private ?\Closure $enqueuer = null, private ?\Closure $clock = null ) {
	}

	/**
	 * Listener.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $from     Previous status.
	 * @param mixed $to       New status.
	 * @param mixed $order    WC_Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		unset( $from );
		if ( ! $order instanceof \WC_Order || ! is_string( $to ) || Config::trigger_status() !== $to || ! Config::enabled() ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META, true ) ) {
			return; // One request per order, ever.
		}

		$now   = null !== $this->clock ? ( $this->clock )() : time();
		$delay = ReviewRules::delay_seconds( $now, Config::delay_days(), RecoveryConfig::window_start(), RecoveryConfig::window_end() );
		$queue = $this->enqueuer ?? static fn( int $id, int $seconds ): bool => Sender::enqueue( self::KIND, $id, null, $seconds, Sender::LOW );
		if ( ! $queue( (int) $order_id, $delay ) ) {
			return;
		}
		$order->update_meta_data( self::META, (string) ( $now + $delay ) );
		$order->save_meta_data();
	}
}
