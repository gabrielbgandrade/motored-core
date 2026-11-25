<?php
/**
 * Catalog readiness scorecard (B4): tire indexing, size-attribute completeness and per-bike stock coverage.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table() / $wpdb; scalar inputs are bound with $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Computes readiness KPIs and worst-covered published motorcycles for Productos > Compatibilidad.
 */
final class ReadinessReport {

	/**
	 * Returns readiness metrics and up to 20 published motorcycles missing front or rear in-stock coverage.
	 *
	 * @param int $offender_limit Maximum motorcycles returned in `offenders`.
	 * @return array{
	 *   published_tires:int,
	 *   indexed_tires:int,
	 *   indexed_pct:int,
	 *   unit_tires:int,
	 *   unit_complete_attrs:int,
	 *   attrs_pct:int,
	 *   published_versions:int,
	 *   covered_versions:int,
	 *   coverage_pct:int,
	 *   offenders:list<array{id:int,label:string,front_count:int,rear_count:int,kit_count:int}>
	 * }
	 */
	public static function summary( int $offender_limit = 20 ): array {
		global $wpdb;
		$empty = array(
			'published_tires'     => 0,
			'indexed_tires'       => 0,
			'indexed_pct'         => 0,
			'unit_tires'          => 0,
			'unit_complete_attrs' => 0,
			'attrs_pct'           => 0,
			'published_versions'  => 0,
			'covered_versions'    => 0,
			'coverage_pct'        => 0,
			'offenders'           => array(),
		);
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $empty;
		}

		$posts = $wpdb->posts;
		$idx   = ProductIndexRepository::table();
		$tr    = $wpdb->term_relationships;
		$tt    = $wpdb->term_taxonomy;

		$tire_taxes = array(
			'pa_' . TireSpecs::ATTR_POSITION,
			'pa_' . TireSpecs::ATTR_FRONT_SIZE,
			'pa_' . TireSpecs::ATTR_REAR_SIZE,
			'pa_' . TireSpecs::ATTR_WIDTH,
			'pa_' . TireSpecs::ATTR_RIM,
		);
		$tax_in     = "'" . implode( "','", array_map( 'esc_sql', $tire_taxes ) ) . "'";

