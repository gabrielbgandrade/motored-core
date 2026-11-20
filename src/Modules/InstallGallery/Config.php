<?php
/**
 * InstallGallery configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_install_gallery` (autoload=false).
 */
final class Config {

	public const OPTION_KEY        = 'install_gallery';
	public const PLACE_BEFORE_TABS = 'before_tabs';
	public const PLACE_AFTER_TABS  = 'after_tabs';
	public const PLACE_TAB         = 'tab';
	public const PLACE_SHORTCODE   = 'shortcode';

	private const PLACEMENTS = array(
		self::PLACE_BEFORE_TABS,
		self::PLACE_AFTER_TABS,
		self::PLACE_TAB,
		self::PLACE_SHORTCODE,
	);

	public const DEFAULTS = array(
		'enabled'         => 'yes',
		'include_reviews' => 'yes',
		'visible'         => '8',
		'placement'       => self::PLACE_BEFORE_TABS,
	);

	/**
	 * Whether the gallery is shown on product pages (nothing shows without photos either way).
	 */
	public static function enabled(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'enabled' );
	}

	/**
	 * Whether photos of approved reviews join the installation photos.
	 */
	public static function include_reviews(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'include_reviews' );
	}

	/**
	 * Thumbnails printed before the "+N" chip (4-12).
	 */
	public static function visible(): int {
		$value = Options::get( self::OPTION_KEY, 'visible', self::DEFAULTS['visible'] );
		$value = is_scalar( $value ) ? (int) $value : 0;
		return $value < 4 ? (int) self::DEFAULTS['visible'] : min( 12, $value );
	}

	/**
	 * Configured display position on the product page.
	 */
	public static function placement(): string {
		$value = Options::get( self::OPTION_KEY, 'placement', self::DEFAULTS['placement'] );
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::PLACEMENTS, true ) ? $value : self::DEFAULTS['placement'];
	}
}
