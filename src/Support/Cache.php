<?php
/**
 * Object-cache wrapper.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Object-cache wrapper (wp_cache_*, group "motored") with versioned keys and a transient fallback.
 *
 * SEMANTICS AND LIMITATIONS
 * - The cache is NEVER a source of truth. Every caller must work on a miss.
 * - Keys are "motored:{schema}:{namespace}:{nsversion}:{key}". bump() replaces the
 *   namespace version, which invalidates the whole namespace without
 *   wp_cache_flush_group() (not supported by every drop-in). Orphaned entries expire by TTL.
 * - Values are wrapped so that false/null are cacheable and misses are unambiguous.
 * - With a persistent object cache (Redis) wp_cache_* is used. Without it, wp_cache_* is
 *   per-request only, so we fall back to transients (DB-backed: use only off the public
 *   request path). The fallback caps TTL at 30 days so transients are never autoloaded.
 * - add() and incr() are atomic only with a persistent cache; on the fallback they are
 *   best-effort (read-then-write). Never rely on them for correctness.
 * - Redis down or evicted keys behave like a miss.
 */
final class Cache {

	public const GROUP  = 'motored';
	public const SCHEMA = '1';

	/**
	 * Per-request memo of namespace versions.
	 *
	 * @var array<string,string>
	 */
	private static array $versions = array();

