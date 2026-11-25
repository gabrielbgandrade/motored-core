<?php
/**
 * Compact catalog facet snapshot, disjunctive counter and versioned size-cascade generator.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Computes exact disjunctive facet counts for all 11 filter groups in O(N) time over parent products.
 * Uses Support\Cache ONLY when persistent object cache (Redis) is active; rebuilds in 1 SQL query on miss.
 */
final class FacetEngine {

	public const SNAPSHOT_KEY        = 'facet_snapshot_v1';
	public const SNAPSHOT_SEARCH_KEY = 'facet_snapshot_search_v1';
	public const SNAPSHOT_TTL        = 21600; // 6 hours.

	/**
	 * Per-request memoized catalog snapshot so multiple widgets/shortcodes share one read.
	 *
	 * @var array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}|null
	 */
	private static ?array $memo_snapshot = null;

	/**
	 * Per-request memoized search snapshot.
	 *
	 * @var array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}|null
	 */
	private static ?array $memo_search_snapshot = null;

	/**
	 * Returns the compact product facet snapshot (per-request memo -> Redis when persistent -> 1 SQL query).
	 *
	 * @param bool $search Whether to return the search-visibility snapshot instead of the catalog-visibility snapshot.
	 * @return array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}
	 * @throws \Throwable Rethrows builder errors when no cached snapshot is available.
	 */
	public static function snapshot( bool $search = false ): array {
		$memo = $search ? self::$memo_search_snapshot : self::$memo_snapshot;
		if ( null !== $memo ) {
			return $memo;
		}

		$set_memo = static function ( array $snap ) use ( $search ): array {
			if ( $search ) {
				self::$memo_search_snapshot = $snap;
			} else {
				self::$memo_snapshot = $snap;
			}
			return $snap;
		};

		if ( ! Cache::is_persistent() ) {
			return $set_memo( self::build_snapshot_from_db( $search ) );
		}

		$cache_key = $search ? self::SNAPSHOT_SEARCH_KEY : self::SNAPSHOT_KEY;
		$variant   = $search ? 'search' : 'catalog';
		$found     = false;
		$cached    = Cache::get( $cache_key, SnapshotInvalidator::BUCKET, $found );
		$valid     = $found && is_array( $cached ) && isset( $cached['products'], $cached['labels'] );

		if ( $valid && ! SnapshotInvalidator::should_rebuild_now( $variant ) ) {
			return $set_memo( $cached );
		}

		if ( SnapshotInvalidator::acquire_lock( $variant ) ) {
			$generation = SnapshotInvalidator::generation();
			try {
				$fresh  = self::build_snapshot_from_db( $search );
				$stored = Cache::set( $cache_key, $fresh, self::SNAPSHOT_TTL, SnapshotInvalidator::BUCKET );
				SnapshotInvalidator::finish_rebuild( $stored, $generation, $variant );
				return $set_memo( $fresh );
			} catch ( \Throwable $e ) {
				SnapshotInvalidator::finish_rebuild( false, null, $variant );
				if ( $valid ) {
					return $set_memo( $cached );
				}
				throw $e;
			}
		}

		if ( $valid ) {
			return $set_memo( $cached );
		}

		return $set_memo( self::build_snapshot_from_db( $search ) );
	}

	/**
	 * Builds the compact snapshot from WordPress tables in ONE SQL query (parent products only, visible in catalog).
	 *
	 * Never reads price or on_sale (on_sale is resolved per request via wc_get_product_ids_on_sale()).
	 *
	 * @param bool $search Whether this snapshot is for product search visibility.
	 * @return array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}
	 * @throws \RuntimeException When the snapshot query fails.
	 */
	public static function build_snapshot_from_db( bool $search = false ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(
				'products' => array(),
				'labels'   => array(),
			);
		}

		$posts    = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$tr       = $wpdb->term_relationships;
		$tt       = $wpdb->term_taxonomy;
		$t        = $wpdb->terms;

