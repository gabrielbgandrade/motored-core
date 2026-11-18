<?php
/**
 * Rate limit of the capture endpoint.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed one-minute windows per session and per IP, counted in Support\Cache (best-effort).
 * Keys use hashes: no session id or IP is stored in clear text.
 */
final class CaptureLimiter {

	public const BUCKET        = 'recovery';
	public const SESSION_LIMIT = 6;
	public const IP_LIMIT      = 30;

	/**
	 * Registers a hit and tells whether the request may proceed.
	 *
	 * @param string $session_key Shopper session.
	 * @param string $ip          Remote address.
	 * @param int    $now         Unix time.
	 */
	public static function allow( string $session_key, string $ip, int $now ): bool {
		$window  = intdiv( $now, 60 );
		$session = Cache::incr( 'cap:s:' . md5( $session_key ) . ':' . $window, 1, 120, self::BUCKET );
		$remote  = Cache::incr( 'cap:i:' . md5( $ip ) . ':' . $window, 1, 120, self::BUCKET );
		return false !== $session && false !== $remote && $session <= self::SESSION_LIMIT && $remote <= self::IP_LIMIT;
	}
}
