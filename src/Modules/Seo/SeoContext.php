<?php
/**
 * Request-scoped SEO context container populated by BikePages or other Motored renderers.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Holds title, meta description, canonical, robots, OG image and JSON-LD nodes for the current request.
 */
final class SeoContext {

	/**
	 * Active SEO payload for the current request.
	 *
	 * @var array<string,mixed>
	 */
	private static array $data = array();

	/**
	 * Sets or merges SEO context values for the current request.
	 *
	 * @param array<string,mixed> $payload Context values (title, description, canonical, robots, og_image, og_type, schema).
	 */
	public static function set( array $payload ): void {
		self::$data = array_merge( self::$data, $payload );
	}

	/**
	 * Returns the resolved SEO context after applying the `motored_seo_context` filter.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$data = self::$data;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'motored_seo_context', $data );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		return $data;
	}

	/**
	 * Resets static request state (for unit tests).
	 */
	public static function reset(): void {
		self::$data = array();
	}
}
