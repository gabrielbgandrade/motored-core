<?php
/**
 * Lazy storefront asset loader for CatalogFilters (0 assets on pages without search/filters/compat list).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues theme-agnostic CSS and vanilla defer JS only when a shortcode, widget or product tab renders.
 */
final class StorefrontAssets {

	public const HANDLE_CSS     = 'motored-catalog-filters';
	public const HANDLE_SEARCH  = 'motored-catalog-search';
	public const HANDLE_FILTERS = 'motored-catalog-filters-js';

	/**
	 * Per-request guard for shared stylesheet.
	 *
	 * @var bool
	 */
	private static bool $css_enqueued = false;

	/**
	 * Per-request guard for search script.
	 *
	 * @var bool
	 */
	private static bool $search_enqueued = false;

	/**
	 * Per-request guard for sidebar filters script.
	 *
	 * @var bool
	 */
	private static bool $filters_enqueued = false;

	/**
	 * Enqueues the shared theme-agnostic stylesheet once per request.
	 */
	public static function enqueue_css(): void {
		if ( self::$css_enqueued ) {
			return;
		}
		self::$css_enqueued = true;
		wp_enqueue_style(
			self::HANDLE_CSS,
			MOTORED_CORE_URL . 'assets/css/catalog-filters.css',
			array(),
			MOTORED_CORE_VERSION
		);
		$accent = Config::accent_color();
		if ( '' !== $accent ) {
			wp_add_inline_style( self::HANDLE_CSS, ':root{--motored-accent:' . $accent . ';}' );
		}
	}

	/**
	 * Enqueues catalog-search.js (defer, vanilla JS) and its localized config once per request.
	 */
	public static function enqueue_search_js(): void {
		self::enqueue_css();
		if ( self::$search_enqueued ) {
			return;
		}
		self::$search_enqueued = true;
		wp_enqueue_script(
			self::HANDLE_SEARCH,
			MOTORED_CORE_URL . 'assets/js/catalog-search.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$cfg = array(
			'restBase'      => untrailingslashit( rest_url( RestController::NAMESPACE ) ),
			'sizesVer'      => SnapshotInvalidator::version(),
			'shopUrl'       => Config::shop_url(),
			'compatEnabled' => \Motored\Core\Modules\Compatibility\Config::enabled(),
			'i18n'          => array(
				'widthPlaceholder'   => __( 'Width', 'motored-core' ),
				'profilePlaceholder' => __( 'Aspect ratio', 'motored-core' ),
				'noProfile'          => __( '— no profile', 'motored-core' ),
				'rimPlaceholder'     => __( 'Rim', 'motored-core' ),
				'brandPlaceholder'   => __( 'Select brand...', 'motored-core' ),
				'modelPlaceholder'   => __( 'Select model...', 'motored-core' ),
				'yearPlaceholder'    => __( 'Select year...', 'motored-core' ),
				'varPlaceholder'     => __( 'Select version...', 'motored-core' ),
				'noSuggestions'      => __( 'No direct matches — press Enter to search the store.', 'motored-core' ),
			),
		);
		wp_add_inline_script(
			self::HANDLE_SEARCH,
			'window.motoredFiltersCfg=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}

	/**
	 * Enqueues catalog-filters.js (defer, vanilla JS) once per request.
	 */
	public static function enqueue_filters_js(): void {
		self::enqueue_css();
		if ( self::$filters_enqueued ) {
			return;
		}
		self::$filters_enqueued = true;
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
	}

	/**
	 * Resets per-request guards (for unit tests).
	 */
	public static function reset(): void {
		self::$css_enqueued     = false;
		self::$search_enqueued  = false;
		self::$filters_enqueued = false;
	}
}
