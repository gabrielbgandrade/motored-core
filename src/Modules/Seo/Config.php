<?php
/**
 * Settings accessors for the Seo module (option motored_seo, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Strongly typed accessors for site-wide Motored SEO behavior.
 */
final class Config {

	public const OPTION_KEY = 'seo';

	/**
	 * Allowed title separators.
	 *
	 * @var list<string>
	 */
	public const SEPARATORS = array( '|', '–', '-', '·' );

	/**
	 * Option defaults.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'enabled'                   => 'yes',
		'site_wide'                 => 'yes',
		'title_separator'           => '|',
		'home_title'                => '',
		'home_description'          => '',
		'noindex_paged'             => 'no',
		'noindex_search'            => 'yes',
		'og_enabled'                => 'yes',
		'schema_enabled'            => 'yes',
		'enhance_wc_product_schema' => 'yes',
		'default_og_image'          => '',
		'org_name'                  => '',
		'org_logo'                  => '',
		'org_phone'                 => '',
		'org_city'                  => '',
		'org_region'                => '',
		'org_country'               => 'AR',
		'social_profiles'           => '',
		'robots_txt_enabled'        => 'yes',
		'disable_user_sitemap'      => 'yes',
		'auto_product_redirects'    => 'yes',
		'merchant_feed_enabled'     => 'yes',
		'merchant_google_category'  => '913',
		'return_days'               => '10',
		'shipping_cost'             => '',
		'shipping_handling_days'    => '1',
		'shipping_transit_days'     => '5',
	);

	/**
	 * Whether the Seo module is enabled.
	 */
	public static function enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'enabled', 'yes' );
	}

	/**
	 * Whether full-site SEO (home, products, categories, brands, pages, blog) is enabled.
	 */
	public static function site_wide(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'site_wide', 'yes' );
	}

	/**
	 * Document title separator.
	 */
	public static function title_separator(): string {
		$sep = trim( (string) Options::get( self::OPTION_KEY, 'title_separator', '|' ) );
		return in_array( $sep, self::SEPARATORS, true ) ? $sep : '|';
	}

	/**
	 * Custom home page SEO title (empty = automatic).
	 */
	public static function home_title(): string {
		return trim( (string) Options::get( self::OPTION_KEY, 'home_title', '' ) );
	}

	/**
	 * Custom home page meta description (empty = site tagline).
	 */
	public static function home_description(): string {
		return trim( (string) Options::get( self::OPTION_KEY, 'home_description', '' ) );
	}

	/**
	 * Whether paginated archive pages (?paged=2+ / /page/2/) emit noindex, follow.
	 */
	public static function noindex_paged(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'noindex_paged', 'no' );
	}

	/**
	 * Whether WordPress search result pages emit noindex, follow.
	 */
	public static function noindex_search(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'noindex_search', 'yes' );
	}

	/**
	 * Whether Open Graph and Twitter meta tags are emitted on managed pages.
	 */
	public static function og_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'og_enabled', 'yes' );
	}

	/**
	 * Whether JSON-LD structured data is emitted on managed pages.
	 */
	public static function schema_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'schema_enabled', 'yes' );
	}

	/**
	 * Whether WooCommerce's native Product JSON-LD is enriched with brand, condition and tire specs.
	 */
	public static function enhance_wc_product_schema(): bool {
		return self::schema_enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'enhance_wc_product_schema', 'yes' );
	}

	/**
	 * Fallback Open Graph image URL when a page does not supply its own image.
	 */
	public static function default_og_image(): string {
		return esc_url_raw( trim( (string) Options::get( self::OPTION_KEY, 'default_og_image', '' ) ) );
	}

	/**
	 * Business / organization display name for JSON-LD.
	 */
	public static function org_name(): string {
		$name = trim( (string) Options::get( self::OPTION_KEY, 'org_name', '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		return function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
	}

	/**
	 * Organization logo URL for JSON-LD (falls back to site custom logo).
	 */
	public static function org_logo(): string {
		$url = esc_url_raw( trim( (string) Options::get( self::OPTION_KEY, 'org_logo', '' ) ) );
		if ( '' !== $url ) {
			return $url;
		}
		if ( function_exists( 'get_theme_mod' ) && function_exists( 'wp_get_attachment_image_url' ) ) {
			$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
			if ( $logo_id > 0 ) {
				$img = wp_get_attachment_image_url( $logo_id, 'full' );
				if ( is_string( $img ) && '' !== $img ) {
					return $img;
				}
			}
		}
		return '';
	}

	/**
	 * Contact phone number for Organization / AutoPartsStore JSON-LD.
	 */
	public static function org_phone(): string {
		return trim( (string) Options::get( self::OPTION_KEY, 'org_phone', '' ) );
	}

	/**
	 * Business city for PostalAddress JSON-LD.
	 */
	public static function org_city(): string {
		return trim( (string) Options::get( self::OPTION_KEY, 'org_city', '' ) );
	}

	/**
	 * Business state/province for PostalAddress JSON-LD.
	 */
	public static function org_region(): string {
		return trim( (string) Options::get( self::OPTION_KEY, 'org_region', '' ) );
	}

	/**
	 * Business ISO-2 country code (default AR).
	 */
	public static function org_country(): string {
		$country = strtoupper( trim( (string) Options::get( self::OPTION_KEY, 'org_country', 'AR' ) ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'AR';
	}

	/**
	 * Validated social profile URLs for Organization `sameAs`.
	 *
	 * @return list<string>
	 */
	public static function social_profiles(): array {
		$raw = (string) Options::get( self::OPTION_KEY, 'social_profiles', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$urls = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) ?: array() as $line ) {
			$clean = esc_url_raw( trim( $line ) );
			if ( '' !== $clean && preg_match( '#^https?://#i', $clean ) ) {
				$urls[ $clean ] = $clean;
			}
		}
		return array_values( $urls );
	}

	/**
	 * Whether virtual robots.txt crawl-budget rules and sitemap declaration are appended.
	 */
	public static function robots_txt_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'robots_txt_enabled', 'yes' );
	}

	/**
	 * Whether the WordPress users XML sitemap provider is removed (prevents username leak and bloat).
	 */
	public static function disable_user_sitemap(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'disable_user_sitemap', 'yes' );
	}

	/**
	 * Whether changing the slug of a published product/page/post automatically records a 301 redirect.
	 */
	public static function auto_product_redirects(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'auto_product_redirects', 'yes' );
	}

	/**
	 * Whether the native Google Merchant Center XML feed (`/feed/motored-merchant/`) is enabled.
	 */
	public static function merchant_feed_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'merchant_feed_enabled', 'yes' );
	}

	/**
	 * Google Product Taxonomy ID or path for the Merchant feed (default 913 = Motor Vehicle Tires).
	 */
	public static function merchant_google_category(): string {
		$val = trim( (string) Options::get( self::OPTION_KEY, 'merchant_google_category', '913' ) );
		return '' !== $val ? $val : '913';
	}

	/**
	 * Return window in days for `hasMerchantReturnPolicy` (default 10 for Argentina; 0 disables).
	 */
	public static function return_days(): int {
		$raw = Options::get( self::OPTION_KEY, 'return_days', '10' );
		$num = is_scalar( $raw ) ? (int) $raw : 10;
		return max( 0, min( 365, $num ) );
	}

	/**
	 * Flat/free shipping cost in store currency for `shippingDetails` and Merchant feed ('' = omit, '0' = free).
	 */
	public static function shipping_cost(): string {
		$raw = trim( str_replace( ',', '.', (string) Options::get( self::OPTION_KEY, 'shipping_cost', '' ) ) );
		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return '';
		}
		return (string) (float) $raw;
	}

	/**
	 * Max handling business days before shipment (`shippingDetails.deliveryTime`).
	 */
	public static function shipping_handling_days(): int {
		$raw = Options::get( self::OPTION_KEY, 'shipping_handling_days', '1' );
		return max( 0, min( 30, is_scalar( $raw ) ? (int) $raw : 1 ) );
	}

	/**
	 * Max transit business days for delivery (`shippingDetails.deliveryTime`).
	 */
	public static function shipping_transit_days(): int {
		$raw = Options::get( self::OPTION_KEY, 'shipping_transit_days', '5' );
		return max( 1, min( 60, is_scalar( $raw ) ? (int) $raw : 5 ) );
	}
}