		$published_tires = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$posts} p
			LEFT JOIN {$idx} i ON i.product_id = p.ID
			LEFT JOIN {$tr} tr ON tr.object_id = p.ID
			LEFT JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ({$tax_in})
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_parent = 0
				AND (i.product_id IS NOT NULL OR tt.term_taxonomy_id IS NOT NULL)"
		);

		$indexed_tires = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$idx} i
			INNER JOIN {$posts} p ON p.ID = i.product_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'"
		);

		$unit_tires = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$idx} i
			INNER JOIN {$posts} p ON p.ID = i.product_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
				AND i.position IN ('front', 'rear', 'both')"
		);

		$w_tax               = esc_sql( 'pa_' . TireSpecs::ATTR_WIDTH );
		$r_tax               = esc_sql( 'pa_' . TireSpecs::ATTR_RIM );
		$unit_complete_attrs = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$idx} i
			INNER JOIN {$posts} p ON p.ID = i.product_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
				AND i.position IN ('front', 'rear', 'both')
				AND EXISTS (
					SELECT 1 FROM {$tr} trw
					INNER JOIN {$tt} ttw ON ttw.term_taxonomy_id = trw.term_taxonomy_id AND ttw.taxonomy = '{$w_tax}'
					WHERE trw.object_id = i.product_id
				)
				AND EXISTS (
					SELECT 1 FROM {$tr} trr
					INNER JOIN {$tt} ttr ON ttr.term_taxonomy_id = trr.term_taxonomy_id AND ttr.taxonomy = '{$r_tax}'
					WHERE trr.object_id = i.product_id
				)"
		);

		$cov = self::bike_coverage( $offender_limit );

		return array(
			'published_tires'     => $published_tires,
			'indexed_tires'       => $indexed_tires,
			'indexed_pct'         => self::pct( $indexed_tires, $published_tires ),
			'unit_tires'          => $unit_tires,
			'unit_complete_attrs' => $unit_complete_attrs,
			'attrs_pct'           => self::pct( $unit_complete_attrs, $unit_tires ),
			'published_versions'  => $cov['published_versions'],
			'covered_versions'    => $cov['covered_versions'],
			'coverage_pct'        => self::pct( $cov['covered_versions'], $cov['published_versions'] ),
			'offenders'           => $cov['offenders'],
		);
	}

	/**
	 * Computes per-bike in-stock front/rear/kit counts across published motorcycles using bulk queries.
	 *
	 * @param int $offender_limit Max worst offenders to return.
	 * @return array{published_versions:int,covered_versions:int,offenders:list<array{id:int,label:string,front_count:int,rear_count:int,kit_count:int}>}
	 */
	private static function bike_coverage( int $offender_limit ): array {
		global $wpdb;
		$v     = CatalogRepository::versions_table();
		$m     = CatalogRepository::models_table();
		$b     = CatalogRepository::brands_table();
		$idx   = ProductIndexRepository::table();
		$posts = $wpdb->posts;

		$versions = $wpdb->get_results(
			"SELECT v.id, v.year_from, v.year_to, v.variant, m.name AS model_name, b.name AS brand_name
			FROM {$v} v
			INNER JOIN {$m} m ON m.id = v.model_id
			INNER JOIN {$b} b ON b.id = m.brand_id
			WHERE v.status = 'published'
			ORDER BY b.name ASC, m.name ASC, v.year_from DESC",
			ARRAY_A
		);
		if ( ! is_array( $versions ) || empty( $versions ) ) {
			return array(
				'published_versions' => 0,
				'covered_versions'   => 0,
				'offenders'          => array(),
			);
		}

		$ver_ids         = array_map( static fn( array $r ): int => (int) $r['id'], $versions );
		$fitments_by_ver = CatalogRepository::fitments_for_versions_full( $ver_ids );

		$oem_size_ids = array();
		foreach ( $fitments_by_ver as $rows ) {
			foreach ( $rows as $row ) {
				if ( TireSpecs::FIT_OEM === (string) $row['fit_type'] ) {
					$oem_size_ids[] = (int) $row['size_id'];
				}
			}
		}
		$equiv_map = array();
		if ( ! empty( $oem_size_ids ) ) {
			foreach ( CatalogRepository::equivalences_from_sizes_batch( $oem_size_ids ) as $eq ) {
				$src                           = (int) $eq['source_size_id'];
				$dst                           = (int) $eq['target_size_id'];
				$scope                         = (string) $eq['position_scope'];
				$equiv_map[ $src ][ $scope ][] = $dst;
			}
		}

		$stock_rows    = $wpdb->get_results(
			"SELECT i.product_id, i.position, i.front_size_id, i.rear_size_id
			FROM {$idx} i
			INNER JOIN {$posts} p ON p.ID = i.product_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND i.in_stock = 1",
			ARRAY_A
		);
		$front_by_size = array();
		$rear_by_size  = array();
		$kit_by_pair   = array();
		foreach ( is_array( $stock_rows ) ? $stock_rows : array() as $sr ) {
			$pid = (int) $sr['product_id'];
			$pos = (string) $sr['position'];
			$fs  = null !== $sr['front_size_id'] ? (int) $sr['front_size_id'] : 0;
			$rs  = null !== $sr['rear_size_id'] ? (int) $sr['rear_size_id'] : 0;
			if ( ( TireSpecs::POS_FRONT === $pos || TireSpecs::POS_BOTH === $pos ) && $fs > 0 ) {
				$front_by_size[ $fs ][ $pid ] = true;
			}
			if ( ( TireSpecs::POS_REAR === $pos || TireSpecs::POS_BOTH === $pos ) && $rs > 0 ) {
				$rear_by_size[ $rs ][ $pid ] = true;
			}
			if ( TireSpecs::POS_KIT === $pos && $fs > 0 && $rs > 0 ) {
				$kit_by_pair[ $fs . ':' . $rs ][ $pid ] = true;
			}
		}

		$covered   = 0;
		$offenders = array();
		foreach ( $versions as $ver ) {
			$vid        = (int) $ver['id'];
			$front_sids = array();
			$rear_sids  = array();
			foreach ( $fitments_by_ver[ $vid ] ?? array() as $fit ) {
				$sid = (int) $fit['size_id'];
				$pos = (string) $fit['position'];
				if ( TireSpecs::POS_FRONT === $pos ) {
					$front_sids[ $sid ] = true;
					if ( TireSpecs::FIT_OEM === (string) $fit['fit_type'] ) {
						foreach ( array_merge( $equiv_map[ $sid ]['any'] ?? array(), $equiv_map[ $sid ]['front'] ?? array() ) as $target_id ) {
							$front_sids[ $target_id ] = true;
						}
					}
				} elseif ( TireSpecs::POS_REAR === $pos ) {
					$rear_sids[ $sid ] = true;
					if ( TireSpecs::FIT_OEM === (string) $fit['fit_type'] ) {
						foreach ( array_merge( $equiv_map[ $sid ]['any'] ?? array(), $equiv_map[ $sid ]['rear'] ?? array() ) as $target_id ) {
							$rear_sids[ $target_id ] = true;
						}
					}
				}
			}

			$front_pids = array();
			foreach ( array_keys( $front_sids ) as $sid ) {
				$front_pids += $front_by_size[ $sid ] ?? array();
			}
			$rear_pids = array();
			foreach ( array_keys( $rear_sids ) as $sid ) {
				$rear_pids += $rear_by_size[ $sid ] ?? array();
			}
			$kit_pids = array();
			foreach ( array_keys( $front_sids ) as $fs ) {
				foreach ( array_keys( $rear_sids ) as $rs ) {
					$kit_pids += $kit_by_pair[ $fs . ':' . $rs ] ?? array();
				}
			}

			$fc = count( $front_pids );
			$rc = count( $rear_pids );
			$kc = count( $kit_pids );
			if ( $fc > 0 || $rc > 0 || $kc > 0 ) {
				++$covered;
			}
			if ( 0 === $fc || 0 === $rc ) {
				$offenders[] = array(
					'id'          => $vid,
					'label'       => MatchEngine::version_label(
						(string) $ver['brand_name'],
						(string) $ver['model_name'],
						(string) $ver['variant'],
						(int) $ver['year_from'],
						(int) $ver['year_to']
					),
					'front_count' => $fc,
					'rear_count'  => $rc,
					'kit_count'   => $kc,
				);
			}
		}

		usort(
			$offenders,
			static function ( array $a, array $b ): int {
				$sa = ( 0 === $a['front_count'] && 0 === $a['rear_count'] && 0 === $a['kit_count'] ) ? 0 : 1;
				$sb = ( 0 === $b['front_count'] && 0 === $b['rear_count'] && 0 === $b['kit_count'] ) ? 0 : 1;
				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}
				return ( $a['front_count'] + $a['rear_count'] ) <=> ( $b['front_count'] + $b['rear_count'] );
			}
		);

		return array(
			'published_versions' => count( $versions ),
			'covered_versions'   => $covered,
			'offenders'          => array_slice( $offenders, 0, max( 1, $offender_limit ) ),
		);
	}

	/**
	 * Rounded percentage helper (0 when total is 0).
	 *
	 * @param int $part  Numerator.
	 * @param int $total Denominator.
	 */
	private static function pct( int $part, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) min( 100, round( ( $part / $total ) * 100 ) );
	}
}
