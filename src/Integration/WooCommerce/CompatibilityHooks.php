<?php
/**
 * WooCommerce storefront, query and product-tab hooks used by Compatibility (tracked in docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Isolates raw WooCommerce and Block query hook names away from the Compatibility module domain code.
 */
final class CompatibilityHooks {

	/**
	 * Registers product catalog filtering (classic shop query and block Product Collection / Query Loop).
	 *
	 * @param callable $classic_cb Filter callback for woocommerce_product_query( WP_Query ).
	 * @param callable $block_cb   Filter callback for query_loop_block_query_vars( array, WP_Block ).
	 * @param callable $nav_cb     Filter callback for woocommerce_layered_nav_link( string ).
	 */
	public static function register_query_filters( callable $classic_cb, callable $block_cb, callable $nav_cb ): void {
		add_action( 'woocommerce_product_query', $classic_cb, 20 );
		add_filter( 'query_loop_block_query_vars', $block_cb, 20, 2 );
		add_filter( 'woocommerce_layered_nav_link', $nav_cb, 20 );
	}

	/**
	 * Registers loop/single storefront output hooks and batch post priming.
	 *
	 * @param callable $prime_cb      Filter callback on the_posts( array, WP_Query ).
	 * @param callable $shop_bar_cb   Action callback on woocommerce_before_shop_loop.
	 * @param callable $loop_item_cb  Action callback on woocommerce_after_shop_loop_item_title.
	 * @param callable $single_cb     Action callback on woocommerce_single_product_summary.
	 */
	public static function register_display_hooks( callable $prime_cb, callable $shop_bar_cb, callable $loop_item_cb, callable $single_cb ): void {
		add_filter( 'the_posts', $prime_cb, 20, 2 );
		add_action( 'woocommerce_before_shop_loop', $shop_bar_cb, 15 );
		add_action( 'woocommerce_after_shop_loop_item_title', $loop_item_cb, 12 );
		add_action( 'woocommerce_single_product_summary', $single_cb, 12 );
	}

	/**
	 * Registers single product tab hooks (Technical information rename + Full size row + Compatibility tab).
	 *
	 * @param callable $tabs_cb       Filter callback on woocommerce_product_tabs( array ).
	 * @param callable $heading_cb    Filter callback on woocommerce_product_additional_information_heading( string ).
	 * @param callable $attrs_cb      Filter callback on woocommerce_display_product_attributes( array, WC_Product ).
	 */
	public static function register_tab_hooks( callable $tabs_cb, callable $heading_cb, callable $attrs_cb ): void {
		add_filter( 'woocommerce_product_tabs', $tabs_cb, 20 );
		add_filter( 'woocommerce_product_additional_information_heading', $heading_cb, 20 );
		add_filter( 'woocommerce_display_product_attributes', $attrs_cb, 20, 2 );
	}

	/**
	 * Resolves current global product instance inside loop or single template.
	 */
	public static function current_product(): ?\WC_Product {
		$product = $GLOBALS['product'] ?? null;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}
		if ( function_exists( 'get_the_ID' ) && function_exists( 'wc_get_product' ) ) {
			$id    = (int) get_the_ID();
			$found = $id > 0 ? wc_get_product( $id ) : null;
			return $found instanceof \WC_Product ? $found : null;
		}
		return null;
	}
}
