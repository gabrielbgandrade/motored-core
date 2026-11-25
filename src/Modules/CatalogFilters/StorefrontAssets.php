<?php
/**
 * Lazy storefront asset loader for CatalogFilters and Compatibility (0 assets on pages without our markup).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Support\StorefrontAssets as SharedAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues theme-agnostic CSS and vanilla defer JS only when a shortcode, widget, product banner or tab renders.
 *
 * Two stylesheets: `compat.css` (banner, badges, selector, bar, tab, Mi Garage — small, needed on product pages)
 * and `catalog-filters.css` (home search + faceted sidebar).
 */
final class StorefrontAssets {

	public const HANDLE_CSS     = 'motored-catalog-filters';
	public const HANDLE_SEARCH  = 'motored-catalog-search';
	public const HANDLE_FILTERS = 'motored-catalog-filters-js';

	/**
	 * Per-request guards keyed by handle.
	 *
	 * @var array<string,bool>
	 */
	private static array $done = array();

	/**
	 * Preloads styles before head printing for explicit shortcodes and configured catalog widgets.
	 * Unrelated pages do not read module options or enqueue assets.
	 */
	public static function preload(): void {
		global $post;
		$shortcode = $post instanceof \WP_Post && ( has_shortcode( $post->post_content, SearchShortcode::SHORTCODE ) || has_shortcode( $post->post_content, FilterRenderer::SHORTCODE ) );
		$catalog   = function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
		$widget    = $catalog && is_active_widget( false, false, 'motored_catalog_filters', true );
		if ( ( $shortcode || $widget ) && Config::enabled() ) {
			self::enqueue_css();
		}
	}

	/**
	 * Enqueues the search/filters stylesheet (plus the shared compat stylesheet it builds on).
	 */
	public static function enqueue_css(): void {
		SharedAssets::enqueue_compat_css();
		if ( self::once( self::HANDLE_CSS ) ) {
			wp_enqueue_style(
				self::HANDLE_CSS,
				MOTORED_CORE_URL . 'assets/css/catalog-filters.css',
				array( SharedAssets::HANDLE_COMPAT_CSS ),
				MOTORED_CORE_VERSION
			);
		}
	}

	/**
	 * Enqueues home search JS + localized config.
	 */
	public static function enqueue_search_js(): void {
		self::enqueue_css();
		SharedAssets::enqueue_ui_js();
		if ( ! self::once( self::HANDLE_SEARCH ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE_SEARCH,
			MOTORED_CORE_URL . 'assets/js/catalog-search.js',
			array( SharedAssets::HANDLE_UI ),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$cfg = array(
			'restBase'      => untrailingslashit( rest_url( RestController::NAMESPACE ) ),
			'sizesUrl'      => rest_url( RestController::NAMESPACE . '/filters/sizes' ),
			'suggestUrl'    => rest_url( RestController::NAMESPACE . '/filters/suggest' ),
			'shopUrl'       => Config::shop_url(),
			'compatEnabled' => \Motored\Core\Modules\Compatibility\Config::enabled(),
			'events'        => \Motored\Core\Modules\Compatibility\Config::analytics_events(),
			'i18n'          => array(
				'widthPlaceholder'   => __( 'Width', 'motored-core' ),
				'profilePlaceholder' => __( 'Aspect ratio', 'motored-core' ),
				'noProfile'          => __( '— no profile', 'motored-core' ),
				'rimPlaceholder'     => __( 'Rim', 'motored-core' ),
				'brandPlaceholder'   => __( 'Select brand...', 'motored-core' ),
				'modelPlaceholder'   => __( 'Select model...', 'motored-core' ),
				'yearPlaceholder'    => __( 'Select year...', 'motored-core' ),
				'varPlaceholder'     => __( 'Select version...', 'motored-core' ),
				'filterList'         => __( 'Type to filter...', 'motored-core' ),
				'loading'            => __( 'Loading...', 'motored-core' ),
				'noModels'           => __( 'No models available', 'motored-core' ),
				'noSizes'            => __( 'No tire sizes available yet.', 'motored-core' ),
				'loadSelectErr'      => __( 'Could not load options. Please try again.', 'motored-core' ),
				/* translators: %d: number of suggestions. */
				'suggestCount'       => __( '%d suggestions available. Use the up and down arrows to browse.', 'motored-core' ),
			),
		);
		wp_add_inline_script(
			self::HANDLE_SEARCH,
			'window.motoredFiltersCfg=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}

	/**
	 * Enqueues faceted sidebar JS (+ its i18n).
	 */
	public static function enqueue_filters_js(): void {
		self::enqueue_css();
		if ( ! self::once( self::HANDLE_FILTERS ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE_FILTERS,
			MOTORED_CORE_URL . 'assets/js/catalog-filters.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$cfg = array(
			'events' => \Motored\Core\Modules\Compatibility\Config::analytics_events(),
			'i18n'   => array(
				'apply'      => __( 'Apply filters', 'motored-core' ),
				/* translators: %d: number of pending filter changes. */
				'applyCount' => __( 'Apply filters (%d)', 'motored-core' ),
			),
		);
		wp_add_inline_script(
			self::HANDLE_FILTERS,
			'window.motoredFacetCfg=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}

	/**
	 * Resets per-request guards (unit tests).
	 */
	public static function reset(): void {
		self::$done = array();
	}

	/**
	 * True the first time a handle is requested in this request.
	 *
	 * @param string $handle Asset handle.
	 */
	private static function once( string $handle ): bool {
		if ( isset( self::$done[ $handle ] ) ) {
			return false;
		}
		self::$done[ $handle ] = true;
		return true;
	}
}
