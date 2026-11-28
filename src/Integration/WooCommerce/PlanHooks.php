<?php
/**
 * WooCommerce hook names used by PaymentPlans (unverified in themes/blocks, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that knows the hook names (core WooCommerce only, no theme hooks).
 */
final class PlanHooks {

	/**
	 * Single product summary, right after the price (priority 10).
	 *
	 * @param callable $callback Prints the plans line.
	 */
	public static function register_single( callable $callback ): void {
		add_action( 'woocommerce_single_product_summary', $callback, 11 );
	}

	/**
	 * Shop / category loop, right after the price (priority 10).
	 *
	 * @param callable $callback Prints the plans line.
	 */
	public static function register_loop( callable $callback ): void {
		add_action( 'woocommerce_after_shop_loop_item_title', $callback, 11 );
	}

	/**
	 * Variation data sent to the browser.
	 *
	 * @param callable $callback fn( array $data, WC_Product $product, WC_Product_Variation $variation ): array.
	 */
	public static function register_variation( callable $callback ): void {
		add_filter( 'woocommerce_available_variation', $callback, 10, 3 );
	}

	/**
	 * Classic cart: the plans block above "Proceed to checkout" (priority 5; the button is 20).
	 *
	 * @param callable $callback Prints the block.
	 */
	public static function register_cart( callable $callback ): void {
		add_action( 'woocommerce_proceed_to_checkout', $callback, 5 );
	}

	/**
	 * Classic checkout: plan selector before the payment methods.
	 *
	 * @param callable $callback Prints the selector.
	 */
	public static function register_selector( callable $callback ): void {
		add_action( 'woocommerce_review_order_before_payment', $callback );
	}

	/**
	 * Classic checkout: fragments refreshed by update_order_review.
	 *
	 * @param callable $callback fn( array $fragments ): array.
	 */
	public static function register_fragments( callable $callback ): void {
		add_filter( 'woocommerce_update_order_review_fragments', $callback );
	}

	/**
	 * Classic checkout: update_order_review (receives the serialized form).
	 *
	 * @param callable $callback fn( string $post_data ): void.
	 */
	public static function register_update_order_review( callable $callback ): void {
		add_action( 'woocommerce_checkout_update_order_review', $callback );
	}

	/**
	 * Classic checkout: place order (posted data is read after WooCommerce verified the nonce).
	 *
	 * @param callable $callback fn( array $data ): array.
	 */
	public static function register_posted_data( callable $callback ): void {
		add_filter( 'woocommerce_checkout_posted_data', $callback );
	}

	/**
	 * Cart fees.
	 *
	 * @param callable $callback fn( WC_Cart $cart ): void.
	 */
	public static function register_fees( callable $callback ): void {
		add_action( 'woocommerce_cart_calculate_fees', $callback );
	}

	/**
	 * Order created from the checkout (before it is saved).
	 *
	 * @param callable $callback fn( WC_Order $order, array $data ): void.
	 */
	public static function register_create_order( callable $callback ): void {
		add_action( 'woocommerce_checkout_create_order', $callback, 10, 2 );
	}
}
