<?php
/**
 * Data access for motored_compat_product_index and reverse motorcycle queries.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table() (validated); all values are bound with $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Fast product-side index reads/writes (product_id PK; simple products only).
 */
final class ProductIndexRepository {

	/**
	 * Index table name.
	 */
	public static function table(): string {
		return Db::table( 'compat_product_index' );
	}

	/**
	 * Counts all indexed tire products.
	 */
	public static function count_indexed(): int {
		global $wpdb;
		$idx = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx}" );
	}

	/**
	 * Reads a single product's index row joined with canonical sizes.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $product_id ): ?array {
		$rows = self::find_many( array( $product_id ) );
		return $rows[ $product_id ] ?? null;
	}

	/**
	 * Batch-loads index rows for multiple product IDs in a single query (zero N+1 in shop loops).
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 * @return array<int,array<string,mixed>> Keyed by product_id.
	 */
	public static function find_many( array $product_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$idx = self::table();
		$s   = CatalogRepository::sizes_table();
		$in  = implode( ',', $ids );

		$rows = $wpdb->get_results(
			"SELECT i.product_id, i.position, i.front_size_id, fs.canonical AS front_canonical,
				i.rear_size_id, rs.canonical AS rear_canonical,
				i.front_load, i.rear_load, i.front_speed_rank, i.rear_speed_rank, i.construction, i.front_construction, i.rear_construction, i.in_stock, i.terms_hash
			FROM {$idx} i
			LEFT JOIN {$s} fs ON fs.id = i.front_size_id
			LEFT JOIN {$s} rs ON rs.id = i.rear_size_id
			WHERE i.product_id IN ({$in})",
			ARRAY_A
		);

		$map = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pid         = (int) $row['product_id'];
			$map[ $pid ] = array(
				'product_id'         => $pid,
				'position'           => (string) $row['position'],
				'front_size_id'      => null !== $row['front_size_id'] ? (int) $row['front_size_id'] : null,
				'front_canonical'    => null !== $row['front_canonical'] ? (string) $row['front_canonical'] : null,
				'rear_size_id'       => null !== $row['rear_size_id'] ? (int) $row['rear_size_id'] : null,
				'rear_canonical'     => null !== $row['rear_canonical'] ? (string) $row['rear_canonical'] : null,
				'front_load'         => null !== $row['front_load'] ? (int) $row['front_load'] : null,
				'rear_load'          => null !== $row['rear_load'] ? (int) $row['rear_load'] : null,
				'front_speed_rank'   => null !== $row['front_speed_rank'] ? (int) $row['front_speed_rank'] : null,
				'rear_speed_rank'    => null !== $row['rear_speed_rank'] ? (int) $row['rear_speed_rank'] : null,
				'construction'       => null !== $row['construction'] ? (string) $row['construction'] : null,
				'front_construction' => isset( $row['front_construction'] ) ? (string) $row['front_construction'] : null,
				'rear_construction'  => isset( $row['rear_construction'] ) ? (string) $row['rear_construction'] : null,
				'in_stock'           => isset( $row['in_stock'] ) ? ( 0 !== (int) $row['in_stock'] ) : true,
				'terms_hash'         => (string) $row['terms_hash'],
			);
		}
		return $map;
	}

	/**
	 * Reads only the stored terms_hash for fast no-op checks during ERP price/stock saves.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function get_hash( int $product_id ): string {
		global $wpdb;
		$idx = self::table();
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT terms_hash FROM {$idx} WHERE product_id = %d LIMIT 1", $product_id ) );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Inserts or replaces a product index row.
	 *
	 * @param array{product_id:int,position:string,front_size_id:int|null,rear_size_id:int|null,front_load:int|null,rear_load:int|null,front_speed_rank:int|null,rear_speed_rank:int|null,construction:string|null,front_construction?:string|null,rear_construction?:string|null,in_stock?:int,terms_hash:string} $row Index row.
	 */
	public static function upsert( array $row ): bool {
		global $wpdb;
		$idx      = self::table();
		$pid      = (int) $row['product_id'];
		$pos      = (string) $row['position'];
		$fs       = null === $row['front_size_id'] ? 'NULL' : $wpdb->prepare( '%d', $row['front_size_id'] );
		$rs       = null === $row['rear_size_id'] ? 'NULL' : $wpdb->prepare( '%d', $row['rear_size_id'] );
		$fl       = null === $row['front_load'] ? 'NULL' : $wpdb->prepare( '%d', $row['front_load'] );
		$rl       = null === $row['rear_load'] ? 'NULL' : $wpdb->prepare( '%d', $row['rear_load'] );
		$fsr      = null === $row['front_speed_rank'] ? 'NULL' : $wpdb->prepare( '%d', $row['front_speed_rank'] );
		$rsr      = null === $row['rear_speed_rank'] ? 'NULL' : $wpdb->prepare( '%d', $row['rear_speed_rank'] );
		$const    = null === $row['construction'] ? 'NULL' : $wpdb->prepare( '%s', $row['construction'] );
		$f_const  = empty( $row['front_construction'] ) ? 'NULL' : $wpdb->prepare( '%s', $row['front_construction'] );
		$r_const  = empty( $row['rear_construction'] ) ? 'NULL' : $wpdb->prepare( '%s', $row['rear_construction'] );
		$in_stock = isset( $row['in_stock'] ) ? ( 0 !== (int) $row['in_stock'] ? 1 : 0 ) : 1;
		$hash     = (string) $row['terms_hash'];
		$now      = gmdate( 'Y-m-d H:i:s' );

		$ok = $wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$idx}
					(product_id, position, front_size_id, rear_size_id, front_load, rear_load, front_speed_rank, rear_speed_rank, construction, front_construction, rear_construction, in_stock, terms_hash, updated_gmt)
				VALUES (%d, %s, {$fs}, {$rs}, {$fl}, {$rl}, {$fsr}, {$rsr}, {$const}, {$f_const}, {$r_const}, %d, %s, %s)",
				$pid,
				$pos,
				$in_stock,
				$hash,
				$now
			)
		);
		if ( false !== $ok ) {
			CompatCache::bump_matches();
			if ( function_exists( 'do_action' ) ) {
				do_action( 'motored_compat_product_index_changed', $pid );
			}
			return true;
		}
		return false;
	}

	/**
	 * Updates only the in_stock column when the product's stock status actually changed.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $in_stock   Whether the product is currently in stock (instock / onbackorder).
	 */
	public static function update_stock_status( int $product_id, bool $in_stock ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$idx     = self::table();
		$flag    = $in_stock ? 1 : 0;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$idx} SET in_stock = %d, updated_gmt = %s WHERE product_id = %d AND in_stock != %d",
				$flag,
				$now,
				$product_id,
				$flag
			)
		);
		if ( is_int( $updated ) && $updated > 0 ) {
			CompatCache::bump_matches();
			if ( function_exists( 'do_action' ) ) {
				do_action( 'motored_compat_product_index_changed', $product_id );
			}
			return true;
		}
		return false;
	}

	/**
	 * Deletes a product from the compatibility index.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function delete( int $product_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( self::table(), array( 'product_id' => $product_id ), array( '%d' ) );
		if ( is_int( $deleted ) && $deleted > 0 ) {
			CompatCache::bump_matches();
			if ( function_exists( 'do_action' ) ) {
				do_action( 'motored_compat_product_index_changed', $product_id );
			}
		}
		return false !== $deleted;
	}

	/**
	 * Queries candidate product rows matching front/rear accepted size IDs (indexed lookup, no term joins).
	 *
	 * @param array<int,int> $front_size_ids Accepted front size IDs.
	 * @param array<int,int> $rear_size_ids  Accepted rear size IDs.
	 * @param string         $pos_filter     Optional filter: '' | 'front' | 'rear' | 'kit'.
	 * @return list<array<string,mixed>>
	 */
	public static function query_candidates_for_sizes( array $front_size_ids, array $rear_size_ids, string $pos_filter = '' ): array {
		global $wpdb;
		$idx    = self::table();
		$s      = CatalogRepository::sizes_table();
		$f_ids  = array_values( array_unique( array_filter( array_map( 'intval', $front_size_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$r_ids  = array_values( array_unique( array_filter( array_map( 'intval', $rear_size_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$select = "SELECT i.product_id, i.position, i.front_size_id, fs.canonical AS front_canonical,
				i.rear_size_id, rs.canonical AS rear_canonical,
				i.front_load, i.rear_load, i.front_speed_rank, i.rear_speed_rank, i.construction, i.front_construction, i.rear_construction
			FROM {$idx} i
			LEFT JOIN {$s} fs ON fs.id = i.front_size_id
			LEFT JOIN {$s} rs ON rs.id = i.rear_size_id";

		$parts = array();

		if ( ( '' === $pos_filter || TireSpecs::POS_FRONT === $pos_filter ) && ! empty( $f_ids ) ) {
			$f_in    = implode( ',', $f_ids );
			$parts[] = "{$select} WHERE i.position IN ('front', 'both') AND i.front_size_id IN ({$f_in})";
		}
		if ( ( '' === $pos_filter || TireSpecs::POS_REAR === $pos_filter ) && ! empty( $r_ids ) ) {
			$r_in    = implode( ',', $r_ids );
			$parts[] = "{$select} WHERE i.position IN ('rear', 'both') AND i.rear_size_id IN ({$r_in})";
		}
		if ( ( '' === $pos_filter || TireSpecs::POS_KIT === $pos_filter ) && ! empty( $f_ids ) && ! empty( $r_ids ) ) {
			$f_in    = implode( ',', $f_ids );
			$r_in    = implode( ',', $r_ids );
			$parts[] = "{$select} WHERE i.position = 'kit' AND i.front_size_id IN ({$f_in}) AND i.rear_size_id IN ({$r_in})";
		}

		if ( empty( $parts ) ) {
			return array();
		}

		$sql  = implode( ' UNION ALL ', $parts );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$deduped = array();
		foreach ( $rows as $row ) {
			$pid             = (int) $row['product_id'];
			$deduped[ $pid ] = array(
				'product_id'         => $pid,
				'position'           => (string) $row['position'],
				'front_size_id'      => null !== $row['front_size_id'] ? (int) $row['front_size_id'] : null,
				'front_canonical'    => null !== $row['front_canonical'] ? (string) $row['front_canonical'] : null,
				'rear_size_id'       => null !== $row['rear_size_id'] ? (int) $row['rear_size_id'] : null,
				'rear_canonical'     => null !== $row['rear_canonical'] ? (string) $row['rear_canonical'] : null,
				'front_load'         => null !== $row['front_load'] ? (int) $row['front_load'] : null,
				'rear_load'          => null !== $row['rear_load'] ? (int) $row['rear_load'] : null,
				'front_speed_rank'   => null !== $row['front_speed_rank'] ? (int) $row['front_speed_rank'] : null,
				'rear_speed_rank'    => null !== $row['rear_speed_rank'] ? (int) $row['rear_speed_rank'] : null,
				'construction'       => null !== $row['construction'] ? (string) $row['construction'] : null,
				'front_construction' => isset( $row['front_construction'] ) ? (string) $row['front_construction'] : null,
				'rear_construction'  => isset( $row['rear_construction'] ) ? (string) $row['rear_construction'] : null,
			);
		}
		return array_values( $deduped );
	}

	/**
	 * Queries published motorcycle versions + fitments accepting a given size ID on an axle (including 1-hop equivalences).
	 *
	 * @param int    $target_size_id Size ID of the tire.
	 * @param string $position       'front' or 'rear'.
	 * @return list<array<string,mixed>>
	 */
	public static function query_version_fitments_for_tire_size( int $target_size_id, string $position ): array {
		if ( $target_size_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$f = CatalogRepository::fitments_table();
		$v = CatalogRepository::versions_table();
		$m = CatalogRepository::models_table();
		$b = CatalogRepository::brands_table();

		// Direct OEM or ALT fitments on this axle.
		$direct_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.id AS version_id, v.slug AS version_slug, v.year_from, v.year_to, v.variant,
					m.id AS model_id, m.name AS model_name, m.aliases,
					b.id AS brand_id, b.name AS brand_name,
					f.fit_type, f.min_load, f.min_speed_rank, f.req_construction, '0' AS is_equiv, '' AS equiv_from
				FROM {$f} f
				INNER JOIN {$v} v ON v.id = f.version_id AND v.status = 'published'
				INNER JOIN {$m} m ON m.id = v.model_id
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE f.size_id = %d AND f.position = %s",
				$target_size_id,
				$position
			),
			ARRAY_A
		);

		$rows = is_array( $direct_rows ) ? $direct_rows : array();

		// 1-hop equivalences originating from OEM fitments.
		$equivs = CatalogRepository::equivalences_to_size( $target_size_id, $position );
		if ( ! empty( $equivs ) ) {
			$src_map = array();
			foreach ( $equivs as $eq ) {
				$id = $eq['source_size_id'];
				if ( ! isset( $src_map[ $id ] ) || $eq['is_approximate'] < $src_map[ $id ]['is_approximate'] ) {
					$src_map[ $id ] = $eq;
				}
			}
			$src_in     = implode( ',', array_map( 'intval', array_keys( $src_map ) ) );
			$equiv_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT v.id AS version_id, v.slug AS version_slug, v.year_from, v.year_to, v.variant,
						m.id AS model_id, m.name AS model_name, m.aliases,
						b.id AS brand_id, b.name AS brand_name,
						f.size_id, f.fit_type, f.min_load, f.min_speed_rank, f.req_construction
					FROM {$f} f
					INNER JOIN {$v} v ON v.id = f.version_id AND v.status = 'published'
					INNER JOIN {$m} m ON m.id = v.model_id
					INNER JOIN {$b} b ON b.id = m.brand_id
					WHERE f.size_id IN ({$src_in}) AND f.position = %s AND f.fit_type = 'oem'",
					$position
				),
				ARRAY_A
			);
			foreach ( is_array( $equiv_rows ) ? $equiv_rows : array() as $row ) {
				$sid               = (int) $row['size_id'];
				$row['fit_type']   = TireSpecs::FIT_EQUIV;
				$row['is_equiv']   = '1';
				$row['equiv_from'] = $src_map[ $sid ]['source_canonical'];
				$row['approx']     = $src_map[ $sid ]['is_approximate'];
				$rows[]            = $row;
			}
		}

		return $rows;
	}

	/**
	 * Finds published products that have a tire-position attribute or size term but failed indexing / lack valid size.
	 *
	 * @param int $limit  Batch limit.
	 * @param int $offset Offset.
	 * @return array{items:list<array{product_id:int,title:string,reason:string}>,total:int}
	 */
	public static function find_products_with_invalid_sizes( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$idx   = self::table();
		$posts = $wpdb->posts;
		$tr    = $wpdb->term_relationships;
		$tt    = $wpdb->term_taxonomy;
		$t     = $wpdb->terms;

		$tax_in = "'" . implode(
			"','",
			array_map(
				'esc_sql',
				array(
					'pa_' . TireSpecs::ATTR_POSITION,
					'pa_' . TireSpecs::ATTR_FRONT_SIZE,
					'pa_' . TireSpecs::ATTR_REAR_SIZE,
				)
			)
		) . "'";

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$posts} p
			INNER JOIN {$tr} tr ON tr.object_id = p.ID
			INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ({$tax_in})
			LEFT JOIN {$idx} i ON i.product_id = p.ID
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND i.product_id IS NULL"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS product_id, p.post_title AS title,
					GROUP_CONCAT(DISTINCT CONCAT(tt.taxonomy, ':', t.name) SEPARATOR ' | ') AS raw_terms
				FROM {$posts} p
				INNER JOIN {$tr} tr ON tr.object_id = p.ID
				INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ({$tax_in})
				INNER JOIN {$t} t ON t.term_id = tt.term_id
				LEFT JOIN {$idx} i ON i.product_id = p.ID
				WHERE p.post_type = 'product' AND p.post_status = 'publish' AND i.product_id IS NULL
				GROUP BY p.ID, p.post_title
				ORDER BY p.ID DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'product_id' => (int) $row['product_id'],
				'title'      => (string) $row['title'],
				'reason'     => (string) $row['raw_terms'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Kits whose front OR rear tire has the given canonical size (Catalog Filters "kits with this size").
	 *
	 * @param string $canonical Canonical size (e.g. "130/70-17").
	 * @return array<int,bool> Product ID => in stock.
	 */
	public static function kit_ids_with_size( string $canonical ): array {
		$size = CatalogRepository::find_size_by_canonical( $canonical );
		if ( null === $size ) {
			return array();
		}
		global $wpdb;
		$idx  = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, in_stock FROM {$idx} WHERE position = 'kit' AND ( front_size_id = %d OR rear_size_id = %d )",
				$size['id'],
				$size['id']
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['product_id'] ] = 0 !== (int) $row['in_stock'];
		}
		return $out;
	}

	/**
	 * Indexed kits without any per-axle rating (load, speed or construction): filled in before the kit sheet existed.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array{items:list<array{product_id:int,title:string}>,total:int}
	 */
	public static function kits_without_axle_sheet( int $limit = 30, int $offset = 0 ): array {
		global $wpdb;
		$idx   = self::table();
		$where = "i.position = 'kit' AND i.front_load IS NULL AND i.rear_load IS NULL AND i.front_speed_rank IS NULL
			AND i.rear_speed_rank IS NULL AND i.front_construction IS NULL AND i.rear_construction IS NULL";
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx} i WHERE {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.product_id, p.post_title AS title FROM {$idx} i
				INNER JOIN {$wpdb->posts} p ON p.ID = i.product_id
				WHERE {$where}
				ORDER BY i.product_id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'product_id' => (int) $row['product_id'],
				'title'      => (string) $row['title'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
