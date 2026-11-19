<?php
/**
 * Persistent-cache-only wrapper for Compatibility catalog and match queries.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Avoids writing transients into wp_options on public paths when Redis/object cache is absent.
 */
final class CompatCache {

	public const BUCKET_CATALOG = 'compat_catalog';
	public const BUCKET_MATCHES = 'compat_matches';

	public const TTL_CATALOG = 21600; // 6 hours.
	public const TTL_MATCHES = 3600;  // 1 hour.

	/**
	 * Reads or computes a cached entry using Support\Cache only when persistent object cache is active.
	 *
	 * @template T
	 * @param string        $key      Cache key.
	 * @param callable(): T $producer Source-of-truth producer callback.
	 * @param string        $bucket   Namespace bucket.
	 * @param int           $ttl      Seconds.
	 * @return T
	 */
	public static function remember( string $key, callable $producer, string $bucket = self::BUCKET_CATALOG, int $ttl = self::TTL_CATALOG ) {
		if ( ! Cache::is_persistent() ) {
			return $producer();
		}
		return Cache::remember( $key, $producer, $ttl, $bucket );
	}

	/**
	 * Invalidates both catalog and match caches (called after motorcycle/fitment/equivalence edits).
	 */
	public static function bump_catalog(): void {
		if ( ! Cache::is_persistent() ) {
			return;
		}
		Cache::bump( self::BUCKET_CATALOG );
		Cache::bump( self::BUCKET_MATCHES );
	}

	/**
	 * Invalidates only product match caches (called when product attributes change).
	 */
	public static function bump_matches(): void {
		if ( ! Cache::is_persistent() ) {
			return;
		}
		Cache::bump( self::BUCKET_MATCHES );
	}
}
