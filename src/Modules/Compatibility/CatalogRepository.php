<?php
/**
 * Data access for compatibility brands, models, versions, sizes, fitments and equivalences.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table() (validated); all values are bound with $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * CRUD, pagination, natural-key upserts and year-range overlap checks.
 */
final class CatalogRepository {

	/**
	 * Table names.
	 */
	public static function brands_table(): string {
		return Db::table( 'compat_brands' );
	}

	/**
	 * Models table name.
	 */
	public static function models_table(): string {
		return Db::table( 'compat_models' );
	}

	/**
	 * Versions table name.
	 */
	public static function versions_table(): string {
		return Db::table( 'compat_versions' );
	}

	/**
	 * Sizes table name.
	 */
	public static function sizes_table(): string {
		return Db::table( 'compat_sizes' );
	}

	/**
	 * Fitments table name.
	 */
	public static function fitments_table(): string {
		return Db::table( 'compat_fitments' );
	}

	/**
	 * Equivalences table name.
	 */
	public static function equivalences_table(): string {
		return Db::table( 'compat_equivalences' );
	}

	/**
	 * Lists all brands (optionally only those having published versions).
	 *
	 * @param bool $published_only Filter to brands with at least one published version.
	 * @return list<array{id:int,name:string,slug:string}>
	 */
	public static function brands( bool $published_only = false ): array {
		global $wpdb;
		$b = self::brands_table();
		if ( ! $published_only ) {
			$rows = $wpdb->get_results( "SELECT id, name, slug FROM {$b} ORDER BY name ASC", ARRAY_A );
		} else {
			$m    = self::models_table();
			$v    = self::versions_table();
			$rows = $wpdb->get_results(
				"SELECT DISTINCT b.id, b.name, b.slug
				FROM {$b} b
				INNER JOIN {$m} m ON m.brand_id = b.id
				INNER JOIN {$v} v ON v.model_id = m.id AND v.status = 'published'
				ORDER BY b.name ASC",
				ARRAY_A
			);
		}
		return self::map_brands( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Paginated brands for admin.
	 *
	 * @param string $search   Search query.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{items:list<array{id:int,name:string,slug:string,models_count:int}>,total:int}
	 */
	public static function list_brands( string $search = '', int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$b      = self::brands_table();
		$m      = self::models_table();
		$where  = '';
		$params = array();
		if ( '' !== trim( $search ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
			$where    = 'WHERE b.name LIKE %s OR b.slug LIKE %s';
			$params[] = $like;
			$params[] = $like;
		}
		$total_sql = "SELECT COUNT(*) FROM {$b} b {$where}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$list_sql = "SELECT b.id, b.name, b.slug, COUNT(m.id) AS models_count
			FROM {$b} b
			LEFT JOIN {$m} m ON m.brand_id = b.id
			{$where}
			GROUP BY b.id, b.name, b.slug
			ORDER BY b.name ASC
			LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results(
			$wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		$items    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'id'           => (int) $row['id'],
				'name'         => (string) $row['name'],
				'slug'         => (string) $row['slug'],
				'models_count' => (int) $row['models_count'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Finds a brand by slug.
	 *
	 * @param string $slug Brand slug.
	 * @return array{id:int,name:string,slug:string}|null
	 */
	public static function find_brand_by_slug( string $slug ): ?array {
		global $wpdb;
		$b   = self::brands_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug FROM {$b} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'   => (int) $row['id'],
			'name' => (string) $row['name'],
			'slug' => (string) $row['slug'],
		);
	}

	/**
	 * Creates or updates a brand by slug (returns brand ID).
	 *
	 * @param string $name Brand display name.
	 * @param string $slug Optional slug (generated from name when empty).
	 */
	public static function upsert_brand( string $name, string $slug = '' ): int {
		global $wpdb;
		$name = trim( $name );
		$slug = sanitize_title( '' !== $slug ? $slug : $name );
		if ( '' === $name || '' === $slug ) {
			return 0;
		}
		$b        = self::brands_table();
		$existing = self::find_brand_by_slug( $slug );
		if ( null !== $existing ) {
			if ( $existing['name'] !== $name ) {
				$wpdb->update( $b, array( 'name' => $name ), array( 'id' => $existing['id'] ), array( '%s' ), array( '%d' ) );
				CompatCache::bump_catalog();
			}
			return $existing['id'];
		}
		$ok = $wpdb->insert(
			$b,
			array(
				'name' => $name,
				'slug' => $slug,
			),
			array( '%s', '%s' )
		);
		if ( false === $ok ) {
			return 0;
		}
		CompatCache::bump_catalog();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a brand when it has no child models.
	 *
	 * @param int $brand_id Brand ID.
	 */
	public static function delete_brand( int $brand_id ): bool {
		global $wpdb;
		$m     = self::models_table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE brand_id = %d", $brand_id ) );
		if ( $count > 0 ) {
			return false;
		}
		$ok = false !== $wpdb->delete( self::brands_table(), array( 'id' => $brand_id ), array( '%d' ) );
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Lists models for a brand (optionally published versions only).
	 *
	 * @param int  $brand_id       Brand ID (0 = all).
	 * @param bool $published_only Filter to models having published versions.
	 * @return list<array{id:int,brand_id:int,name:string,slug:string,cc:int|null,aliases:string}>
	 */
	public static function models( int $brand_id = 0, bool $published_only = false ): array {
		global $wpdb;
		$m      = self::models_table();
		$v      = self::versions_table();
		$where  = array();
		$params = array();
		if ( $brand_id > 0 ) {
			$where[]  = 'm.brand_id = %d';
			$params[] = $brand_id;
		}
		$join = '';
		if ( $published_only ) {
			$join   = "INNER JOIN {$v} v ON v.model_id = m.id AND v.status = 'published'";
			$prefix = 'SELECT DISTINCT';
		} else {
			$prefix = 'SELECT';
		}
		$w    = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$sql  = "{$prefix} m.id, m.brand_id, m.name, m.slug, m.cc, m.aliases FROM {$m} m {$join} {$w} ORDER BY m.name ASC";
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'       => (int) $row['id'],
				'brand_id' => (int) $row['brand_id'],
				'name'     => (string) $row['name'],
				'slug'     => (string) $row['slug'],
				'cc'       => null !== $row['cc'] ? (int) $row['cc'] : null,
				'aliases'  => (string) $row['aliases'],
			);
		}
		return $out;
	}

	/**
	 * Paginated models for admin.
	 *
	 * @param int    $brand_id Brand filter (0 = all).
	 * @param string $search   Search query (name, slug, aliases).
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{items:list<array{id:int,brand_id:int,brand_name:string,name:string,slug:string,cc:int|null,aliases:string,versions_count:int}>,total:int}
	 */
	public static function list_models( int $brand_id = 0, string $search = '', int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$m      = self::models_table();
		$b      = self::brands_table();
		$v      = self::versions_table();
		$where  = array();
		$params = array();
		if ( $brand_id > 0 ) {
			$where[]  = 'm.brand_id = %d';
			$params[] = $brand_id;
		}
		if ( '' !== trim( $search ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
			$where[]  = '(m.name LIKE %s OR m.aliases LIKE %s OR m.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$w         = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$total_sql = "SELECT COUNT(*) FROM {$m} m {$w}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = "SELECT m.id, m.brand_id, b.name AS brand_name, m.name, m.slug, m.cc, m.aliases, COUNT(v.id) AS versions_count
			FROM {$m} m
			INNER JOIN {$b} b ON b.id = m.brand_id
			LEFT JOIN {$v} v ON v.model_id = m.id
			{$w}
			GROUP BY m.id, m.brand_id, b.name, m.name, m.slug, m.cc, m.aliases
			ORDER BY b.name ASC, m.name ASC
			LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		$items  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'id'             => (int) $row['id'],
				'brand_id'       => (int) $row['brand_id'],
				'brand_name'     => (string) $row['brand_name'],
				'name'           => (string) $row['name'],
				'slug'           => (string) $row['slug'],
				'cc'             => null !== $row['cc'] ? (int) $row['cc'] : null,
				'aliases'        => (string) $row['aliases'],
				'versions_count' => (int) $row['versions_count'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Finds a model by brand ID and slug.
	 *
	 * @param int    $brand_id Brand ID.
	 * @param string $slug     Model slug.
	 * @return array{id:int,brand_id:int,name:string,slug:string,cc:int|null,aliases:string}|null
	 */
	public static function find_model( int $brand_id, string $slug ): ?array {
		global $wpdb;
		$m   = self::models_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, brand_id, name, slug, cc, aliases FROM {$m} WHERE brand_id = %d AND slug = %s LIMIT 1", $brand_id, $slug ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'       => (int) $row['id'],
			'brand_id' => (int) $row['brand_id'],
			'name'     => (string) $row['name'],
			'slug'     => (string) $row['slug'],
			'cc'       => null !== $row['cc'] ? (int) $row['cc'] : null,
			'aliases'  => (string) $row['aliases'],
		);
	}

	/**
	 * Creates or updates a model by (brand_id, slug).
	 *
	 * @param int      $brand_id Brand ID.
	 * @param string   $name     Model name.
	 * @param string   $slug     Optional model slug.
	 * @param int|null $cc       Engine displacement in cc.
	 * @param string   $aliases  Comma-separated search aliases.
	 */
	public static function upsert_model( int $brand_id, string $name, string $slug = '', ?int $cc = null, string $aliases = '' ): int {
		global $wpdb;
		$name    = trim( $name );
		$slug    = sanitize_title( '' !== $slug ? $slug : $name );
		$aliases = trim( $aliases );
		if ( $brand_id <= 0 || '' === $name || '' === $slug ) {
			return 0;
		}
		$m        = self::models_table();
		$existing = self::find_model( $brand_id, $slug );
		if ( null !== $existing ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$m} SET name = %s, cc = %d, aliases = %s WHERE id = %d",
					$name,
					null !== $cc ? $cc : 0,
					$aliases,
					$existing['id']
				)
			);
			if ( null === $cc ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$m} SET cc = NULL WHERE id = %d", $existing['id'] ) );
			}
			CompatCache::bump_catalog();
			return $existing['id'];
		}
		if ( null !== $cc ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$m} (brand_id, name, slug, cc, aliases) VALUES (%d, %s, %s, %d, %s)",
					$brand_id,
					$name,
					$slug,
					$cc,
					$aliases
				)
			);
		} else {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$m} (brand_id, name, slug, cc, aliases) VALUES (%d, %s, %s, NULL, %s)",
					$brand_id,
					$name,
					$slug,
					$aliases
				)
			);
		}
		if ( false === $ok ) {
			return 0;
		}
		CompatCache::bump_catalog();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a model when it has no child versions.
	 *
	 * @param int $model_id Model ID.
	 */
	public static function delete_model( int $model_id ): bool {
		global $wpdb;
		$v     = self::versions_table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE model_id = %d", $model_id ) );
		if ( $count > 0 ) {
			return false;
		}
		$ok = false !== $wpdb->delete( self::models_table(), array( 'id' => $model_id ), array( '%d' ) );
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Checks whether [year_from, year_to] overlaps any existing version of the same (model_id, variant_slug).
	 *
	 * The year_to = 0 sentinel means open-ended ("present") and is normalized to 9999 for interval comparison.
	 *
	 * @param int    $model_id     Model ID.
	 * @param string $variant_slug Normalized variant slug.
	 * @param int    $year_from    Start year.
	 * @param int    $year_to      End year (0 = open).
	 * @param int    $exclude_id   Optional version ID to ignore (when updating).
	 */
	public static function has_year_overlap( int $model_id, string $variant_slug, int $year_from, int $year_to, int $exclude_id = 0 ): bool {
		global $wpdb;
		$v    = self::versions_table();
		$end  = 0 === $year_to ? 9999 : $year_to;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, year_from, year_to FROM {$v} WHERE model_id = %d AND variant_slug = %s AND id != %d",
				$model_id,
				$variant_slug,
				$exclude_id
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$existing_from = (int) $row['year_from'];
			$existing_to   = 0 === (int) $row['year_to'] ? 9999 : (int) $row['year_to'];
			if ( self::ranges_overlap( $year_from, $end, $existing_from, $existing_to ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pure interval overlap helper (used by overlap checks and JSON dry-run).
	 *
	 * @param int $from_a Start A.
	 * @param int $to_a   End A (0 = 9999).
	 * @param int $from_b Start B.
	 * @param int $to_b   End B (0 = 9999).
	 */
	public static function ranges_overlap( int $from_a, int $to_a, int $from_b, int $to_b ): bool {
		$end_a = 0 === $to_a ? 9999 : $to_a;
		$end_b = 0 === $to_b ? 9999 : $to_b;
		return $from_a <= $end_b && $from_b <= $end_a;
	}

	/**
	 * Builds a deterministic initial version slug from brand/model/years/variant.
	 *
	 * @param string $brand_slug   Brand slug.
	 * @param string $model_slug   Model slug.
	 * @param int    $year_from    Start year.
	 * @param int    $year_to      End year (0 = open).
	 * @param string $variant_slug Variant slug.
	 */
	public static function build_version_slug( string $brand_slug, string $model_slug, int $year_from, int $year_to, string $variant_slug = '' ): string {
		$parts = array(
			sanitize_title( $brand_slug ),
			sanitize_title( $model_slug ),
		);
		if ( '' !== $variant_slug ) {
			$parts[] = sanitize_title( $variant_slug );
		}
		$parts[] = $year_from . '-' . ( 0 === $year_to ? 'actual' : (string) $year_to );
		return substr( implode( '-', array_filter( $parts ) ), 0, 180 );
	}

	/**
	 * Finds a version by immutable slug, joined with brand & model names.
	 *
	 * @param string $slug           Version slug.
	 * @param bool   $published_only Whether to require status = published.
	 * @return array<string,mixed>|null
	 */
	public static function find_version_by_slug( string $slug, bool $published_only = false ): ?array {
		global $wpdb;
		$v      = self::versions_table();
		$m      = self::models_table();
		$b      = self::brands_table();
		$status = $published_only ? "AND v.status = 'published'" : '';
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT v.id, v.model_id, v.slug, v.year_from, v.year_to, v.variant, v.variant_slug, v.status, v.verified, v.notes,
					m.name AS model_name, m.slug AS model_slug, m.cc, m.aliases,
					b.id AS brand_id, b.name AS brand_name, b.slug AS brand_slug
				FROM {$v} v
				INNER JOIN {$m} m ON m.id = v.model_id
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE v.slug = %s {$status}
				LIMIT 1",
				$slug
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Finds a version by natural key (model_id, year_from, year_to, variant_slug).
	 *
	 * @param int    $model_id     Model ID.
	 * @param int    $year_from    Start year.
	 * @param int    $year_to      End year (0 = open).
	 * @param string $variant_slug Variant slug.
	 * @return array<string,mixed>|null
	 */
	public static function find_version_by_natural_key( int $model_id, int $year_from, int $year_to, string $variant_slug = '' ): ?array {
		global $wpdb;
		$v   = self::versions_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, model_id, slug, year_from, year_to, variant, variant_slug, status, verified, notes
				FROM {$v}
				WHERE model_id = %d AND year_from = %d AND year_to = %d AND variant_slug = %s
				LIMIT 1",
				$model_id,
				$year_from,
				$year_to,
				$variant_slug
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Finds a version by numeric ID.
	 *
	 * @param int $version_id Version ID.
	 * @return array<string,mixed>|null
	 */
	public static function find_version( int $version_id ): ?array {
		global $wpdb;
		$v   = self::versions_table();
		$m   = self::models_table();
		$b   = self::brands_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT v.id, v.model_id, v.slug, v.year_from, v.year_to, v.variant, v.variant_slug, v.status, v.verified, v.notes,
					m.name AS model_name, m.slug AS model_slug, b.id AS brand_id, b.name AS brand_name, b.slug AS brand_slug
				FROM {$v} v
				INNER JOIN {$m} m ON m.id = v.model_id
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE v.id = %d
				LIMIT 1",
				$version_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lists published versions for a model (used by the storefront cascade selector).
	 *
	 * @param int $model_id Model ID.
	 * @return list<array{id:int,slug:string,year_from:int,year_to:int,variant:string}>
	 */
	public static function published_versions_for_model( int $model_id ): array {
		global $wpdb;
		$v    = self::versions_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, slug, year_from, year_to, variant
				FROM {$v}
				WHERE model_id = %d AND status = 'published'
				ORDER BY year_from DESC, variant ASC",
				$model_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'slug'      => (string) $row['slug'],
				'year_from' => (int) $row['year_from'],
				'year_to'   => (int) $row['year_to'],
				'variant'   => (string) $row['variant'],
			);
		}
		return $out;
	}

	/**
	 * Paginated versions list for admin.
	 *
	 * @param array{brand_id?:int,model_id?:int,status?:string,verified?:string,search?:string} $filters Filters.
	 * @param int                                                                               $page     1-based page.
	 * @param int                                                                               $per_page Page size.
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public static function list_versions( array $filters = array(), int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$v      = self::versions_table();
		$m      = self::models_table();
		$b      = self::brands_table();
		$f      = self::fitments_table();
		$where  = array();
		$params = array();

		if ( ! empty( $filters['brand_id'] ) ) {
			$where[]  = 'b.id = %d';
			$params[] = (int) $filters['brand_id'];
		}
		if ( ! empty( $filters['model_id'] ) ) {
			$where[]  = 'v.model_id = %d';
			$params[] = (int) $filters['model_id'];
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array( 'draft', 'published' ), true ) ) {
			$where[]  = 'v.status = %s';
			$params[] = $filters['status'];
		}
		if ( isset( $filters['verified'] ) && '' !== $filters['verified'] ) {
			$where[]  = 'v.verified = %d';
			$params[] = (int) $filters['verified'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $filters['search'] ) ) . '%';
			$where[]  = '(b.name LIKE %s OR m.name LIKE %s OR v.variant LIKE %s OR v.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$w = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*)
			FROM {$v} v
			INNER JOIN {$m} m ON m.id = v.model_id
			INNER JOIN {$b} b ON b.id = m.brand_id
			{$w}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = "SELECT v.id, v.model_id, v.slug, v.year_from, v.year_to, v.variant, v.variant_slug, v.status, v.verified, v.notes,
				m.name AS model_name, b.name AS brand_name, COUNT(f.id) AS fitments_count
			FROM {$v} v
			INNER JOIN {$m} m ON m.id = v.model_id
			INNER JOIN {$b} b ON b.id = m.brand_id
			LEFT JOIN {$f} f ON f.version_id = v.id
			{$w}
			GROUP BY v.id, v.model_id, v.slug, v.year_from, v.year_to, v.variant, v.variant_slug, v.status, v.verified, v.notes, m.name, b.name
			ORDER BY b.name ASC, m.name ASC, v.year_from DESC
			LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Creates or updates a version by natural key (model_id, year_from, year_to, variant_slug).
	 * Slug is immutable once created.
	 *
	 * @param array{model_id:int,brand_slug:string,model_slug:string,year_from:int,year_to:int,variant:string,status:string,verified:int,notes:string} $data Version payload.
	 */
	public static function upsert_version( array $data ): int {
		global $wpdb;
		$model_id     = (int) $data['model_id'];
		$year_from    = (int) $data['year_from'];
		$year_to      = (int) $data['year_to'];
		$variant      = trim( (string) $data['variant'] );
		$variant_slug = '' !== $variant ? sanitize_title( $variant ) : '';
		$status       = 'published' === $data['status'] ? 'published' : 'draft';
		$verified     = ! empty( $data['verified'] ) ? 1 : 0;
		$notes        = trim( (string) $data['notes'] );
		$now          = gmdate( 'Y-m-d H:i:s' );
		$v            = self::versions_table();

		$existing = self::find_version_by_natural_key( $model_id, $year_from, $year_to, $variant_slug );
		if ( null !== $existing ) {
			$wpdb->update(
				$v,
				array(
					'variant'     => $variant,
					'status'      => $status,
					'verified'    => $verified,
					'notes'       => $notes,
					'updated_gmt' => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			CompatCache::bump_catalog();
			return (int) $existing['id'];
		}

		$base_slug = self::build_version_slug( (string) $data['brand_slug'], (string) $data['model_slug'], $year_from, $year_to, $variant_slug );
		$slug      = self::unique_version_slug( $base_slug );

		$ok = $wpdb->insert(
			$v,
			array(
				'model_id'     => $model_id,
				'slug'         => $slug,
				'year_from'    => $year_from,
				'year_to'      => $year_to,
				'variant'      => $variant,
				'variant_slug' => $variant_slug,
				'status'       => $status,
				'verified'     => $verified,
				'notes'        => $notes,
				'updated_gmt'  => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return 0;
		}
		CompatCache::bump_catalog();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing version by ID without changing its immutable slug.
	 *
	 * @param int                                                                                     $id   Version ID.
	 * @param array{year_from:int,year_to:int,variant:string,status:string,verified:int,notes:string} $data Fields.
	 */
	public static function update_version( int $id, array $data ): bool {
		global $wpdb;
		$variant      = trim( $data['variant'] );
		$variant_slug = '' !== $variant ? sanitize_title( $variant ) : '';
		$status       = 'published' === $data['status'] ? 'published' : 'draft';
		$ok           = false !== $wpdb->update(
			self::versions_table(),
			array(
				'year_from'    => $data['year_from'],
				'year_to'      => $data['year_to'],
				'variant'      => $variant,
				'variant_slug' => $variant_slug,
				'status'       => $status,
				'verified'     => ! empty( $data['verified'] ) ? 1 : 0,
				'notes'        => trim( $data['notes'] ),
				'updated_gmt'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Deletes a version and its fitments.
	 *
	 * @param int $version_id Version ID.
	 */
	public static function delete_version( int $version_id ): bool {
		global $wpdb;
		$wpdb->delete( self::fitments_table(), array( 'version_id' => $version_id ), array( '%d' ) );
		$ok = false !== $wpdb->delete( self::versions_table(), array( 'id' => $version_id ), array( '%d' ) );
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Finds a size row by canonical string or creates it via SizeParser.
	 *
	 * @param string $raw Raw or canonical size string.
	 * @return array{id:int,canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null
	 */
	public static function ensure_size( string $raw ): ?array {
		$parsed = SizeParser::parse( $raw );
		if ( null === $parsed ) {
			return null;
		}
		$existing = self::find_size_by_canonical( $parsed['canonical'] );
		if ( null !== $existing ) {
			return $existing;
		}
		global $wpdb;
		$s = self::sizes_table();
		if ( null !== $parsed['aspect'] ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$s} (canonical, notation, width, aspect, rim) VALUES (%s, %s, %s, %d, %d)",
					$parsed['canonical'],
					$parsed['notation'],
					$parsed['width'],
					$parsed['aspect'],
					$parsed['rim']
				)
			);
		} else {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$s} (canonical, notation, width, aspect, rim) VALUES (%s, %s, %s, NULL, %d)",
					$parsed['canonical'],
					$parsed['notation'],
					$parsed['width'],
					$parsed['rim']
				)
			);
		}
		if ( false === $ok ) {
			return self::find_size_by_canonical( $parsed['canonical'] );
		}
		AttributeInstaller::sync_size_term( $parsed['canonical'] );
		return array(
			'id'        => (int) $wpdb->insert_id,
			'canonical' => $parsed['canonical'],
			'notation'  => $parsed['notation'],
			'width'     => $parsed['width'],
			'aspect'    => $parsed['aspect'],
			'rim'       => $parsed['rim'],
		);
	}

	/**
	 * Finds a size by canonical key without creating it.
	 *
	 * @param string $canonical Canonical key.
	 * @return array{id:int,canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null
	 */
	public static function find_size_by_canonical( string $canonical ): ?array {
		global $wpdb;
		$s   = self::sizes_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, canonical, notation, width, aspect, rim FROM {$s} WHERE canonical = %s LIMIT 1", $canonical ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'        => (int) $row['id'],
			'canonical' => (string) $row['canonical'],
			'notation'  => (string) $row['notation'],
			'width'     => (string) $row['width'],
			'aspect'    => null !== $row['aspect'] ? (int) $row['aspect'] : null,
			'rim'       => (int) $row['rim'],
		);
	}

	/**
	 * Lists catalog sizes with search and pagination.
	 *
	 * @param string $search   Search query.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{items:list<array{id:int,canonical:string,notation:string,width:string,aspect:int|null,rim:int}>,total:int}
	 */
	public static function list_sizes( string $search = '', int $page = 1, int $per_page = 30 ): array {
		global $wpdb;
		$s      = self::sizes_table();
		$where  = '';
		$params = array();
		if ( '' !== trim( $search ) ) {
			$where    = 'WHERE canonical LIKE %s';
			$params[] = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
		}
		$total_sql = "SELECT COUNT(*) FROM {$s} {$where}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = "SELECT id, canonical, notation, width, aspect, rim FROM {$s} {$where} ORDER BY rim ASC, canonical ASC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		$items  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'id'        => (int) $row['id'],
				'canonical' => (string) $row['canonical'],
				'notation'  => (string) $row['notation'],
				'width'     => (string) $row['width'],
				'aspect'    => null !== $row['aspect'] ? (int) $row['aspect'] : null,
				'rim'       => (int) $row['rim'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * All sizes ordered by canonical key (for dropdowns).
	 *
	 * @return list<array{id:int,canonical:string}>
	 */
	public static function all_sizes_min(): array {
		global $wpdb;
		$s    = self::sizes_table();
		$rows = $wpdb->get_results( "SELECT id, canonical FROM {$s} ORDER BY canonical ASC", ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'canonical' => (string) $row['canonical'],
			);
		}
		return $out;
	}

	/**
	 * Returns fitments for a motorcycle version joined with canonical size data.
	 *
	 * @param int $version_id Version ID.
	 * @return list<array{id:int,version_id:int,position:string,size_id:int,canonical:string,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,source:string,note:string}>
	 */
	public static function fitments_for_version( int $version_id ): array {
		global $wpdb;
		$f    = self::fitments_table();
		$s    = self::sizes_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.version_id, f.position, f.size_id, s.canonical, f.fit_type, f.min_load, f.min_speed_rank, f.req_construction, f.source, f.note
				FROM {$f} f
				INNER JOIN {$s} s ON s.id = f.size_id
				WHERE f.version_id = %d
				ORDER BY f.position ASC, f.fit_type DESC, s.canonical ASC",
				$version_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'               => (int) $row['id'],
				'version_id'       => (int) $row['version_id'],
				'position'         => (string) $row['position'],
				'size_id'          => (int) $row['size_id'],
				'canonical'        => (string) $row['canonical'],
				'fit_type'         => (string) $row['fit_type'],
				'min_load'         => null !== $row['min_load'] ? (int) $row['min_load'] : null,
				'min_speed_rank'   => null !== $row['min_speed_rank'] ? (int) $row['min_speed_rank'] : null,
				'req_construction' => null !== $row['req_construction'] ? (string) $row['req_construction'] : null,
				'source'           => (string) $row['source'],
				'note'             => (string) $row['note'],
			);
		}
		return $out;
	}

	/**
	 * Upserts a fitment row by (version_id, position, size_id).
	 *
	 * @param array{version_id:int,position:string,size_id:int,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,source:string,note:string} $data Fitment payload.
	 */
	public static function upsert_fitment( array $data ): int {
		global $wpdb;
		$f          = self::fitments_table();
		$version_id = (int) $data['version_id'];
		$position   = in_array( $data['position'], array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true ) ? $data['position'] : TireSpecs::POS_FRONT;
		$size_id    = (int) $data['size_id'];
		$fit_type   = TireSpecs::FIT_ALT === $data['fit_type'] ? TireSpecs::FIT_ALT : TireSpecs::FIT_OEM;
		$source     = in_array( $data['source'], array( 'manual', 'manufacturer', 'third_party' ), true ) ? $data['source'] : 'manual';
		$note       = substr( trim( $data['note'] ), 0, 190 );

		$min_load         = null !== $data['min_load'] && $data['min_load'] > 0 ? (int) $data['min_load'] : null;
		$min_speed_rank   = null !== $data['min_speed_rank'] && $data['min_speed_rank'] > 0 ? (int) $data['min_speed_rank'] : null;
		$req_construction = null !== $data['req_construction'] && '' !== $data['req_construction'] ? (string) $data['req_construction'] : null;

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$f} WHERE version_id = %d AND position = %s AND size_id = %d LIMIT 1",
				$version_id,
				$position,
				$size_id
			)
		);

		$load_sql  = null === $min_load ? 'NULL' : $wpdb->prepare( '%d', $min_load );
		$speed_sql = null === $min_speed_rank ? 'NULL' : $wpdb->prepare( '%d', $min_speed_rank );
		$const_sql = null === $req_construction ? 'NULL' : $wpdb->prepare( '%s', $req_construction );

		if ( $existing_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$f}
					SET fit_type = %s, min_load = {$load_sql}, min_speed_rank = {$speed_sql}, req_construction = {$const_sql}, source = %s, note = %s
					WHERE id = %d",
					$fit_type,
					$source,
					$note,
					$existing_id
				)
			);
			CompatCache::bump_catalog();
			return $existing_id;
		}

		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$f} (version_id, position, size_id, fit_type, min_load, min_speed_rank, req_construction, source, note)
				VALUES (%d, %s, %d, %s, {$load_sql}, {$speed_sql}, {$const_sql}, %s, %s)",
				$version_id,
				$position,
				$size_id,
				$fit_type,
				$source,
				$note
			)
		);
		if ( false === $ok ) {
			return 0;
		}
		CompatCache::bump_catalog();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a single fitment row.
	 *
	 * @param int $fitment_id Fitment ID.
	 */
	public static function delete_fitment( int $fitment_id ): bool {
		global $wpdb;
		$ok = false !== $wpdb->delete( self::fitments_table(), array( 'id' => $fitment_id ), array( '%d' ) );
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Lists approved directed equivalences originating from the given source size IDs.
	 *
	 * @param array<int,int> $source_size_ids Source size IDs.
	 * @param string         $position        'front' or 'rear'.
	 * @return list<array{source_size_id:int,source_canonical:string,target_size_id:int,target_canonical:string,is_approximate:int,note:string}>
	 */
	public static function equivalences_from_sizes( array $source_size_ids, string $position ): array {
		if ( empty( $source_size_ids ) ) {
			return array();
		}
		global $wpdb;
		$e      = self::equivalences_table();
		$s      = self::sizes_table();
		$ids_in = implode( ',', array_map( 'intval', $source_size_ids ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.source_size_id, src.canonical AS source_canonical, e.target_size_id, dst.canonical AS target_canonical, e.is_approximate, e.note
				FROM {$e} e
				INNER JOIN {$s} src ON src.id = e.source_size_id
				INNER JOIN {$s} dst ON dst.id = e.target_size_id
				WHERE e.source_size_id IN ({$ids_in})
					AND e.is_approved = 1
					AND e.position_scope IN ('any', %s)
				ORDER BY dst.canonical ASC",
				$position
			),
			ARRAY_A
		);
		$out    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'source_size_id'   => (int) $row['source_size_id'],
				'source_canonical' => (string) $row['source_canonical'],
				'target_size_id'   => (int) $row['target_size_id'],
				'target_canonical' => (string) $row['target_canonical'],
				'is_approximate'   => (int) $row['is_approximate'],
				'note'             => (string) $row['note'],
			);
		}
		return $out;
	}

	/**
	 * Lists approved directed equivalences targeting the given size ID (used in reverse product lookup).
	 *
	 * @param int    $target_size_id Target size ID.
	 * @param string $position       'front' or 'rear'.
	 * @return list<array{source_size_id:int,source_canonical:string,is_approximate:int}>
	 */
	public static function equivalences_to_size( int $target_size_id, string $position ): array {
		if ( $target_size_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$e    = self::equivalences_table();
		$s    = self::sizes_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.source_size_id, src.canonical AS source_canonical, e.is_approximate
				FROM {$e} e
				INNER JOIN {$s} src ON src.id = e.source_size_id
				WHERE e.target_size_id = %d
					AND e.is_approved = 1
					AND e.position_scope IN ('any', %s)",
				$target_size_id,
				$position
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'source_size_id'   => (int) $row['source_size_id'],
				'source_canonical' => (string) $row['source_canonical'],
				'is_approximate'   => (int) $row['is_approximate'],
			);
		}
		return $out;
	}

	/**
	 * Paginated equivalences list for admin.
	 *
	 * @param string $search   Canonical size filter.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public static function list_equivalences( string $search = '', int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$e      = self::equivalences_table();
		$s      = self::sizes_table();
		$where  = '';
		$params = array();
		if ( '' !== trim( $search ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
			$where    = 'WHERE src.canonical LIKE %s OR dst.canonical LIKE %s';
			$params[] = $like;
			$params[] = $like;
		}
		$total_sql = "SELECT COUNT(*) FROM {$e} e
			INNER JOIN {$s} src ON src.id = e.source_size_id
			INNER JOIN {$s} dst ON dst.id = e.target_size_id
			{$where}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = "SELECT e.id, e.source_size_id, src.canonical AS source_canonical, e.target_size_id, dst.canonical AS target_canonical,
				e.position_scope, e.is_approved, e.is_approximate, e.note
			FROM {$e} e
			INNER JOIN {$s} src ON src.id = e.source_size_id
			INNER JOIN {$s} dst ON dst.id = e.target_size_id
			{$where}
			ORDER BY src.canonical ASC, dst.canonical ASC
			LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Upserts a directed equivalence (and optionally its inverse rule).
	 *
	 * @param array{source_size_id:int,target_size_id:int,position_scope:string,is_approved:int,is_approximate:int,note:string} $data          Equivalence fields.
	 * @param bool                                                                                                              $create_inverse Whether to create target -> source as well.
	 */
	public static function upsert_equivalence( array $data, bool $create_inverse = false ): int {
		$id = self::upsert_single_equivalence( $data );
		if ( $id > 0 && $create_inverse && $data['source_size_id'] !== $data['target_size_id'] ) {
			$inv                   = $data;
			$inv['source_size_id'] = $data['target_size_id'];
			$inv['target_size_id'] = $data['source_size_id'];
			self::upsert_single_equivalence( $inv );
		}
		return $id;
	}

	/**
	 * Deletes an equivalence row.
	 *
	 * @param int $id Equivalence ID.
	 */
	public static function delete_equivalence( int $id ): bool {
		global $wpdb;
		$ok = false !== $wpdb->delete( self::equivalences_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $ok ) {
			CompatCache::bump_catalog();
		}
		return $ok;
	}

	/**
	 * Exports full catalog snapshot for JSON export.
	 *
	 * @return array{brands:list<array<string,mixed>>,models:list<array<string,mixed>>,versions:list<array<string,mixed>>,fitments:list<array<string,mixed>>,equivalences:list<array<string,mixed>>}
	 */
	public static function export_all(): array {
		global $wpdb;
		$b = self::brands_table();
		$m = self::models_table();
		$v = self::versions_table();
		$f = self::fitments_table();
		$s = self::sizes_table();
		$e = self::equivalences_table();

		$brands   = $wpdb->get_results( "SELECT id, name, slug FROM {$b} ORDER BY name ASC", ARRAY_A );
		$models   = $wpdb->get_results( "SELECT id, brand_id, name, slug, cc, aliases FROM {$m} ORDER BY name ASC", ARRAY_A );
		$versions = $wpdb->get_results( "SELECT id, model_id, slug, year_from, year_to, variant, status, verified, notes FROM {$v} ORDER BY year_from ASC", ARRAY_A );
		$fitments = $wpdb->get_results(
			"SELECT f.version_id, f.position, s.canonical, f.fit_type, f.min_load, f.min_speed_rank, f.req_construction, f.source, f.note
			FROM {$f} f
			INNER JOIN {$s} s ON s.id = f.size_id
			ORDER BY f.version_id ASC, f.position ASC, f.fit_type DESC",
			ARRAY_A
		);
		$equivs   = $wpdb->get_results(
			"SELECT src.canonical AS source, dst.canonical AS target, e.position_scope, e.is_approved, e.is_approximate, e.note
			FROM {$e} e
			INNER JOIN {$s} src ON src.id = e.source_size_id
			INNER JOIN {$s} dst ON dst.id = e.target_size_id
			ORDER BY src.canonical ASC, dst.canonical ASC",
			ARRAY_A
		);

		return array(
			'brands'       => is_array( $brands ) ? $brands : array(),
			'models'       => is_array( $models ) ? $models : array(),
			'versions'     => is_array( $versions ) ? $versions : array(),
			'fitments'     => is_array( $fitments ) ? $fitments : array(),
			'equivalences' => is_array( $equivs ) ? $equivs : array(),
		);
	}

	/**
	 * Inserts or updates a single directed equivalence row.
	 *
	 * @param array{source_size_id:int,target_size_id:int,position_scope:string,is_approved:int,is_approximate:int,note:string} $data Equivalence fields.
	 */
	private static function upsert_single_equivalence( array $data ): int {
		global $wpdb;
		$src   = (int) $data['source_size_id'];
		$dst   = (int) $data['target_size_id'];
		$scope = in_array( $data['position_scope'], array( 'any', TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true ) ? $data['position_scope'] : 'any';
		if ( $src <= 0 || $dst <= 0 || $src === $dst ) {
			return 0;
		}
		$e        = self::equivalences_table();
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$e} WHERE source_size_id = %d AND target_size_id = %d AND position_scope = %s LIMIT 1",
				$src,
				$dst,
				$scope
			)
		);
		$approved = ! empty( $data['is_approved'] ) ? 1 : 0;
		$approx   = ! empty( $data['is_approximate'] ) ? 1 : 0;
		$note     = substr( trim( $data['note'] ), 0, 190 );

		if ( $existing > 0 ) {
			$wpdb->update(
				$e,
				array(
					'is_approved'    => $approved,
					'is_approximate' => $approx,
					'note'           => $note,
				),
				array( 'id' => $existing ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
			CompatCache::bump_catalog();
			return $existing;
		}

		$ok = $wpdb->insert(
			$e,
			array(
				'source_size_id' => $src,
				'target_size_id' => $dst,
				'position_scope' => $scope,
				'is_approved'    => $approved,
				'is_approximate' => $approx,
				'note'           => $note,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s' )
		);
		if ( false === $ok ) {
			return 0;
		}
		CompatCache::bump_catalog();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ensures generated version slug is unique in compat_versions.
	 *
	 * @param string $base_slug Base slug.
	 */
	private static function unique_version_slug( string $base_slug ): string {
		global $wpdb;
		$v      = self::versions_table();
		$slug   = '' !== $base_slug ? $base_slug : 'moto';
		$suffix = 1;
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE slug = %s", $slug ) ) > 0 ) {
			++$suffix;
			$slug = substr( $base_slug, 0, 175 ) . '-' . $suffix;
		}
		return $slug;
	}

	/**
	 * Maps raw brand DB rows.
	 *
	 * @param list<array<string,mixed>> $rows DB rows.
	 * @return list<array{id:int,name:string,slug:string}>
	 */
	private static function map_brands( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'   => (int) $row['id'],
				'name' => (string) $row['name'],
				'slug' => (string) $row['slug'],
			);
		}
		return $out;
	}
}
