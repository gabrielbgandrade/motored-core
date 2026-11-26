<?php
/**
 * 301/302 redirect storage, Redis-cached 404 lookup, automatic slug-change redirects and admin CRUD.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Cache;
use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table(); scalar parameters use $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Performs zero queries on 200 requests; checks Redis cache then motored_seo_redirects only on 404s.
 */
final class Redirects {

	public const CACHE_BUCKET = 'seo_redirects';
	private const CACHE_TTL   = 1800;

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
	 * Registers or updates a redirect rule (prevents self-loops) and invalidates the Redis bucket.
	 *
	 * @param string $source_path Source path (e.g. /cubiertas-para/old-slug/).
	 * @param string $target_url  Target URL or path.
	 * @param int    $status_code HTTP redirect code (301, 302, 307 or 308).
	 */
	public static function add( string $source_path, string $target_url, int $status_code = 301 ): bool {
		global $wpdb;
		$src = self::normalize_path( $source_path );
		$dst = trim( $target_url );
		if ( str_starts_with( $dst, '/' ) && function_exists( 'home_url' ) ) {
			$dst = home_url( $dst );
		}
		$dst = esc_url_raw( $dst );
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
		if ( false !== $ok ) {
			self::invalidate_cache();
			return true;
		}
		return false;
	}

	/**
	 * Deletes one redirect rule by ID and invalidates the Redis bucket.
	 *
	 * @param int $id Row ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		if ( $id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$table = self::table();
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id = %d LIMIT 1",
				$id
			)
		);
		if ( false !== $ok ) {
			self::invalidate_cache();
			return true;
		}
		return false;
	}

	/**
	 * Lists recent redirect rules for the admin table.
	 *
	 * @param int $limit Max rows to return.
	 * @return list<array{id:int,source_path:string,target_url:string,status_code:int,created_gmt:string}>
	 */
	public static function list_rules( int $limit = 50 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$limit = max( 1, min( 200, $limit ) );
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_path, target_url, status_code, created_gmt FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) ( $row['id'] ?? 0 ),
				'source_path' => (string) ( $row['source_path'] ?? '' ),
				'target_url'  => (string) ( $row['target_url'] ?? '' ),
				'status_code' => (int) ( $row['status_code'] ?? 301 ),
				'created_gmt' => (string) ( $row['created_gmt'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Looks up a redirect target for a path (Redis-cached on persistent cache; checks redirect table, then _motored_prev_slug).
	 *
	 * @param string $source_path Request path.
	 * @return array{target_url:string,status_code:int}|null
	 */
	public static function find( string $source_path ): ?array {
		$src = self::normalize_path( $source_path );
		if ( '' === $src || '/' === $src ) {
			return null;
		}

		$lookup = static function () use ( $src ): ?array {
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
		};

		if ( function_exists( 'wp_using_ext_object_cache' ) && Cache::is_persistent() ) {
			$res = Cache::remember( 'path:' . md5( $src ), $lookup, self::CACHE_TTL, self::CACHE_BUCKET );
			return is_array( $res ) ? $res : null;
		}

		return $lookup();
	}

	/**
	 * Records a 301 redirect automatically when a published product/page/post changes its slug.
	 *
	 * @param int   $post_id     Post ID.
	 * @param mixed $post_after  Updated WP_Post.
	 * @param mixed $post_before Previous WP_Post.
	 */
	public static function on_post_updated( int $post_id, $post_after, $post_before ): void {
		if ( ! Config::auto_product_redirects() || ! is_object( $post_after ) || ! is_object( $post_before ) ) {
			return;
		}
		if ( 'publish' !== (string) ( $post_before->post_status ?? '' ) || 'publish' !== (string) ( $post_after->post_status ?? '' ) ) {
			return;
		}
		$pt = (string) ( $post_after->post_type ?? '' );
		if ( ! in_array( $pt, HeadRenderer::MANAGED_POST_TYPES, true ) ) {
			return;
		}
		$old_slug = (string) ( $post_before->post_name ?? '' );
		$new_slug = (string) ( $post_after->post_name ?? '' );
		if ( '' === $old_slug || '' === $new_slug || $old_slug === $new_slug || ! function_exists( 'get_permalink' ) ) {
			return;
		}
		$new_url = get_permalink( $post_id );
		if ( ! is_string( $new_url ) || '' === $new_url ) {
			return;
		}
		$old_path = preg_replace( '#/' . preg_quote( $new_slug, '#' ) . '/?$#', '/' . $old_slug . '/', (string) wp_parse_url( $new_url, PHP_URL_PATH ) );
		if ( is_string( $old_path ) && '' !== $old_path && self::normalize_path( $old_path ) !== self::normalize_path( $new_url ) ) {
			self::add( $old_path, $new_url, 301 );
		}
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

	/**
	 * Bumps Redis redirect cache version safely.
	 */
	public static function invalidate_cache(): void {
		if ( function_exists( 'wp_using_ext_object_cache' ) ) {
			Cache::bump( self::CACHE_BUCKET );
		}
	}
}
