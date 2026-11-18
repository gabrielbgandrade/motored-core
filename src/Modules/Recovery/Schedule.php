<?php
/**
 * Sending window calculation (pure functions).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Pure schedule logic: evaluates whether a timestamp falls inside the sending window
 * and calculates the next start time when outside.
 */
final class Schedule {

	public const DEFAULT_START = '09:00';
	public const DEFAULT_END   = '21:00';

	/**
	 * Checks whether a unix timestamp falls within the allowed sending window.
	 *
	 * @param int                $timestamp Unix time.
	 * @param string             $start     Window start 'HH:MM'.
	 * @param string             $end       Window end 'HH:MM'.
	 * @param \DateTimeZone|null $tz        Store timezone (defaults to wp_timezone()).
	 */
	public static function is_inside_window( int $timestamp, string $start = self::DEFAULT_START, string $end = self::DEFAULT_END, ?\DateTimeZone $tz = null ): bool {
		$tz   = self::resolve_timezone( $tz );
		$dt   = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz );
		$time = $dt->format( 'H:i' );

		$start = self::normalize_time( $start, self::DEFAULT_START );
		$end   = self::normalize_time( $end, self::DEFAULT_END );

		if ( $start <= $end ) {
			return $time >= $start && $time < $end;
		}

		// Cross-midnight window (e.g. 22:00 to 06:00).
		return $time >= $start || $time < $end;
	}

	/**
	 * Returns the unix timestamp for the next window start after the given timestamp.
	 *
	 * @param int                $timestamp Unix time.
	 * @param string             $start     Window start 'HH:MM'.
	 * @param string             $end       Window end 'HH:MM'.
	 * @param \DateTimeZone|null $tz        Store timezone (defaults to wp_timezone()).
	 */
	public static function next_window_start( int $timestamp, string $start = self::DEFAULT_START, string $end = self::DEFAULT_END, ?\DateTimeZone $tz = null ): int {
		$tz    = self::resolve_timezone( $tz );
		$dt    = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz );
		$start = self::normalize_time( $start, self::DEFAULT_START );
		$parts = explode( ':', $start );
		$hour  = (int) $parts[0];
		$min   = (int) ( $parts[1] ?? 0 );

		$start_today = $dt->setTime( $hour, $min, 0 );

		if ( $dt < $start_today ) {
			return $start_today->getTimestamp();
		}

		// If at or past start_today, next start is tomorrow at start time.
		return $start_today->modify( '+1 day' )->getTimestamp();
	}

	/**
	 * Resolves timezone object.
	 *
	 * @param \DateTimeZone|null $tz Timezone.
	 */
	public static function resolve_timezone( ?\DateTimeZone $tz ): \DateTimeZone {
		if ( null !== $tz ) {
			return $tz;
		}
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Normalizes HH:MM string.
	 *
	 * @param string $time    Raw time.
	 * @param string $fallback Fallback.
	 */
	private static function normalize_time( string $time, string $fallback ): string {
		$time = trim( $time );
		if ( 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return $time;
		}
		return $fallback;
	}
}
