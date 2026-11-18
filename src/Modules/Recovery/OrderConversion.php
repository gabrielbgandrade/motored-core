<?php
/**
 * Turns the captured cart into the order row when an order is created.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Integration\WooCommerce\OrderHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the classic checkout hook and the Store API one (the order is still pending here, so the cart row
 * becomes the type=order row; payment is resolved by OrderSync on the status change). Idempotent.
 */
final class OrderConversion {

	/**
	 * Registers both hooks.
	 */
	public function register(): void {
		CartHooks::register_order_created( array( $this, 'on_classic' ), array( $this, 'on_store_api' ) );
	}

	/**
	 * Classic shortcode checkout.
	 *
	 * @param mixed $order_id    Order id.
	 * @param mixed $posted_data Posted checkout data (unused).
	 * @param mixed $order       Order passed by WooCommerce.
	 */
	public function on_classic( mixed $order_id = 0, mixed $posted_data = null, mixed $order = null ): void {
		unset( $posted_data );
		$order = $order instanceof \WC_Order ? $order : OrderHooks::load_order( is_numeric( $order_id ) ? (int) $order_id : 0 );
		if ( null !== $order ) {
			OrderSync::sync_order( $order );
		}
	}

	/**
	 * Store API (Blocks) checkout.
	 *
	 * @param mixed $order Order.
	 */
	public function on_store_api( $order = null ): void {
		if ( $order instanceof \WC_Order ) {
			OrderSync::sync_order( $order );
		}
	}
}
