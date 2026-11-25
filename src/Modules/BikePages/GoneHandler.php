<?php
/**
 * HTTP 410 for bike pages that were removed on purpose (no 301 target).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

defined( 'ABSPATH' ) || exit;

// One indexed lookup over wp_posts/wp_postmeta, only on 404s under the bike pages base path.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * A trashed `motored_bike` page (its last motorcycle version was unpublished or deleted and no sibling page exists)
 * answers 410 Gone instead of 404, so search engines drop it quickly instead of re-crawling it.
 *
 * Order on template_redirect: Seo\Redirects (1, explicit 301 map) → core wp_old_slug_redirect (10) → this (20).
 */
final class GoneHandler {

	/**
	 * Registers the frontend hook.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_send_gone' ), 20 );
	}

	/**
	 * Hook template_redirect: 410 when the 404 path is `/{base}/{slug}/` of a trashed bike page.
	 */
	public function maybe_send_gone(): void {
		if ( ! function_exists( 'is_404' ) || ! is_404() || ! Config::enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and sanitized below.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$slug = self::slug_from_path( (string) wp_parse_url( $uri, PHP_URL_PATH ), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), Config::base_slug() );
		if ( '' === $slug || ! self::is_trashed_bike_slug( $slug ) ) {
			return;
		}
		status_header( 410 );
		nocache_headers();
	}

	/**
	 * Pure parser: returns `{slug}` for `{home_path}{base}/{slug}/`, '' for anything else (hubs, brand pages, deeper paths).
	 *
	 * @param string $path      Request path.
	 * @param string $home_path Path of home_url( '/' ) (subdirectory installs).
	 * @param string $base      Bike pages base slug.
	 */
	public static function slug_from_path( string $path, string $home_path, string $base ): string {
		$home_path = '/' . trim( $home_path, '/' );
		$path      = '/' . trim( $path, '/' );
		$prefix    = rtrim( $home_path, '/' ) . '/' . trim( $base, '/' ) . '/';
		if ( '' === trim( $base, '/' ) || ! str_starts_with( $path . '/', $prefix ) ) {
			return '';
		}
		$rest = trim( substr( $path, strlen( $prefix ) - 1 ), '/' );
		if ( '' === $rest || str_contains( $rest, '/' ) ) {
			return '';
		}
		return sanitize_title( $rest );
	}

	/**
	 * Whether a trashed bike page originally used this slug (`_wp_desired_post_slug`, set by core on trash).
	 *
	 * @param string $slug Post slug.
	 */
	private static function is_trashed_bike_slug( string $slug ): bool {
		global $wpdb;
		if ( '' === $slug || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_desired_post_slug'
				WHERE p.post_type = %s AND p.post_status = 'trash' AND pm.meta_value = %s
				LIMIT 1",
				PostType::POST_TYPE,
				$slug
			)
		);
		return null !== $found;
	}
}
