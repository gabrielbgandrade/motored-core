<?php
/**
 * ReviewPhotos configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_review_photos` (autoload=false).
 */
final class Config {

	public const OPTION_KEY = 'review_photos';

	public const DEFAULTS = array(
		'enabled'     => 'no',
		'max_photos'  => '3',
		'max_mb'      => '5',
		'show_photos' => 'yes',
	);

	/**
	 * Whether customers may attach photos to reviews.
	 */
	public static function enabled(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'enabled' );
	}

	/**
	 * Whether approved reviews show their photos.
	 */
	public static function show_photos(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'show_photos' );
	}

	/**
	 * Maximum photos per review (1-5).
	 */
	public static function max_photos(): int {
		return self::clamp( 'max_photos', 1, 5 );
	}

	/**
	 * Maximum megabytes per photo (1-10).
	 */
	public static function max_mb(): int {
		return self::clamp( 'max_mb', 1, 10 );
	}

	/**
	 * Maximum bytes per photo.
	 */
	public static function max_bytes(): int {
		return self::max_mb() * 1048576;
	}

	/**
	 * Integer within bounds (default when invalid).
	 *
	 * @param string $key Config key.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 */
	private static function clamp( string $key, int $min, int $max ): int {
		$value = Options::get( self::OPTION_KEY, $key, self::DEFAULTS[ $key ] );
		$value = is_scalar( $value ) ? (int) $value : 0;
		return $value < $min ? (int) self::DEFAULTS[ $key ] : min( $max, $value );
	}
}
