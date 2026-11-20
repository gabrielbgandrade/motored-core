<?php
/**
 * ImageOptimizer configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_image_optimizer` (autoload=false).
 */
final class Config {

	public const OPTION_KEY = 'image_optimizer';

	public const DEFAULTS = array(
		'enabled'            => 'no',
		'max_side'           => '1600',
		'quality'            => '82',
		'convert_webp'       => 'yes',
		'convert_png_webp'   => 'no',
		'strip_exif'         => 'yes',
		'skip_unused_sizes'  => 'no',
		'bulk_batch_size'    => '10',
		'bulk_webp_subsizes' => 'no',
		'bulk_resize'        => 'no',
	);

	/**
	 * Whether new-upload optimization filters are active.
	 */
	public static function enabled(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'enabled' );
	}

	/**
	 * Maximum pixel width/height allowed (800-3000 px).
	 */
	public static function max_side(): int {
		return self::clamp( 'max_side', 800, 3000 );
	}

	/**
	 * Compression quality for JPEG/WebP (60-95).
	 */
	public static function quality(): int {
		return self::clamp( 'quality', 60, 95 );
	}

	/**
	 * Whether new JPEG uploads generate WebP sub-sizes when supported by the server.
	 */
	public static function convert_webp(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'convert_webp' );
	}

	/**
	 * Whether new PNG uploads generate WebP sub-sizes (opt-in for transparency safety).
	 */
	public static function convert_png_webp(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'convert_png_webp' );
	}

	/**
	 * Whether EXIF/IPTC metadata should be stripped.
	 */
	public static function strip_exif(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'strip_exif' );
	}

	/**
	 * Whether WordPress 1536x1536 and 2048x2048 sub-sizes should be skipped on new uploads.
	 */
	public static function skip_unused_sizes(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'skip_unused_sizes' );
	}

	/**
	 * Attachments processed per Action Scheduler batch (5-25).
	 */
	public static function bulk_batch_size(): int {
		return self::clamp( 'bulk_batch_size', 5, 25 );
	}

	/**
	 * Whether bulk optimization also regenerates sub-sizes as WebP (default off to keep URLs unchanged for ERPs).
	 */
	public static function bulk_webp_subsizes(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'bulk_webp_subsizes' );
	}

	/**
	 * Whether bulk runs also scale existing originals down to max_side (default off: banners and theme assets
	 * wider than max_side keep their resolution; bulk only recompresses).
	 */
	public static function bulk_resize(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'bulk_resize' );
	}

	/**
	 * Integer value clamped within bounds (default on invalid input).
	 *
	 * @param string $key Config key.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 */
	private static function clamp( string $key, int $min, int $max ): int {
		$value = Options::get( self::OPTION_KEY, $key, self::DEFAULTS[ $key ] );
		$value = is_scalar( $value ) ? (int) $value : 0;
		return $value < $min ? (int) self::DEFAULTS[ $key ] : min( $max, $value );
	}
}
