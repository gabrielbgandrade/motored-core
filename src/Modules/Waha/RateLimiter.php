<?php
/**
 * Per-minute and per-hour send counters.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters kept in Support\Cache (best-effort: a cache wipe only resets the
 * window, it never loses data). Never sleeps; callers reschedule.
 */
final class RateLimiter {

	/**
	 * Seconds to wait before sending is allowed again (0 = allowed now).
	 *
	 * @param int $now        Unix time.
	 * @param int $per_minute Limit per minute.
	 * @param int $per_hour   Limit per hour.
	 */
	public static function wait_seconds( int $now, int $per_minute, int $per_hour ): int {
		$wait = 0;
		if ( Cache::counter( self::minute_key( $now ), ChatResolver::BUCKET ) >= $per_minute ) {
			$wait = 60 - ( $now % 60 );
		}
		if ( Cache::counter( self::hour_key( $now ), ChatResolver::BUCKET ) >= $per_hour ) {
			$wait = max( $wait, 3600 - ( $now % 3600 ) );
		}
		return $wait;
	}

	/**
	 * Records one send in the current windows.
	 *
	 * @param int $now Unix time.
	 */
	public static function hit( int $now ): void {
		Cache::incr( self::minute_key( $now ), 1, 120, ChatResolver::BUCKET );
		Cache::incr( self::hour_key( $now ), 1, 7200, ChatResolver::BUCKET );
	}

	/**
	 * Key of the minute window.
	 *
	 * @param int $now Unix time.
	 */
	private static function minute_key( int $now ): string {
		return 'rate:m:' . intdiv( $now, 60 );
	}

	/**
	 * Key of the hour window.
	 *
	 * @param int $now Unix time.
	 */
	private static function hour_key( int $now ): string {
		return 'rate:h:' . intdiv( $now, 3600 );
	}
}
