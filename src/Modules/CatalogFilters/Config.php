<?php
/**
 * Settings accessors for the CatalogFilters module (option motored_catalog_filters, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Strongly typed accessors with safe launch defaults (disabled by default).
 */
final class Config {

	public const OPTION_KEY = 'catalog_filters';

	/**
	 * Ordered list of all 11 supported facet keys.
	 *
	 * @var list<string>
	 */
	public const ALL_FACETS = array(
		TireSpecs::ATTR_POSITION,
		'product_brand',
		TireSpecs::ATTR_WIDTH,
		TireSpecs::ATTR_PROFILE,
		TireSpecs::ATTR_RIM,
		TireSpecs::ATTR_LOAD_INDEX,
		TireSpecs::ATTR_SPEED_RATING,
		TireSpecs::ATTR_CONSTRUCTION,
		TireSpecs::ATTR_TUBE,
		TireSpecs::ATTR_USAGE,
		'oferta',
	);

	/**
	 * The 10 WooCommerce pa_* attribute slugs tracked by filters.
	 *
	 * @var list<string>
	 */
	public const ATTRIBUTE_SLUGS = array(
		TireSpecs::ATTR_POSITION,
		TireSpecs::ATTR_WIDTH,
		TireSpecs::ATTR_PROFILE,
		TireSpecs::ATTR_RIM,
		TireSpecs::ATTR_LOAD_INDEX,
		TireSpecs::ATTR_SPEED_RATING,
		TireSpecs::ATTR_CONSTRUCTION,
		TireSpecs::ATTR_TUBE,
		TireSpecs::ATTR_USAGE,
	);

	/**
	 * Unit-tire-only attribute slugs (kits with Posición = Kit never enter these facets).
	 *
	 * @var list<string>
	 */
	public const UNIT_ONLY_ATTRIBUTES = array(
		TireSpecs::ATTR_WIDTH,
		TireSpecs::ATTR_PROFILE,
		TireSpecs::ATTR_RIM,
		TireSpecs::ATTR_LOAD_INDEX,
		TireSpecs::ATTR_SPEED_RATING,
	);

	/**
	 * Option defaults (disabled at launch; only in-stock and noindex enabled by default).
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'enabled'          => 'no',
		'only_in_stock'    => 'yes',
		'noindex_filtered' => 'yes',
		'accent_color'     => '',
		'default_layout'   => 'tabs',
		'shop_url'         => '',
	);

	/**
	 * Whether the CatalogFilters module is enabled on the storefront.
	 */
	public static function enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'enabled', 'no' );
	}

	/**
	 * Whether the search cascade and filters restrict to in-stock products only.
	 * Also respects WooCommerce's own "Hide out of stock items from the catalog" option.
	 */
	public static function only_in_stock(): bool {
		if ( 'yes' === (string) get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) ) {
			return true;
		}
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'only_in_stock', 'yes' );
	}

	/**
	 * Whether filtered catalog URLs emit `noindex, follow` via wp_robots.
	 */
	public static function noindex_filtered(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'noindex_filtered', 'yes' );
	}

	/**
	 * Optional sanitized hex accent color feeding CSS variable --motored-accent (or '' for theme default).
	 */
	public static function accent_color(): string {
		$raw = trim( (string) Options::get( self::OPTION_KEY, 'accent_color', '' ) );
		if ( 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw ) ) {
			return strtolower( $raw );
		}
		return '';
	}

	/**
	 * Default layout for [motored_buscador] when attribute is omitted ('tabs'|'split').
	 */
	public static function default_layout(): string {
		return 'split' === (string) Options::get( self::OPTION_KEY, 'default_layout', 'tabs' ) ? 'split' : 'tabs';
	}

	/**
	 * Base catalog URL for search redirects (own setting -> Compatibility shop URL -> WC shop page -> home).
	 */
	public static function shop_url(): string {
		$custom = trim( (string) Options::get( self::OPTION_KEY, 'shop_url', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$compat_url = \Motored\Core\Modules\Compatibility\Config::shop_url();
		if ( '' !== $compat_url ) {
			return $compat_url;
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$wc_shop = (string) wc_get_page_permalink( 'shop' );
			if ( '' !== $wc_shop ) {
				return $wc_shop;
			}
		}
		return home_url( '/' );
	}
}
