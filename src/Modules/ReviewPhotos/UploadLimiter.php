<?php
/**
 * Per-IP upload rate limit.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Counts photos per visitor and hour in Support\Cache (best-effort; keys use a keyed hash of the IP).
 */
final class UploadLimiter {

	public const BUCKET = 'review_photos';
	public const LIMIT  = 5;
	public const WINDOW = 3600;

	/**
	 * Photos this IP may still upload in the current hour.
	 *
	 * @param string $ip  Remote address.
	 * @param int    $now Unix time.
	 */
	public static function remaining( string $ip, int $now ): int {
		return max( 0, self::LIMIT - Cache::counter( self::key( $ip, $now ), self::BUCKET ) );
	}

	/**
	 * Counts photos taken in the current hour.
	 *
	 * @param string $ip    Remote address.
	 * @param int    $now   Unix time.
	 * @param int    $count Photos.
	 */
	public static function record( string $ip, int $now, int $count ): void {
		if ( $count > 0 ) {
			Cache::incr( self::key( $ip, $now ), $count, 2 * self::WINDOW, self::BUCKET );
		}
	}

	/**
	 * Cache key of the hour window.
	 *
	 * @param string $ip  Remote address.
	 * @param int    $now Unix time.
	 */
	private static function key( string $ip, int $now ): string {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'motored_review_photos' ) : 'motored_default_salt';
		return 'up:' . substr( hash_hmac( 'sha256', $ip, $salt ), 0, 32 ) . ':' . intdiv( $now, self::WINDOW );
	}
}
