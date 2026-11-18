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
	 * Seconds until the global "next send" slot opens (0 = open or unknown). Soft guarantee:
	 * an empty cache simply means no extra spacing beyond the scheduling jitter.
	 *
	 * @param int $now Unix time.
	 */
	public static function spacing_wait( int $now ): int {
		$found = false;
		$slot  = Cache::get( 'next_slot', ChatResolver::BUCKET, $found );
		return $found && is_int( $slot ) && $slot > $now ? $slot - $now : 0;
	}

	/**
	 * Reserves the next slot after a send.
	 *
	 * @param int $now Unix time.
	 * @param int $gap Seconds until the next message may go out.
	 */
	public static function reserve_slot( int $now, int $gap ): void {
		Cache::set( 'next_slot', $now + max( 0, $gap ), max( 120, $gap * 2 ), ChatResolver::BUCKET );
	}

	/**
	 * Messages counted today (site timezone).
	 *
	 * @param int $now Unix time.
	 */
	public static function daily_count( int $now ): int {
		return Cache::counter( self::day_key( $now ), ChatResolver::BUCKET );
	}

	/**
	 * Records one send in today's counter.
	 *
	 * @param int $now Unix time.
	 */
	public static function daily_hit( int $now ): void {
		Cache::incr( self::day_key( $now ), 1, 172800, ChatResolver::BUCKET );
	}

	/**
	 * Seconds until the start of the next day when today's allowance for this priority is used up
	 * (0 = allowed now). Low priority only uses `$low_percent` % of the cap.
	 *
	 * @param int    $now         Unix time.
	 * @param int    $cap         Daily cap.
	 * @param string $priority    Sender::HIGH|NORMAL|LOW.
	 * @param int    $low_percent Share of the cap for low priority.
	 */
	public static function daily_wait( int $now, int $cap, string $priority, int $low_percent ): int {
		$limit = Sender::LOW === $priority ? (int) floor( $cap * $low_percent / 100 ) : $cap;
		if ( self::daily_count( $now ) < $limit ) {
			return 0;
		}
		$tomorrow = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->modify( 'tomorrow midnight' );
		return max( 1, $tomorrow->getTimestamp() - $now );
	}

	/**
	 * Key of today's counter in the site timezone.
	 *
	 * @param int $now Unix time.
	 */
	private static function day_key( int $now ): string {
		return 'day:' . wp_date( 'Ymd', $now, wp_timezone() );
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