	/**
	 * Whether a persistent object cache is active.
	 */
	public static function is_persistent(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Reads a value.
	 *
	 * @param string    $key       Key.
	 * @param string    $bucket Invalidation namespace.
	 * @param bool|null $found     Set to whether the key was found.
	 * @param-out bool  $found
	 * @return mixed Null on miss.
	 */
	public static function get( string $key, string $bucket = 'default', &$found = null ) {
		$raw   = self::raw_get( self::key( $key, $bucket ) );
		$found = is_array( $raw ) && array_key_exists( 'v', $raw );
		return $found ? $raw['v'] : null;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key       Key.
	 * @param mixed  $value     Value.
	 * @param int    $ttl       Seconds (0 = no expiry on persistent cache; capped on fallback).
	 * @param string $bucket Invalidation namespace.
	 */
	public static function set( string $key, $value, int $ttl = 0, string $bucket = 'default' ): bool {
		return self::raw_set( self::key( $key, $bucket ), array( 'v' => $value ), $ttl );
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $key       Key.
	 * @param string $bucket Invalidation namespace.
	 */
	public static function delete( string $key, string $bucket = 'default' ): bool {
		$full = self::key( $key, $bucket );
		return self::is_persistent() ? (bool) wp_cache_delete( $full, self::GROUP ) : (bool) delete_transient( self::transient_name( $full ) );
	}

	/**
	 * Returns the cached value or computes, stores and returns it.
	 *
	 * @param string   $key       Key.
	 * @param callable $callback  Producer, called on miss.
	 * @param int      $ttl       Seconds.
	 * @param string   $bucket Invalidation namespace.
	 * @return mixed
	 */
	public static function remember( string $key, callable $callback, int $ttl = 0, string $bucket = 'default' ) {
		$found = false;
		$value = self::get( $key, $bucket, $found );
		if ( $found ) {
			return $value;
		}
		$value = $callback();
		self::set( $key, $value, $ttl, $bucket );
		return $value;
	}

	/**
	 * Stores a value only if the key does not exist (lock primitive; best-effort on fallback).
	 *
	 * @param string $key       Key.
	 * @param mixed  $value     Value.
	 * @param int    $ttl       Seconds.
	 * @param string $bucket Invalidation namespace.
	 * @return bool True when this call created the key.
	 */
	public static function add( string $key, $value, int $ttl = 0, string $bucket = 'default' ): bool {
		$full = self::key( $key, $bucket );
		if ( self::is_persistent() ) {
			return (bool) wp_cache_add( $full, array( 'v' => $value ), self::GROUP, $ttl );
		}
		if ( is_array( self::raw_get( $full ) ) ) {
			return false;
		}
		return self::raw_set( $full, array( 'v' => $value ), $ttl );
	}

	/**
	 * Increments an integer counter, creating it at $by when missing.
	 *
	 * Counters are stored as bare integers (not wrapped) so wp_cache_incr() works.
	 *
	 * @param string $key       Key.
	 * @param int    $by        Amount.
	 * @param int    $ttl       TTL used when the counter is created.
	 * @param string $bucket Invalidation namespace.
	 * @return int|false New value, or false on failure.
	 */
	public static function incr( string $key, int $by = 1, int $ttl = 0, string $bucket = 'default' ) {
		$full = self::key( $key, $bucket );
		if ( self::is_persistent() ) {
			wp_cache_add( $full, 0, self::GROUP, $ttl );
			return wp_cache_incr( $full, $by, self::GROUP );
		}
		$name    = self::transient_name( $full );
		$current = get_transient( $name );
		$next    = ( is_numeric( $current ) ? (int) $current : 0 ) + $by;
		return set_transient( $name, $next, self::fallback_ttl( $ttl ) ) ? $next : false;
	}

	/**
	 * Reads a counter created by incr().
	 *
	 * @param string $key       Key.
	 * @param string $bucket Invalidation namespace.
	 */
	public static function counter( string $key, string $bucket = 'default' ): int {
		$full = self::key( $key, $bucket );
		if ( self::is_persistent() ) {
			$found = false;
			$value = wp_cache_get( $full, self::GROUP, false, $found );
		} else {
			$value = get_transient( self::transient_name( $full ) );
		}
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Invalidates every key in a namespace by replacing its version.
	 *
	 * @param string $bucket Invalidation namespace.
	 */
	public static function bump( string $bucket = 'default' ): void {
		$token = bin2hex( random_bytes( 4 ) );
		self::raw_set( self::version_key( $bucket ), array( 'v' => $token ), 0 );
		self::$versions[ $bucket ] = $token;
	}

	/**
	 * Clears per-request memo (tests and long-running processes).
	 */
	public static function reset(): void {
		self::$versions = array();
	}

	/**
	 * Builds the full versioned key.
	 *
	 * @param string $key       Key.
	 * @param string $bucket Namespace.
	 */
	public static function key( string $key, string $bucket = 'default' ): string {
		return sprintf( 'motored:%s:%s:%s:%s', self::SCHEMA, $bucket, self::version( $bucket ), $key );
	}

	/**
	 * Resolves (creating if needed) the current namespace version token.
	 *
	 * @param string $bucket Namespace.
	 */
	private static function version( string $bucket ): string {
		if ( isset( self::$versions[ $bucket ] ) ) {
			return self::$versions[ $bucket ];
		}
		$version_key = self::version_key( $bucket );
		$raw         = self::raw_get( $version_key );
		if ( ! is_array( $raw ) || ! isset( $raw['v'] ) ) {
			$token = bin2hex( random_bytes( 4 ) );
			if ( self::is_persistent() ) {
				// Atomic add: if another request won the race, adopt its token.
				wp_cache_add( $version_key, array( 'v' => $token ), self::GROUP, 0 );
				$raw = self::raw_get( $version_key );
			} else {
				self::raw_set( $version_key, array( 'v' => $token ), 0 );
				$raw = array( 'v' => $token );
			}
			if ( ! is_array( $raw ) || ! isset( $raw['v'] ) ) {
				$raw = array( 'v' => $token );
			}
		}
		self::$versions[ $bucket ] = (string) $raw['v'];
		return self::$versions[ $bucket ];
	}

	/**
	 * Key that stores a namespace's version token.
	 *
	 * @param string $bucket Namespace.
	 */
	private static function version_key( string $bucket ): string {
		return sprintf( 'motored:%s:nsver:%s', self::SCHEMA, $bucket );
	}

	/**
	 * Backend read.
	 *
	 * @param string $full Full key.
	 * @return mixed
	 */
	private static function raw_get( string $full ) {
		if ( self::is_persistent() ) {
			$found = false;
			$raw   = wp_cache_get( $full, self::GROUP, false, $found );
			return $found ? $raw : null;
		}
		$raw = get_transient( self::transient_name( $full ) );
		return false === $raw ? null : $raw;
	}

	/**
	 * Backend write.
	 *
	 * @param string $full  Full key.
	 * @param mixed  $value Wrapped value.
	 * @param int    $ttl   Seconds.
	 */
	private static function raw_set( string $full, $value, int $ttl ): bool {
		if ( self::is_persistent() ) {
			return (bool) wp_cache_set( $full, $value, self::GROUP, $ttl );
		}
		return (bool) set_transient( self::transient_name( $full ), $value, self::fallback_ttl( $ttl ) );
	}

	/**
	 * Transient name (max 172 chars) for a full key.
	 *
	 * @param string $full Full key.
	 */
	private static function transient_name( string $full ): string {
		return 'motored_' . md5( $full );
	}

	/**
	 * Transient TTL: never 0 (would autoload), capped at 30 days.
	 *
	 * @param int $ttl Requested seconds.
	 */
	private static function fallback_ttl( int $ttl ): int {
		$max = 30 * 86400;
		return ( $ttl <= 0 || $ttl > $max ) ? $max : $ttl;
	}
}
