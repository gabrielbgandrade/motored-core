<?php
/**
 * Compatibility module configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors over the motored_compatibility option (autoload = false).
 */
final class Config {

	public const OPTION_KEY = 'compatibility';

	/**
	 * Default option values.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'enabled'                 => 'no',
		'max_garage_bikes'        => '5',
		'strict_specs'            => 'no',
		'show_incompatible_badge' => 'no',
		'rename_tech_tab'         => 'yes',
		'show_compat_tab'         => 'yes',
		'auto_fill_dimensions'    => 'no',
		'disclaimer'              => '',
		'shop_url'                => '',
	);

	/**
	 * Whether the storefront compatibility features are active.
	 */
	public static function enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'enabled', 'no' );
	}

	/**
	 * Maximum motorcycles allowed per customer in Mi Garage.
	 */
	public static function max_garage_bikes(): int {
		$val = (int) Options::get( self::OPTION_KEY, 'max_garage_bikes', '5' );
		return max( 1, min( 20, $val ) );
	}

	/**
	 * When true, missing tire load/speed attributes fail strict version minimum requirements.
	 */
	public static function strict_specs(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'strict_specs', 'no' );
	}

	/**
	 * Whether single product page may show an incompatible notice when a bike is active.
	 */
	public static function show_incompatible_badge(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'show_incompatible_badge', 'no' );
	}

	/**
	 * Whether WooCommerce's Additional Information tab should be renamed to Technical Information.
	 */
	public static function rename_tech_tab(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'rename_tech_tab', 'yes' );
	}

	/**
	 * Whether products with valid tire sizes display the Compatibility tab.
	 */
	public static function show_compat_tab(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'show_compat_tab', 'yes' );
	}

	/**
	 * Optional Phase 7 helper: auto-fill empty Ancho/Perfil/Rodado on single tires when saving.
	 */
	public static function auto_fill_dimensions(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'auto_fill_dimensions', 'no' );
	}

	/**
	 * Legal verification disclaimer shown on storefront selectors and compatibility tabs.
	 */
	public static function disclaimer(): string {
		$custom = trim( (string) Options::get( self::OPTION_KEY, 'disclaimer', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return __( "Always verify the size, load index and speed rating in your motorcycle's manual.", 'motored-core' );
	}

	/**
	 * Base URL used by the selector when redirecting to filtered catalog results.
	 */
	public static function shop_url(): string {
		$custom = trim( (string) Options::get( self::OPTION_KEY, 'shop_url', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}
}
