<?php
/**
 * Motorcycle-to-tire matching engine (1-hop directed equivalences, kit dual-axle, optional strict specs).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Computes bike profiles, evaluates product fitments and reverses product specs into compatible bikes.
 */
final class MatchEngine {

	/**
	 * Per-request memo of per-position matching id lists (never persisted; the cache bucket stays the source).
	 *
	 * @var array<string,array{all:list<int>,front:list<int>,rear:list<int>,kit:list<int>}>
	 */
	private static array $ids_memo = array();

	/**
	 * Per-request memo of loaded bike profiles (avoids re-querying within the same request when Redis is off).
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static array $profile_memo = array();


	/**
	 * Loads a published motorcycle version's compact compatibility profile (cached in compat_catalog).
	 *
	 * @param string $slug Immutable version slug.
	 * @return array<string,mixed>|null
	 */
	public static function bike_profile( string $slug ): ?array {
		$clean = sanitize_title( $slug );
		if ( '' === $clean ) {
			return null;
		}
		if ( array_key_exists( $clean, self::$profile_memo ) ) {
			return self::$profile_memo[ $clean ];
		}
		$cached                       = CompatCache::remember(
			'bike2_' . md5( $clean ),
			static fn(): array => array( 'p' => self::build_bike_profile( $clean ) ),
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		self::$profile_memo[ $clean ] = is_array( $cached['p'] ?? null ) ? $cached['p'] : null;
		return self::$profile_memo[ $clean ];
	}

	/**
	 * Batch-loads published motorcycle compatibility profiles in at most 3 queries for all uncached slugs.
	 *
	 * @param array<int,string> $slugs Version slugs.
	 * @return array<string,array<string,mixed>> Keyed by sanitized slug (missing/draft slugs omitted).
	 */
	public static function bike_profile_many( array $slugs ): array {
		$clean_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $slugs ), static fn( string $s ): bool => '' !== $s ) ) );
		if ( empty( $clean_slugs ) ) {
			return array();
		}

		$profiles   = array();
		$missing    = array();
		$persistent = Cache::is_persistent();

		foreach ( $clean_slugs as $clean ) {
			if ( $persistent ) {
				$found  = false;
				$cached = Cache::get( 'bike2_' . md5( $clean ), CompatCache::BUCKET_CATALOG, $found );
				if ( $found ) {
					if ( is_array( $cached['p'] ?? null ) ) {
						$profiles[ $clean ] = $cached['p'];
					}
					continue;
				}
			}
			$missing[] = $clean;
		}

		if ( empty( $missing ) ) {
			return $profiles;
		}

		$versions = CatalogRepository::find_versions_by_slugs( $missing, true );
		$ver_ids  = array();
		foreach ( $versions as $ver ) {
			$ver_ids[] = (int) $ver['id'];
		}
		$fitments_by_ver = CatalogRepository::fitments_for_versions_full( $ver_ids );

		$oem_size_ids = array();
		foreach ( $fitments_by_ver as $rows ) {
			foreach ( $rows as $row ) {
				if ( TireSpecs::FIT_OEM === (string) $row['fit_type'] ) {
					$oem_size_ids[] = (int) $row['size_id'];
				}
			}
		}
		$all_equivs = ! empty( $oem_size_ids ) ? CatalogRepository::equivalences_from_sizes_batch( $oem_size_ids ) : array();

		foreach ( $missing as $clean ) {
			if ( ! isset( $versions[ $clean ] ) ) {
				if ( $persistent ) {
					Cache::set( 'bike2_' . md5( $clean ), array( 'p' => null ), CompatCache::TTL_CATALOG, CompatCache::BUCKET_CATALOG );
				}
				continue;
			}
			$ver                          = $versions[ $clean ];
			$vid                          = (int) $ver['id'];
			$axes                         = self::build_axes_from_fitments( $fitments_by_ver[ $vid ] ?? array(), $all_equivs );
			$profile                      = self::format_profile_array( $ver, $axes );
			$profiles[ $clean ]           = $profile;
			self::$profile_memo[ $clean ] = $profile;
			if ( $persistent ) {
				Cache::set( 'bike2_' . md5( $clean ), array( 'p' => $profile ), CompatCache::TTL_CATALOG, CompatCache::BUCKET_CATALOG );
			}
		}

		return $profiles;
	}

	/**
	 * Resets per-request static memos (for unit tests).
	 */
	public static function reset(): void {
		self::$ids_memo     = array();
		self::$profile_memo = array();
	}

	/**
	 * Evaluates whether a product index row matches a bike profile (pure PHP, mirrored in compat-garage.js).
	 *
	 * @param array<string,mixed> $product_fit  Product index row (`position`, `front_canonical`, `rear_canonical`, etc.).
	 * @param array<string,mixed> $bike_profile Output of bike_profile().
	 * @param bool                $strict_specs When true, missing tire specs fail version minimum requirements.
	 * @return array{compatible:bool,positions:list<string>,match:string,caution:bool,equivalent_from:string}
	 */
	public static function evaluate_product( array $product_fit, array $bike_profile, bool $strict_specs = false ): array {
		$pos     = (string) ( $product_fit['position'] ?? '' );
		$front   = is_array( $bike_profile['front'] ?? null ) ? $bike_profile['front'] : array();
		$rear    = is_array( $bike_profile['rear'] ?? null ) ? $bike_profile['rear'] : array();
		$f_const = self::axle_construction( $product_fit, TireSpecs::POS_FRONT );
		$r_const = self::axle_construction( $product_fit, TireSpecs::POS_REAR );

		$f_size  = isset( $product_fit['front_canonical'] ) ? (string) $product_fit['front_canonical'] : '';
		$r_size  = isset( $product_fit['rear_canonical'] ) ? (string) $product_fit['rear_canonical'] : '';
		$f_load  = isset( $product_fit['front_load'] ) ? (int) $product_fit['front_load'] : null;
		$r_load  = isset( $product_fit['rear_load'] ) ? (int) $product_fit['rear_load'] : null;
		$f_speed = isset( $product_fit['front_speed_rank'] ) ? (int) $product_fit['front_speed_rank'] : null;
		$r_speed = isset( $product_fit['rear_speed_rank'] ) ? (int) $product_fit['rear_speed_rank'] : null;

		if ( TireSpecs::POS_KIT === $pos ) {
			if ( '' === $f_size || '' === $r_size || ! isset( $front[ $f_size ], $rear[ $r_size ] ) ) {
				return self::no_match();
			}
			$fc = self::check_constraints( $front[ $f_size ], $f_load, $f_speed, $f_const, $strict_specs );
			$rc = self::check_constraints( $rear[ $r_size ], $r_load, $r_speed, $r_const, $strict_specs );
			if ( ! $fc['ok'] || ! $rc['ok'] ) {
				return self::no_match();
			}
			$rank = self::worse_match_type( (string) $front[ $f_size ]['type'], (string) $rear[ $r_size ]['type'] );
			$from = self::join_equiv_from( (string) ( $front[ $f_size ]['from'] ?? '' ), (string) ( $rear[ $r_size ]['from'] ?? '' ) );
			return array(
				'compatible'      => true,
				'positions'       => array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ),
				'match'           => $rank,
				'caution'         => $fc['caution'] || $rc['caution'],
				'equivalent_from' => $from,
			);
		}

		$positions = array();
		$best_type = '';
		$caution   = false;
		$from_list = array();

		if ( ( TireSpecs::POS_FRONT === $pos || TireSpecs::POS_BOTH === $pos ) && '' !== $f_size && isset( $front[ $f_size ] ) ) {
			$fc = self::check_constraints( $front[ $f_size ], $f_load, $f_speed, $f_const, $strict_specs );
			if ( $fc['ok'] ) {
				$positions[] = TireSpecs::POS_FRONT;
				$best_type   = (string) $front[ $f_size ]['type'];
				$caution     = $fc['caution'];
				if ( ! empty( $front[ $f_size ]['from'] ) ) {
					$from_list[] = (string) $front[ $f_size ]['from'];
				}
			}
		}

		if ( ( TireSpecs::POS_REAR === $pos || TireSpecs::POS_BOTH === $pos ) && '' !== $r_size && isset( $rear[ $r_size ] ) ) {
			$rc = self::check_constraints( $rear[ $r_size ], $r_load, $r_speed, $r_const, $strict_specs );
			if ( $rc['ok'] ) {
				$positions[] = TireSpecs::POS_REAR;
				$best_type   = '' === $best_type ? (string) $rear[ $r_size ]['type'] : self::better_match_type( $best_type, (string) $rear[ $r_size ]['type'] );
				$caution     = $caution || $rc['caution'];
				if ( ! empty( $rear[ $r_size ]['from'] ) ) {
					$from_list[] = (string) $rear[ $r_size ]['from'];
				}
			}
		}

		if ( empty( $positions ) ) {
			return self::no_match();
		}

		return array(
			'compatible'      => true,
			'positions'       => $positions,
			'match'           => $best_type,
			'caution'         => $caution,
			'equivalent_from' => implode( ', ', array_unique( $from_list ) ),
		);
	}

	/**
	 * Returns compatible product IDs for a motorcycle version slug and optional position filter, best fit first (OEM, alternative, equivalent).
	 *
	 * Served from matching_ids_by_position(): ONE candidate query per bike per request, shared by the grid, the
	 * counts (bar, banner, bike page) and the facet sidebar, so a tab count can never disagree with its grid.
	 *
	 * @param string $version_slug Published version slug.
	 * @param string $pos_filter   Optional '' | 'front' | 'rear' | 'kit'.
	 * @return list<int>
	 */
	public static function matching_product_ids( string $version_slug, string $pos_filter = '' ): array {
		$bucket = in_array( $pos_filter, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ), true ) ? $pos_filter : 'all';
		return self::matching_ids_by_position( $version_slug )[ $bucket ];
	}

	/**
	 * Per-position compatible product counts (same lists as matching_product_ids()).
	 *
	 * @param string $version_slug Published version slug.
	 * @return array{all:int,front:int,rear:int,kit:int}
	 */
	public static function matching_product_counts( string $version_slug ): array {
		$lists = self::matching_ids_by_position( $version_slug );
		return array(
			'all'   => count( $lists['all'] ),
			'front' => count( $lists['front'] ),
			'rear'  => count( $lists['rear'] ),
			'kit'   => count( $lists['kit'] ),
		);
	}

	/**
	 * Cached, memoized partition of a bike's compatible products by position, built from ONE candidate query.
	 *
	 * @param string $version_slug Published version slug.
	 * @return array{all:list<int>,front:list<int>,rear:list<int>,kit:list<int>}
	 */
	public static function matching_ids_by_position( string $version_slug ): array {
		$empty      = array(
			'all'   => array(),
			'front' => array(),
			'rear'  => array(),
			'kit'   => array(),
		);
		$clean_slug = sanitize_title( $version_slug );
		if ( '' === $clean_slug ) {
			return $empty;
		}
		$strict = Config::strict_specs();
		$key    = 'mpart1_' . md5( $clean_slug . '|' . ( $strict ? '1' : '0' ) );

		// Per-request memo: the shop query, the facet sidebar, the bar and the bike page share one partition.
		if ( isset( self::$ids_memo[ $key ] ) ) {
			return self::$ids_memo[ $key ];
		}

		$cached = CompatCache::remember(
			$key,
			static function () use ( $clean_slug, $strict, $empty ): array {
				$profile = self::bike_profile( $clean_slug );
				if ( null === $profile ) {
					return $empty;
				}
				$front_ids = array();
				foreach ( $profile['front'] as $item ) {
					$front_ids[] = (int) $item['size_id'];
				}
				$rear_ids = array();
				foreach ( $profile['rear'] as $item ) {
					$rear_ids[] = (int) $item['size_id'];
				}
				$candidates = ProductIndexRepository::query_candidates_for_sizes( $front_ids, $rear_ids, '' );
				return self::partition_by_position( $candidates, $profile, $strict );
			},
			CompatCache::BUCKET_MATCHES,
			CompatCache::TTL_MATCHES
		);

		$lists = $empty;
		foreach ( array_keys( $empty ) as $bucket ) {
			$lists[ $bucket ] = array_values( array_map( 'intval', is_array( $cached[ $bucket ] ?? null ) ? $cached[ $bucket ] : array() ) );
		}
		self::$ids_memo[ $key ] = $lists;
		return $lists;
	}

	/**
	 * Pure partition of compatible candidates, each list best fit first (OEM > alternative > equivalent; stable).
	 *
	 * Buckets: a kit goes ONLY to `kit` (never front/rear — otherwise "Delanteras (1)" links to an empty grid);
	 * a single or dual (`both`) tire goes to every axle the evaluation actually matched (a dual tire whose front
	 * fails its load rating is NOT listed as a front tire). `all` holds every compatible product once.
	 *
	 * @param list<array<string,mixed>> $candidates Rows from ProductIndexRepository::query_candidates_for_sizes().
	 * @param array<string,mixed>       $profile    Output of bike_profile().
	 * @param bool                      $strict     Strict specs setting.
	 * @return array{all:list<int>,front:list<int>,rear:list<int>,kit:list<int>}
	 */
	public static function partition_by_position( array $candidates, array $profile, bool $strict ): array {
		$ranked = array();
		foreach ( $candidates as $row ) {
			$eval = self::evaluate_product( $row, $profile, $strict );
			if ( ! $eval['compatible'] ) {
				continue;
			}
			$buckets = array( 'all' );
			if ( TireSpecs::POS_KIT === (string) ( $row['position'] ?? '' ) ) {
				$buckets[] = TireSpecs::POS_KIT;
			} else {
				foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $axis ) {
					if ( in_array( $axis, $eval['positions'], true ) ) {
						$buckets[] = $axis;
					}
				}
			}
			$ranked[] = array( (int) ( $row['product_id'] ?? 0 ), self::match_priority( $eval['match'] ), $buckets );
		}
		usort( $ranked, static fn( array $a, array $b ): int => $b[1] <=> $a[1] );

		$out = array(
			'all'   => array(),
			'front' => array(),
			'rear'  => array(),
			'kit'   => array(),
		);
		foreach ( $ranked as $r ) {
			foreach ( $r[2] as $bucket ) {
				$out[ $bucket ][] = $r[0];
			}
		}
		return $out;
	}

	/**
	 * Pure per-position tally (counts of partition_by_position()).
	 *
	 * @param list<array<string,mixed>> $candidates Rows from ProductIndexRepository::query_candidates_for_sizes().
	 * @param array<string,mixed>       $profile    Output of bike_profile().
	 * @param bool                      $strict     Strict specs setting.
	 * @return array{all:int,front:int,rear:int,kit:int}
	 */
	public static function count_by_position( array $candidates, array $profile, bool $strict ): array {
		$lists = self::partition_by_position( $candidates, $profile, $strict );
		return array(
			'all'   => count( $lists['all'] ),
			'front' => count( $lists['front'] ),
			'rear'  => count( $lists['rear'] ),
			'kit'   => count( $lists['kit'] ),
		);
	}

	/**
	 * Reverse lookup: compatible motorcycles for a product (shared cache key by tire spec, not per product_id).
	 *
	 * Returns null when the product is not indexed (not a tire / no valid size).
	 *
	 * @param int $product_id Product ID.
	 * @return array{position:string,items:list<array<string,mixed>>}|null
	 */
	public static function compatible_motos_for_product( int $product_id ): ?array {
		$fit = ProductIndexRepository::find( $product_id );
		if ( null === $fit ) {
			return null;
		}
		$strict   = Config::strict_specs();
		$spec_key = 'pm3_' . md5(
			implode(
				'|',
				array(
					(string) $fit['position'],
					(string) ( $fit['front_size_id'] ?? 0 ),
					(string) ( $fit['rear_size_id'] ?? 0 ),
					(string) ( $fit['front_load'] ?? '' ),
					(string) ( $fit['rear_load'] ?? '' ),
					(string) ( $fit['front_speed_rank'] ?? '' ),
					(string) ( $fit['rear_speed_rank'] ?? '' ),
					(string) ( $fit['construction'] ?? '' ),
					(string) ( $fit['front_construction'] ?? '' ),
					(string) ( $fit['rear_construction'] ?? '' ),
					$strict ? '1' : '0',
				)
			)
		);

		return CompatCache::remember(
			$spec_key,
			static fn(): array => array(
				'position' => (string) $fit['position'],
				'items'    => self::compute_compatible_motos( $fit, $strict ),
			),
			CompatCache::BUCKET_MATCHES,
			CompatCache::TTL_MATCHES
		);
	}

	/**
	 * Single human label for a motorcycle version everywhere (selectors, Mi Garage, search, admin):
	 * "Benelli TRK 502 2017+" or "Honda CBR900RR Fireblade SC28 1992–1995".
	 *
	 * @param string $brand     Brand name.
	 * @param string $model     Model name.
	 * @param string $variant   Optional real sub-version (ABS, Rally, chassis code).
	 * @param int    $year_from Start year.
	 * @param int    $year_to   End year (0 = present).
	 */
	public static function version_label( string $brand, string $model, string $variant, int $year_from, int $year_to ): string {
		$variant = trim( $variant );
		return trim( $brand . ' ' . $model . ( '' !== $variant ? ' ' . $variant : '' ) ) . ' ' . self::format_years( $year_from, $year_to );
	}

	/**
	 * Formats a compact, human-readable year range label ("2001–2024", or "2020+" while still in production).
	 *
	 * @param int $year_from Start year.
	 * @param int $year_to   End year (0 = present).
	 */
	public static function format_years( int $year_from, int $year_to ): string {
		if ( 0 === $year_to ) {
			return $year_from . '+';
		}
		if ( $year_from === $year_to ) {
			return (string) $year_from;
		}
		return $year_from . '–' . $year_to;
	}

	/**
	 * Builds front/rear accepted-size maps from fitment rows plus 1-hop OEM equivalences.
	 *
	 * Shared by bike_profile() (storefront) and the admin Store matching preview so both enforce identical rules.
	 *
	 * @param list<array<string,mixed>>      $fitments          Fitment rows from CatalogRepository::fitments_for_version().
	 * @param list<array<string,mixed>>|null $prefetched_equivs Optional preloaded batch of equivalences (avoids N+1 queries).
	 * @return array{front:array<string,array<string,mixed>>,rear:array<string,array<string,mixed>>}
	 */
	public static function build_axes_from_fitments( array $fitments, ?array $prefetched_equivs = null ): array {
		$axes    = array(
			TireSpecs::POS_FRONT => array(),
			TireSpecs::POS_REAR  => array(),
		);
		$oem_ids = array(
			TireSpecs::POS_FRONT => array(),
			TireSpecs::POS_REAR  => array(),
		);

		foreach ( $fitments as $f ) {
			$pos = (string) $f['position'];
			if ( ! isset( $axes[ $pos ] ) ) {
				continue;
			}
			$canon = (string) $f['canonical'];
			$entry = array(
				'size_id'   => (int) $f['size_id'],
				'type'      => (string) $f['fit_type'],
				'from'      => '',
				'approx'    => 0,
				'min_load'  => $f['min_load'],
				'min_speed' => $f['min_speed_rank'],
				'req_const' => $f['req_construction'],
				'desig'     => (string) ( $f['designation'] ?? $canon ),
			);

			// Direct OEM always takes priority over ALT.
			if ( ! isset( $axes[ $pos ][ $canon ] ) || TireSpecs::FIT_OEM === $entry['type'] ) {
				$axes[ $pos ][ $canon ] = $entry;
			}
			if ( TireSpecs::FIT_OEM === $f['fit_type'] ) {
				$oem_ids[ $pos ][] = (int) $f['size_id'];
			}
		}

		// 1-hop directed equivalences originate ONLY from OEM fitments.
		foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $pos ) {
			if ( empty( $oem_ids[ $pos ] ) ) {
				continue;
			}
			$oem_fit_by_size = array();
			foreach ( $axes[ $pos ] as $row ) {
				if ( TireSpecs::FIT_OEM === $row['type'] ) {
					$oem_fit_by_size[ $row['size_id'] ] = $row;
				}
			}
			$wanted_ids = array_values( array_unique( $oem_ids[ $pos ] ) );
			if ( null !== $prefetched_equivs ) {
				$equivs = array_values(
					array_filter(
						$prefetched_equivs,
						static fn( array $eq ): bool => in_array( (int) ( $eq['source_size_id'] ?? 0 ), $wanted_ids, true )
							&& in_array( (string) ( $eq['position_scope'] ?? '' ), array( 'any', $pos ), true )
					)
				);
			} else {
				$equivs = CatalogRepository::equivalences_from_sizes( $wanted_ids, $pos );
			}
			foreach ( $equivs as $eq ) {
				$dst_canon = $eq['target_canonical'];
				// Direct OEM or ALT fitment on this bike always beats an equivalence entry.
				$current = $axes[ $pos ][ $dst_canon ] ?? null;
				if ( null !== $current && ( TireSpecs::FIT_EQUIV !== $current['type'] || ! self::preferred_equivalence( (int) $eq['is_approximate'], (string) $eq['source_canonical'], (int) $current['approx'], (string) $current['from'] ) ) ) {
					continue;
				}
				$src_fit                    = $oem_fit_by_size[ $eq['source_size_id'] ] ?? array();
				$axes[ $pos ][ $dst_canon ] = array(
					'size_id'   => (int) $eq['target_size_id'],
					'type'      => TireSpecs::FIT_EQUIV,
					'from'      => (string) $eq['source_canonical'],
					'approx'    => (int) $eq['is_approximate'],
					'min_load'  => $src_fit['min_load'] ?? null,
					'min_speed' => $src_fit['min_speed'] ?? null,
					'req_const' => $src_fit['req_const'] ?? null,
				);
			}
		}

		return $axes;
	}

	/**
	 * Builds the full bike profile from DB (including 1-hop directed equivalences from OEM fitments only).
	 *
	 * @param string $slug Version slug.
	 * @return array<string,mixed>|null
	 */
	private static function build_bike_profile( string $slug ): ?array {
		$ver = CatalogRepository::find_version_by_slug( $slug, true );
		if ( null === $ver ) {
			return null;
		}
		$axes = self::build_axes_from_fitments( CatalogRepository::fitments_for_version( (int) $ver['id'] ) );
		return self::format_profile_array( $ver, $axes );
	}

	/**
	 * Formats a version DB row + computed axes into the canonical bike profile array.
	 *
	 * @param array<string,mixed>                                                                   $ver  Version DB row.
	 * @param array{front:array<string,array<string,mixed>>,rear:array<string,array<string,mixed>>} $axes Computed axes.
	 * @return array<string,mixed>
	 */
	private static function format_profile_array( array $ver, array $axes ): array {
		$label = self::version_label( (string) $ver['brand_name'], (string) $ver['model_name'], (string) $ver['variant'], (int) $ver['year_from'], (int) $ver['year_to'] );

		return array(
			'slug'  => (string) $ver['slug'],
			'label' => $label,
			'brand' => (string) $ver['brand_name'],
			'model' => (string) $ver['model_name'],
			'years' => self::format_years( (int) $ver['year_from'], (int) $ver['year_to'] ),
			'front' => $axes[ TireSpecs::POS_FRONT ],
			'rear'  => $axes[ TireSpecs::POS_REAR ],
		);
	}

	/**
	 * Computes reverse compatible bikes list for a product index row.
	 *
	 * @param array<string,mixed> $fit    Product index row.
	 * @param bool                $strict Strict spec mode.
	 * @return list<array<string,mixed>>
	 */
	private static function compute_compatible_motos( array $fit, bool $strict ): array {
		$pos     = (string) $fit['position'];
		$f_const = self::axle_construction( $fit, TireSpecs::POS_FRONT );
		$r_const = self::axle_construction( $fit, TireSpecs::POS_REAR );

		if ( TireSpecs::POS_KIT === $pos ) {
			$f_id = (int) ( $fit['front_size_id'] ?? 0 );
			$r_id = (int) ( $fit['rear_size_id'] ?? 0 );
			if ( $f_id <= 0 || $r_id <= 0 ) {
				return array();
			}
			$front_hits = self::index_hits_by_version(
				ProductIndexRepository::query_version_fitments_for_tire_size( $f_id, TireSpecs::POS_FRONT ),
				$fit['front_load'],
				$fit['front_speed_rank'],
				$f_const,
				$strict
			);
			$rear_hits  = self::index_hits_by_version(
				ProductIndexRepository::query_version_fitments_for_tire_size( $r_id, TireSpecs::POS_REAR ),
				$fit['rear_load'],
				$fit['rear_speed_rank'],
				$r_const,
				$strict
			);
			$out        = array();
			foreach ( $front_hits as $vid => $fh ) {
				if ( ! isset( $rear_hits[ $vid ] ) ) {
					continue;
				}
				$rh    = $rear_hits[ $vid ];
				$out[] = array(
					'version_id' => (int) $vid,
					'slug'       => $fh['slug'],
					'brand'      => $fh['brand'],
					'model'      => $fh['model'],
					'aliases'    => $fh['aliases'],
					'years'      => $fh['years'],
					'variant'    => $fh['variant'],
					'positions'  => array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ),
					'match'      => self::worse_match_type( $fh['match'], $rh['match'] ),
					'equiv_from' => self::join_equiv_from( $fh['equiv_from'], $rh['equiv_from'] ),
					'caution'    => $fh['caution'] || $rh['caution'],
				);
			}
			return self::sort_moto_hits( $out );
		}

		$by_version = array();
		if ( ( TireSpecs::POS_FRONT === $pos || TireSpecs::POS_BOTH === $pos ) && ! empty( $fit['front_size_id'] ) ) {
			$hits = self::index_hits_by_version(
				ProductIndexRepository::query_version_fitments_for_tire_size( (int) $fit['front_size_id'], TireSpecs::POS_FRONT ),
				$fit['front_load'],
				$fit['front_speed_rank'],
				$f_const,
				$strict
			);
			foreach ( $hits as $vid => $h ) {
				$h['positions']     = array( TireSpecs::POS_FRONT );
				$by_version[ $vid ] = $h;
			}
		}

		if ( ( TireSpecs::POS_REAR === $pos || TireSpecs::POS_BOTH === $pos ) && ! empty( $fit['rear_size_id'] ) ) {
			$hits = self::index_hits_by_version(
				ProductIndexRepository::query_version_fitments_for_tire_size( (int) $fit['rear_size_id'], TireSpecs::POS_REAR ),
				$fit['rear_load'],
				$fit['rear_speed_rank'],
				$r_const,
				$strict
			);
			foreach ( $hits as $vid => $h ) {
				if ( isset( $by_version[ $vid ] ) ) {
					$by_version[ $vid ]['positions'][] = TireSpecs::POS_REAR;
					$by_version[ $vid ]['match']       = self::better_match_type( $by_version[ $vid ]['match'], $h['match'] );
					$by_version[ $vid ]['equiv_from']  = self::join_equiv_from( $by_version[ $vid ]['equiv_from'], $h['equiv_from'] );
					$by_version[ $vid ]['caution']     = $by_version[ $vid ]['caution'] || $h['caution'];
				} else {
					$h['positions']     = array( TireSpecs::POS_REAR );
					$by_version[ $vid ] = $h;
				}
			}
		}

		return self::sort_moto_hits( array_values( $by_version ) );
	}

	/**
	 * Filters and deduplicates raw version fitment rows per version_id after constraint evaluation.
	 *
	 * @param list<array<string,mixed>> $rows         DB rows.
	 * @param int|null                  $load         Product load index.
	 * @param int|null                  $speed_rank   Product speed rank.
	 * @param string                    $construction Product construction.
	 * @param bool                      $strict       Strict mode.
	 * @return array<int,array<string,mixed>>
	 */
	private static function index_hits_by_version( array $rows, ?int $load, ?int $speed_rank, string $construction, bool $strict ): array {
		$preferred = array();
		foreach ( $rows as $row ) {
			$vid     = (int) $row['version_id'];
			$current = $preferred[ $vid ] ?? null;
			$type    = (string) $row['fit_type'];
			if ( null === $current || self::match_priority( $type ) > self::match_priority( $current['fit_type'] )
				|| ( TireSpecs::FIT_EQUIV === $type && TireSpecs::FIT_EQUIV === $current['fit_type']
					&& self::preferred_equivalence( (int) ( $row['approx'] ?? 0 ), (string) $row['equiv_from'], (int) ( $current['approx'] ?? 0 ), (string) $current['equiv_from'] ) ) ) {
				$preferred[ $vid ] = $row;
			}
		}
		$map = array();
		foreach ( $preferred as $vid => $row ) {
			$req = array(
				'min_load'  => null !== $row['min_load'] ? (int) $row['min_load'] : null,
				'min_speed' => null !== $row['min_speed_rank'] ? (int) $row['min_speed_rank'] : null,
				'req_const' => null !== $row['req_construction'] ? (string) $row['req_construction'] : null,
			);
			$chk = self::check_constraints( $req, $load, $speed_rank, $construction, $strict );
			if ( ! $chk['ok'] ) {
				continue;
			}
			$map[ $vid ] = array(
				'version_id' => (int) $vid,
				'slug'       => (string) $row['version_slug'],
				'brand'      => (string) $row['brand_name'],
				'model'      => (string) $row['model_name'],
				'aliases'    => (string) $row['aliases'],
				'years'      => self::format_years( (int) $row['year_from'], (int) $row['year_to'] ),
				'variant'    => (string) $row['variant'],
				'match'      => (string) $row['fit_type'],
				'equiv_from' => (string) ( $row['equiv_from'] ?? '' ),
				'caution'    => $chk['caution'],
			);
		}
		return $map;
	}

	/**
	 * Uses the same tie-breaker in both directions before evaluating requirements.
	 *
	 * @param int    $approx          Whether the candidate is approximate.
	 * @param string $source          Candidate OEM size.
	 * @param int    $current_approx  Whether the current route is approximate.
	 * @param string $current_source  Current OEM size.
	 */
	private static function preferred_equivalence( int $approx, string $source, int $current_approx, string $current_source ): bool {
		return $approx < $current_approx || ( $approx === $current_approx && strcmp( $source, $current_source ) < 0 );
	}

	/**
	 * Evaluates minimum load, minimum speed and required construction rules.
	 *
	 * Never rejects a tire simply for having equal-or-higher rating; only rejects when explicitly below minimum,
	 * or when strict_specs is enabled and the tire is missing a required rating.
	 *
	 * @param array<string,mixed> $req          Bike fitment requirements (`min_load`, `min_speed`, `req_const`).
	 * @param int|null            $load         Product load index.
	 * @param int|null            $speed        Product speed rank.
	 * @param string              $construction Product construction.
	 * @param bool                $strict       Strict mode.
	 * @return array{ok:bool,caution:bool}
	 */
	private static function check_constraints( array $req, ?int $load, ?int $speed, string $construction, bool $strict ): array {
		$caution   = false;
		$min_load  = isset( $req['min_load'] ) ? (int) $req['min_load'] : null;
		$min_speed = isset( $req['min_speed'] ) ? (int) $req['min_speed'] : null;
		$req_const = isset( $req['req_const'] ) && '' !== (string) $req['req_const'] ? (string) $req['req_const'] : null;

		if ( null !== $min_load ) {
			if ( null === $load ) {
				if ( $strict ) {
					return array(
						'ok'      => false,
						'caution' => false,
					);
				}
				$caution = true;
			} elseif ( $load < $min_load ) {
				return array(
					'ok'      => false,
					'caution' => false,
				);
			}
		}

		if ( null !== $min_speed ) {
			if ( null === $speed ) {
				if ( $strict ) {
					return array(
						'ok'      => false,
						'caution' => false,
					);
				}
				$caution = true;
			} elseif ( $speed < $min_speed ) {
				return array(
					'ok'      => false,
					'caution' => false,
				);
			}
		}

		if ( null !== $req_const ) {
			if ( '' === $construction ) {
				if ( $strict ) {
					return array(
						'ok'      => false,
						'caution' => false,
					);
				}
				$caution = true;
			} elseif ( $construction !== $req_const ) {
				return array(
					'ok'      => false,
					'caution' => false,
				);
			}
		}

		return array(
			'ok'      => true,
			'caution' => $caution,
		);
	}

	/**
	 * Priority rank for fitment quality (oem > alt > equiv).
	 *
	 * @param string $type Fitment type.
	 */
	private static function match_priority( string $type ): int {
		if ( TireSpecs::FIT_OEM === $type ) {
			return 3;
		}
		if ( TireSpecs::FIT_ALT === $type ) {
			return 2;
		}
		return 1;
	}

	/**
	 * Better (higher priority) match between two positions of a dual-position tire.
	 *
	 * @param string $a First match type.
	 * @param string $b Second match type.
	 */
	private static function better_match_type( string $a, string $b ): string {
		return self::match_priority( $a ) >= self::match_priority( $b ) ? $a : $b;
	}

	/**
	 * Construction of one axle: the kit sheet value when present, otherwise the product-wide construction.
	 *
	 * @param array<string,mixed> $fit  Product index row.
	 * @param string              $axle 'front' | 'rear'.
	 */
	public static function axle_construction( array $fit, string $axle ): string {
		$own = $fit[ $axle . '_construction' ] ?? null;
		if ( is_string( $own ) && '' !== $own ) {
			return $own;
		}
		return isset( $fit['construction'] ) && is_string( $fit['construction'] ) ? $fit['construction'] : '';
	}

	/**
	 * Worse (lower priority) match between front and rear of a kit.
	 *
	 * @param string $a Front match type.
	 * @param string $b Rear match type.
	 */
	private static function worse_match_type( string $a, string $b ): string {
		return self::match_priority( $a ) <= self::match_priority( $b ) ? $a : $b;
	}

	/**
	 * Joins unique equivalence origin sizes.
	 *
	 * @param string $a First size.
	 * @param string $b Second size.
	 */
	private static function join_equiv_from( string $a, string $b ): string {
		$items = array_filter( array_unique( array( $a, $b ) ), static fn( string $s ): bool => '' !== $s );
		return implode( ' / ', $items );
	}

	/**
	 * Sorts compatible motorcycle hits alphabetically by brand, model, years.
	 *
	 * @param list<array<string,mixed>> $items Hits.
	 * @return list<array<string,mixed>>
	 */
	private static function sort_moto_hits( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$c = strcmp( (string) $a['brand'], (string) $b['brand'] );
				if ( 0 !== $c ) {
					return $c;
				}
				$c = strcmp( (string) $a['model'], (string) $b['model'] );
				if ( 0 !== $c ) {
					return $c;
				}
				return strcmp( (string) $b['years'], (string) $a['years'] );
			}
		);
		return $items;
	}

	/**
	 * Empty non-compatible evaluation result.
	 *
	 * @return array{compatible:bool,positions:list<string>,match:string,caution:bool,equivalent_from:string}
	 */
	private static function no_match(): array {
		return array(
			'compatible'      => false,
			'positions'       => array(),
			'match'           => '',
			'caution'         => false,
			'equivalent_from' => '',
		);
	}
}