		$taxes = array( 'product_cat', 'product_brand' );
		foreach ( Config::ATTRIBUTE_SLUGS as $slug ) {
			$taxes[] = 'pa_' . $slug;
		}
		$tax_in   = "'" . implode( "','", array_map( 'esc_sql', $taxes ) ) . "'";
		$excluded = $search ? 'exclude-from-search' : 'exclude-from-catalog';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single snapshot scan over fixed WP core tables and whitelisted taxonomy names.
		$rows = $wpdb->get_results(
			"SELECT p.ID AS id,
				COALESCE(pm.meta_value, 'instock') AS stock_status,
				tt.taxonomy AS taxonomy,
				t.slug AS slug,
				t.name AS name
			FROM {$posts} p
			LEFT JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock_status'
			LEFT JOIN {$tr} tr ON tr.object_id = p.ID
			LEFT JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ({$tax_in})
			LEFT JOIN {$t} t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND p.post_parent = 0
				AND NOT EXISTS (
					SELECT 1
					FROM {$tr} trv
					INNER JOIN {$tt} ttv ON ttv.term_taxonomy_id = trv.term_taxonomy_id AND ttv.taxonomy = 'product_visibility'
					INNER JOIN {$t} tv ON tv.term_id = ttv.term_id AND tv.slug = '{$excluded}'
					WHERE trv.object_id = p.ID
				)",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read the catalog facet snapshot.' );
		}
		return self::assemble_snapshot_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Pure assembler turning joined product/term SQL rows into the compact snapshot structure.
	 *
	 * @param list<array<string,mixed>> $rows Raw rows (`id`, `stock_status`, `taxonomy`, `slug`, `name`).
	 * @return array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}
	 */
	public static function assemble_snapshot_rows( array $rows ): array {
		$by_id  = array();
		$labels = array();

		foreach ( $rows as $row ) {
			$pid = (int) ( $row['id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			if ( ! isset( $by_id[ $pid ] ) ) {
				$stock_status  = strtolower( trim( (string) ( $row['stock_status'] ?? 'instock' ) ) );
				$by_id[ $pid ] = array(
					'id'       => $pid,
					'in_stock' => 'outofstock' !== $stock_status,
					'is_kit'   => false,
					'cats'     => array(),
					'brands'   => array(),
					'attrs'    => array(),
				);
			}

			$tax  = (string) ( $row['taxonomy'] ?? '' );
			$slug = (string) ( $row['slug'] ?? '' );
			$name = (string) ( $row['name'] ?? '' );
			if ( '' === $tax || '' === $slug ) {
				continue;
			}

			if ( 'product_cat' === $tax ) {
				$by_id[ $pid ]['cats'][ $slug ] = true;
				$labels['product_cat'][ $slug ] = $name;
			} elseif ( 'product_brand' === $tax ) {
				$by_id[ $pid ]['brands'][ $slug ] = true;
				$labels['product_brand'][ $slug ] = $name;
			} elseif ( str_starts_with( $tax, 'pa_' ) ) {
				$attr_slug = substr( $tax, 3 );
				if ( ! in_array( $attr_slug, Config::ATTRIBUTE_SLUGS, true ) ) {
					continue;
				}
				$by_id[ $pid ]['attrs'][ $attr_slug ][ $slug ] = true;
				$labels[ $attr_slug ][ $slug ]                 = $name;

				if ( TireSpecs::ATTR_POSITION === $attr_slug && TireSpecs::POS_KIT === TireSpecs::normalize_position( $slug ) ) {
					$by_id[ $pid ]['is_kit'] = true;
				}
			}
		}

		$products = array();
		foreach ( $by_id as $item ) {
			// Kits (Posición = Kit) never enter unit-tire dimension/load/speed facets.
			if ( $item['is_kit'] ) {
				foreach ( Config::UNIT_ONLY_ATTRIBUTES as $unit_attr ) {
					unset( $item['attrs'][ $unit_attr ] );
				}
			}
			$item['cats']   = array_map( 'strval', array_keys( $item['cats'] ) );
			$item['brands'] = array_map( 'strval', array_keys( $item['brands'] ) );
			foreach ( $item['attrs'] as $attr_slug => $slug_map ) {
				$item['attrs'][ $attr_slug ] = array_map( 'strval', array_keys( $slug_map ) );
			}
			$products[] = $item;
		}

		return array(
			'products' => $products,
			'labels'   => $labels,
		);
	}

	/**
	 * Computes exact disjunctive facet counts across all 11 filter groups in a single O(N) pass.
	 *
	 * Context: `cat_slugs` = archive category plus its descendants (WooCommerce lists child-category products on the
	 * parent archive); `universe_ids` = base product set of the main query on search / other taxonomy archives.
	 *
	 * @param array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>} $snapshot Compact snapshot.
	 * @param FilterState                                                                         $state    Active URL filter state.
	 * @param array<string,mixed>                                                                 $context  Request context (only_in_stock, moto_ids, kit_ids, cat_slug, cat_slugs, universe_ids, sale_ids).
	 * @return array{total:int,facets:array<string,list<array{slug:string,label:string,count:int,active:bool}>>}
	 */
	public static function compute_facets( array $snapshot, FilterState $state, array $context = array() ): array {
		$only_in_stock = ! array_key_exists( 'only_in_stock', $context ) || ! empty( $context['only_in_stock'] );
		$moto_ids      = isset( $context['moto_ids'] ) && is_array( $context['moto_ids'] ) ? $context['moto_ids'] : null;
		$kit_ids       = isset( $context['kit_ids'] ) && is_array( $context['kit_ids'] ) ? $context['kit_ids'] : null;
		$universe      = isset( $context['universe_ids'] ) && is_array( $context['universe_ids'] ) ? $context['universe_ids'] : null;
		$cat_set       = array();
		foreach ( array_merge( array( (string) ( $context['cat_slug'] ?? '' ) ), (array) ( $context['cat_slugs'] ?? array() ) ) as $cs ) {
			if ( '' !== (string) $cs ) {
				$cat_set[ (string) $cs ] = true;
			}
		}
		$sale_ids = isset( $context['sale_ids'] ) && is_array( $context['sale_ids'] ) ? $context['sale_ids'] : array();

		// Precompute active filter groups as lookup sets for O(1) checks inside the loop.
		$active_groups = array();
		foreach ( $state->attributes() as $attr_slug => $slugs ) {
			if ( ! empty( $slugs ) ) {
				$active_groups[ $attr_slug ] = array_fill_keys( $slugs, true );
			}
		}
		$active_brands = $state->brands();
		if ( ! empty( $active_brands ) ) {
			$active_groups['product_brand'] = array_fill_keys( $active_brands, true );
		}
		if ( $state->on_sale() ) {
			$active_groups['oferta'] = array( '1' => true );
		}

		$counts = array();
		foreach ( Config::ALL_FACETS as $facet ) {
			$counts[ $facet ] = array();
		}
		$total = 0;

		foreach ( $snapshot['products'] as $p ) {
			if ( $only_in_stock && empty( $p['in_stock'] ) ) {
				continue;
			}
			$pid = (int) $p['id'];
			if ( null !== $moto_ids && ! isset( $moto_ids[ $pid ] ) ) {
				continue;
			}
			if ( null !== $kit_ids && ! isset( $kit_ids[ $pid ] ) ) {
				continue;
			}
			if ( null !== $universe && ! isset( $universe[ $pid ] ) ) {
				continue;
			}
			if ( ! empty( $cat_set ) && empty( array_intersect_key( $cat_set, array_flip( $p['cats'] ) ) ) ) {
				continue;
			}

			$is_on_sale   = isset( $sale_ids[ $pid ] );
			$fail_count   = 0;
			$failed_group = '';

			foreach ( $active_groups as $group => $wanted_map ) {
				$matches = false;
				if ( 'oferta' === $group ) {
					$matches = $is_on_sale;
				} elseif ( 'product_brand' === $group ) {
					foreach ( $p['brands'] as $b ) {
						if ( isset( $wanted_map[ $b ] ) ) {
							$matches = true;
							break;
						}
					}
				} else {
					$available = array_fill_keys( $p['attrs'][ $group ] ?? array(), true );
					$matched   = array_intersect_key( $wanted_map, $available );
					$matches   = 'and' === $state->operator( $group ) ? count( $matched ) === count( $wanted_map ) : ! empty( $matched );
				}
				if ( ! $matches ) {
					++$fail_count;
					$failed_group = $group;
					if ( $fail_count >= 2 ) {
						break;
					}
				}
			}

			if ( $fail_count >= 2 ) {
				continue;
			}

			if ( 0 === $fail_count ) {
				++$total;
				foreach ( Config::ATTRIBUTE_SLUGS as $attr_slug ) {
					foreach ( $p['attrs'][ $attr_slug ] ?? array() as $t_slug ) {
						$counts[ $attr_slug ][ $t_slug ] = ( $counts[ $attr_slug ][ $t_slug ] ?? 0 ) + 1;
					}
				}
				foreach ( $p['brands'] as $b_slug ) {
					$counts['product_brand'][ $b_slug ] = ( $counts['product_brand'][ $b_slug ] ?? 0 ) + 1;
				}
				if ( $is_on_sale ) {
					$counts['oferta']['1'] = ( $counts['oferta']['1'] ?? 0 ) + 1;
				}
			} elseif ( 'oferta' === $failed_group ) {
				// Failed only the sale filter -> contributes disjunctively ONLY to oferta.
				if ( $is_on_sale ) {
					$counts['oferta']['1'] = ( $counts['oferta']['1'] ?? 0 ) + 1;
				}
			} elseif ( 'product_brand' === $failed_group ) {
				foreach ( $p['brands'] as $b_slug ) {
					$counts['product_brand'][ $b_slug ] = ( $counts['product_brand'][ $b_slug ] ?? 0 ) + 1;
				}
			} elseif ( 'and' !== $state->operator( $failed_group ) ) {
				foreach ( $p['attrs'][ $failed_group ] ?? array() as $t_slug ) {
					$counts[ $failed_group ][ $t_slug ] = ( $counts[ $failed_group ][ $t_slug ] ?? 0 ) + 1;
				}
			}
		}

		$labels = $snapshot['labels'] ?? array();
		$facets = array();
		foreach ( Config::ALL_FACETS as $facet ) {
			$items = array();
			if ( 'oferta' === $facet ) {
				$sale_cnt = (int) ( $counts['oferta']['1'] ?? 0 );
				if ( $sale_cnt > 0 || $state->on_sale() ) {
					$items[] = array(
						'slug'   => '1',
						'label'  => __( 'On sale', 'motored-core' ),
						'count'  => $sale_cnt,
						'active' => $state->on_sale(),
					);
				}
				$facets['oferta'] = $items;
				continue;
			}

			$active_slugs = 'product_brand' === $facet ? $state->brands() : $state->attribute_terms( $facet );
			$seen_slugs   = $counts[ $facet ];
			foreach ( $active_slugs as $as ) {
				if ( ! isset( $seen_slugs[ $as ] ) ) {
					$seen_slugs[ $as ] = 0;
				}
			}

			foreach ( $seen_slugs as $slug => $cnt ) {
				$slug_str = (string) $slug;
				$label    = (string) ( $labels[ $facet ][ $slug_str ] ?? $slug_str );
				$items[]  = array(
					'slug'   => $slug_str,
					'label'  => $label,
					'count'  => (int) $cnt,
					'active' => in_array( $slug_str, $active_slugs, true ),
				);
			}

			$facets[ $facet ] = self::sort_facet_items( $facet, $items );
		}

		return array(
			'total'  => $total,
			'facets' => $facets,
		);
	}

	/**
	 * Builds the compact Ancho / Perfil / Rodado combinations list from real pa_* terms of unit tires.
	 *
	 * Imperial tires (no pa_perfil) emit `p => null` and `pl => ''` so the client cascade disables Perfil cleanly.
	 *
	 * @param array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>} $snapshot      Snapshot.
	 * @param bool                                                                                $only_in_stock Filter to in-stock products.
	 * @return list<array{w:string,wl:string,p:string|null,pl:string,r:string,rl:string,c:int}>
	 */
	public static function size_combinations( array $snapshot, bool $only_in_stock = true ): array {
		$labels = $snapshot['labels'] ?? array();
		$combos = array();

		foreach ( $snapshot['products'] as $p ) {
			if ( ! empty( $p['is_kit'] ) ) {
				continue;
			}
			if ( $only_in_stock && empty( $p['in_stock'] ) ) {
				continue;
			}
			$widths   = $p['attrs'][ TireSpecs::ATTR_WIDTH ] ?? array();
			$profiles = $p['attrs'][ TireSpecs::ATTR_PROFILE ] ?? array();
			$rims     = $p['attrs'][ TireSpecs::ATTR_RIM ] ?? array();
			if ( empty( $widths ) || empty( $rims ) ) {
				continue;
			}
			// Sort multi-valued attribute rows deterministically since the snapshot query has no ORDER BY.
			sort( $widths, SORT_NATURAL | SORT_FLAG_CASE );
			sort( $rims, SORT_NATURAL | SORT_FLAG_CASE );
			if ( ! empty( $profiles ) ) {
				sort( $profiles, SORT_NATURAL | SORT_FLAG_CASE );
			}

			$w_slug = (string) $widths[0];
			$r_slug = (string) $rims[0];
			$p_slug = ! empty( $profiles ) ? (string) $profiles[0] : null;
			$key    = $w_slug . '|' . ( null !== $p_slug ? $p_slug : '' ) . '|' . $r_slug;

			if ( ! isset( $combos[ $key ] ) ) {
				$combos[ $key ] = array(
					'w'  => $w_slug,
					'wl' => (string) ( $labels[ TireSpecs::ATTR_WIDTH ][ $w_slug ] ?? $w_slug ),
					'p'  => $p_slug,
					'pl' => null !== $p_slug ? (string) ( $labels[ TireSpecs::ATTR_PROFILE ][ $p_slug ] ?? $p_slug ) : '',
					'r'  => $r_slug,
					'rl' => (string) ( $labels[ TireSpecs::ATTR_RIM ][ $r_slug ] ?? $r_slug ),
					'c'  => 0,
				);
			}
			++$combos[ $key ]['c'];
		}

		$list = array_values( $combos );
		usort(
			$list,
			static function ( array $a, array $b ): int {
				$cmp = self::numeric_weight( $a['wl'] ) <=> self::numeric_weight( $b['wl'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$cmp = self::numeric_weight( $a['pl'] ) <=> self::numeric_weight( $b['pl'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return self::numeric_weight( $a['rl'] ) <=> self::numeric_weight( $b['rl'] );
			}
		);
		return $list;
	}

	/**
	 * Resolves on-sale product IDs live on the request (never stored in the facet snapshot).
	 *
	 * @return array<int,true>
	 */
	public static function on_sale_id_map(): array {
		if ( ! function_exists( 'wc_get_product_ids_on_sale' ) ) {
			return array();
		}
		$ids = wc_get_product_ids_on_sale();
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}
		$map = array();
		foreach ( $ids as $id ) {
			$pid = (int) $id;
			if ( $pid > 0 ) {
				$map[ $pid ] = true;
			}
		}
		return $map;
	}

	/**
	 * Sorts facet options using domain rules: numeric for dimensions/load, ETRTO rank for speed, logical for position.
	 *
	 * @param string                                                      $facet Facet key.
	 * @param list<array{slug:string,label:string,count:int,active:bool}> $items Items.
	 * @return list<array{slug:string,label:string,count:int,active:bool}>
	 */
	public static function sort_facet_items( string $facet, array $items ): array {
		if ( in_array( $facet, array( TireSpecs::ATTR_WIDTH, TireSpecs::ATTR_PROFILE, TireSpecs::ATTR_RIM, TireSpecs::ATTR_LOAD_INDEX ), true ) ) {
			usort(
				$items,
				static function ( array $a, array $b ): int {
					$cmp = self::numeric_weight( $a['label'] ) <=> self::numeric_weight( $b['label'] );
					return 0 !== $cmp ? $cmp : strcasecmp( $a['label'], $b['label'] );
				}
			);
			return $items;
		}

		if ( TireSpecs::ATTR_SPEED_RATING === $facet ) {
			usort(
				$items,
				static function ( array $a, array $b ): int {
					$ra  = TireSpecs::speed_rank( $a['label'] ) ?? TireSpecs::speed_rank( $a['slug'] ) ?? 999;
					$rb  = TireSpecs::speed_rank( $b['label'] ) ?? TireSpecs::speed_rank( $b['slug'] ) ?? 999;
					$cmp = $ra <=> $rb;
					return 0 !== $cmp ? $cmp : strcasecmp( $a['label'], $b['label'] );
				}
			);
			return $items;
		}

		if ( TireSpecs::ATTR_POSITION === $facet ) {
			$order = array(
				TireSpecs::POS_FRONT => 1,
				TireSpecs::POS_REAR  => 2,
				TireSpecs::POS_BOTH  => 3,
				TireSpecs::POS_KIT   => 4,
			);
			usort(
				$items,
				static function ( array $a, array $b ) use ( $order ): int {
					$oa  = $order[ TireSpecs::normalize_position( $a['slug'] ) ] ?? 99;
					$ob  = $order[ TireSpecs::normalize_position( $b['slug'] ) ] ?? 99;
					$cmp = $oa <=> $ob;
					return 0 !== $cmp ? $cmp : strcasecmp( $a['label'], $b['label'] );
				}
			);
			return $items;
		}

		usort( $items, static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] ) );
		return $items;
	}

	/**
	 * Converts a label like "4.10", "120", "17" to a float solely for display sorting (never stored or cast as slug).
	 *
	 * @param string $label Term label.
	 */
	private static function numeric_weight( string $label ): float {
		$norm = str_replace( ',', '.', trim( $label ) );
		if ( is_numeric( $norm ) ) {
			return (float) $norm;
		}
		return 999999.0;
	}

	/**
	 * Resets per-request memoized snapshot (for unit tests).
	 */
	public static function reset(): void {
		self::$memo_snapshot        = null;
		self::$memo_search_snapshot = null;
	}
}
