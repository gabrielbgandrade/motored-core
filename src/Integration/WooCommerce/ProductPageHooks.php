<?php
/**
 * WooCommerce single product page hooks (isolated: names are NOT in the stubs, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the classic single product template.
 */
final class ProductPageHooks {

	/**
	 * Hooks an action printed right below the native product gallery inside the gallery column
	 * (classic template: sale flash is priority 10, product images are priority 20).
	 *
	 * @param callable $callback fn(): void.
	 */
	public static function register_after_gallery( callable $callback ): void {
		add_action( 'woocommerce_before_single_product_summary', $callback, 30 );
	}

	/**
	 * Hooks an action printed at the very top of the product summary, before the title (priority 3; the
	 * classic title is priority 5).
	 *
	 * @param callable $callback fn(): void.
	 */
	public static function register_summary_top( callable $callback ): void {
		add_action( 'woocommerce_single_product_summary', $callback, 3 );
	}

	/**
	 * Hooks an action printed right below the Add to Cart button inside the product summary
	 * (classic template: add-to-cart is priority 30, meta/SKU is priority 40). Also runs on out-of-stock products.
	 *
	 * @param callable $callback fn(): void.
	 */
	public static function register_after_add_to_cart( callable $callback ): void {
		add_action( 'woocommerce_single_product_summary', $callback, 35 );
	}

	/**
	 * Hooks an action printed right below the product gallery + summary and above the tabs (priority 8).
	 *
	 * @param callable $callback fn(): void.
	 */
	public static function register_before_tabs( callable $callback ): void {
		add_action( 'woocommerce_after_single_product_summary', $callback, 8 );
	}

	/**
	 * Hooks an action printed right below the product tabs (classic template: tabs are priority 10,
	 * upsells 15, related products 20).
	 *
	 * @param callable $callback fn(): void.
	 */
	public static function register_after_summary( callable $callback ): void {
		add_action( 'woocommerce_after_single_product_summary', $callback, 11 );
	}

	/**
	 * Hooks a WooCommerce product tab filter so the gallery can render as a native tab on any theme with tabs.
	 *
	 * @param callable $callback fn( array $tabs ): array.
	 */
	public static function register_product_tab( callable $callback ): void {
		add_filter( 'woocommerce_product_tabs', $callback, 35 );
	}

	/**
	 * Block themes: the single-product template renders tabs through the `woocommerce/product-details` block and
	 * never fires woocommerce_after_single_product_summary. Filtering that block's HTML places the gallery
	 * above/below the tabs there too (unverified block name: docs/VERIFY.md #122).
	 *
	 * @param callable $callback fn( string $content ): string.
	 */
	public static function register_product_details_block( callable $callback ): void {
		add_filter( 'render_block_woocommerce/product-details', $callback, 10, 1 );
	}

	/**
	 * Block themes: appends the gallery right below the Product Image Gallery block when placement is `below_gallery`.
	 *
	 * @param callable $callback fn( string $content ): string.
	 */
	public static function register_product_gallery_block( callable $callback ): void {
		add_filter( 'render_block_woocommerce/product-image-gallery', $callback, 10, 1 );
	}

	/**
	 * Block themes: appends the gallery right below the Add to Cart Form block when placement is `after_add_to_cart`.
	 *
	 * @param callable $callback fn( string $content ): string.
	 */
	public static function register_add_to_cart_block( callable $callback ): void {
		add_filter( 'render_block_woocommerce/add-to-cart-form', $callback, 10, 1 );
	}
}
