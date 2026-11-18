<?php
/**
 * Per-number message frequency limits (soft limit).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces per-phone rate limits: max 1 message per 24 hours and max 3 per week.
 * Contadores stored in Cache bucket 'recovery'. Documented as a soft limit:
 * if Cache/Redis is flushed, it resets safely without causing fatal errors.
 */
final class MessageLimiter {

	public const TTL_24H  = 86400;
	public const TTL_WEEK = 604800;

	/**
	 * Checks whether a phone number can receive a message according to the limits.
	 *
	 * @param string $raw_phone Phone number.
	 * @param int    $max_24h   Max messages per 24h.
	 * @param int    $max_week  Max messages per week.
	 */
	public static function can_send( string $raw_phone, int $max_24h = 1, int $max_week = 3 ): bool {
		$digits = Phone::digits( $raw_phone );
		if ( '' === $digits ) {
			return true;
		}

		$hash = Suppression::hash( $digits ); // Keyed HMAC: a cache dump does not reveal phone numbers.

		$count_24h = Cache::counter( 'msg_24h:' . $hash, CaptureLimiter::BUCKET );
		if ( $count_24h >= $max_24h ) {
			return false;
		}

		$count_week = Cache::counter( 'msg_week:' . $hash, CaptureLimiter::BUCKET );
		if ( $count_week >= $max_week ) {
			return false;
		}

		return true;
	}

	/**
	 * Records a message sent to the phone number.
	 *
	 * @param string $raw_phone Phone number.
	 */
	public static function hit( string $raw_phone ): void {
		$digits = Phone::digits( $raw_phone );
		if ( '' === $digits ) {
			return;
		}

		$hash = Suppression::hash( $digits ); // Keyed HMAC: a cache dump does not reveal phone numbers.

		Cache::incr( 'msg_24h:' . $hash, 1, self::TTL_24H, CaptureLimiter::BUCKET );
		Cache::incr( 'msg_week:' . $hash, 1, self::TTL_WEEK, CaptureLimiter::BUCKET );
	}
}
