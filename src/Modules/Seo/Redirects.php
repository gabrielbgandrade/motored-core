<?php
/**
 * 301/302 redirect storage and 404-only lookup handler.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table(); scalar parameters use $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Performs zero queries on 200 requests; checks motored_seo_redirects only on 404s.
 */
final class Redirects {

	/**
	 * Redirects table name.
	 */
	public static function table(): string {
		return Db::table( 'seo_redirects' );
	}

	/**
	 * Normalizes a URL path to a leading-slash, lowercase, query-less key (max 191 chars).
	 *
	 * @param string $raw Path or URL.
	 */
	public static function normalize_path( string $raw ): string {
		$path = (string) wp_parse_url( trim( $raw ), PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );
		if ( strlen( $path ) > 191 ) {
			$path = substr( $path, 0, 191 );
		}
		return strtolower( $path );
	}

	/**
	 * Registers or updates a redirect rule (prevents self-loops).
	 *
	 * @param string $source_path Source path (e.g. /cubiertas-para/old-slug/).
	 * @param string $target_url  Target URL or path.
	 * @param int    $status_code HTTP redirect code (301 or 302).
	 */
	public static function add( string $source_path, string $target_url, int $status_code = 301 ): bool {
		global $wpdb;
		$src = self::normalize_path( $source_path );
		$dst = esc_url_raw( trim( $target_url ) );
		if ( '' === $src || '/' === $src || '' === $dst ) {
			return false;
		}
		if ( self::normalize_path( $dst ) === $src ) {
			return false;
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$code  = in_array( $status_code, array( 301, 302, 307, 308 ), true ) ? $status_code : 301;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_path, target_url, status_code, created_gmt)
				VALUES (%s, %s, %d, %s)
				ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = VALUES(status_code)",
				$src,
				$dst,
				$code,
				$now
			)
		);
		return false !== $ok;
	}

	/**
	 * Looks up a redirect target for a path (checks redirect table, then _motored_prev_slug post meta).
	 *
	 * @param string $source_path Request path.
	 * @return array{target_url:string,status_code:int}|null
	 */
	public static function find( string $source_path ): ?array {
		$src = self::normalize_path( $source_path );
		if ( '' === $src || '/' === $src ) {
			return null;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT target_url, status_code FROM {$table} WHERE source_path = %s LIMIT 1",
				$src
			),
			ARRAY_A
		);
		if ( is_array( $row ) && ! empty( $row['target_url'] ) ) {
			return array(
				'target_url'  => (string) $row['target_url'],
				'status_code' => (int) ( $row['status_code'] ?? 301 ),
			);
		}

		// Fallback: look up previous slug stored on bike/size posts.
		$slug = sanitize_title( basename( trim( $src, '/' ) ) );
		if ( '' !== $slug && isset( $wpdb->postmeta, $wpdb->posts ) ) {
			$pid = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_motored_prev_slug' AND pm.meta_value = %s AND p.post_status = 'publish'
					LIMIT 1",
					$slug
				)
			);
			if ( $pid > 0 && function_exists( 'get_permalink' ) ) {
				$url = get_permalink( $pid );
				if ( is_string( $url ) && '' !== $url && self::normalize_path( $url ) !== $src ) {
					return array(
						'target_url'  => $url,
						'status_code' => 301,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Hook: template_redirect — resolves redirects ONLY on 404 requests (0 queries on 200 pages).
	 */
	public function maybe_redirect(): void {
		if ( ! function_exists( 'is_404' ) || ! is_404() || ! Config::enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized by normalize_path().
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return;
		}
		$hit = self::find( $uri );
		if ( null === $hit ) {
			return;
		}
		$target = esc_url_raw( $hit['target_url'] );
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target, $hit['status_code'] );
		exit;
	}
}
