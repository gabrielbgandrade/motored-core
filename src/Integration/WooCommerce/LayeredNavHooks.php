<?php
/**
 * WooCommerce native layered navigation widget integration (tracked in docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Unregisters WooCommerce native layered nav widgets when CatalogFilters replaces them as the single facet system.
 */
final class LayeredNavHooks {

	/**
	 * Unregisters WooCommerce native layered navigation widgets after WooCommerce registers them.
	 */
	public static function unregister_native_widgets(): void {
		if ( ! function_exists( 'unregister_widget' ) ) {
			return;
		}
		unregister_widget( 'WC_Widget_Layered_Nav' );
		unregister_widget( 'WC_Widget_Layered_Nav_Filters' );
	}

	/**
	 * Whether a widget object is one of WooCommerce's native layered navigation widgets.
	 *
	 * @param mixed $widget WP_Widget object.
	 */
	public static function is_native_layered_nav( $widget ): bool {
		return is_object( $widget ) && in_array( get_class( $widget ), array( 'WC_Widget_Layered_Nav', 'WC_Widget_Layered_Nav_Filters' ), true );
	}

	/**
	 * Checks whether either native layered nav widget is active in any widget area.
	 */
	public static function has_active_native_layered_nav_widget(): bool {
		if ( ! function_exists( 'is_active_widget' ) ) {
			return false;
		}
		return false !== is_active_widget( false, false, 'woocommerce_layered_nav', true )
			|| false !== is_active_widget( false, false, 'woocommerce_layered_nav_filters', true );
	}
}
