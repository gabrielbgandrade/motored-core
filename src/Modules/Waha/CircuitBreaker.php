<?php
/**
 * Circuit breaker for WAHA sends.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * N consecutive service failures pause sending for X minutes. State lives in Support\Cache:
 * if it is lost the breaker simply closes (sending resumes), which is the safe direction.
 */
final class CircuitBreaker {

	private const UNTIL = 'breaker:until';
	private const FAILS = 'breaker:fails';

	/**
	 * Unix time until which sending is paused (0 = closed).
	 */
	public static function until(): int {
		$value = Cache::get( self::UNTIL, ChatResolver::BUCKET );
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Consecutive failures counted so far.
	 */
	public static function failures(): int {
		return Cache::counter( self::FAILS, ChatResolver::BUCKET );
	}

	/**
	 * Registers a successful send.
	 */
	public static function record_success(): void {
		Cache::delete( self::FAILS, ChatResolver::BUCKET );
	}

	/**
	 * Registers a service failure; opens the breaker at the threshold.
	 *
	 * @param int $now       Unix time.
	 * @param int $threshold Consecutive failures that open it.
	 * @param int $minutes   Pause length.
	 * @return bool True when this failure opened the breaker.
	 */
	public static function record_failure( int $now, int $threshold, int $minutes ): bool {
		$count = Cache::incr( self::FAILS, 1, 86400, ChatResolver::BUCKET );
		if ( false === $count || $count < $threshold ) {
			return false;
		}
		Cache::set( self::UNTIL, $now + ( $minutes * 60 ), ( $minutes * 60 ) + 60, ChatResolver::BUCKET );
		Cache::delete( self::FAILS, ChatResolver::BUCKET );
		return true;
	}
}
