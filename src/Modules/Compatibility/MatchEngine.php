<?php
/**
 * Motorcycle-to-tire matching engine (1-hop directed equivalences, kit dual-axle, optional strict specs).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Computes bike profiles, evaluates product fitments and reverses product specs into compatible bikes.
 */
final class MatchEngine {

	/**
	 * Per-request memo of matching product id lists (never persisted; the cache bucket stays the source).
	 *
	 * @var array<string,list<int>>
	 */
	private static array $ids_memo = array();


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
		$cached = CompatCache::remember(
			'bike_' . md5( $clean ),
			static fn(): array => array( 'p' => self::build_bike_profile( $clean ) ),
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return is_array( $cached['p'] ?? null ) ? $cached['p'] : null;
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
		$pos   = (string) ( $product_fit['position'] ?? '' );
		$front = is_array( $bike_profile['front'] ?? null ) ? $bike_profile['front'] : array();
		$rear  = is_array( $bike_profile['rear'] ?? null ) ? $bike_profile['rear'] : array();
		$const = isset( $product_fit['construction'] ) && is_string( $product_fit['construction'] ) ? $product_fit['construction'] : '';

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
			$fc = self::check_constraints( $front[ $f_size ], $f_load, $f_speed, $const, $strict_specs );
			$rc = self::check_constraints( $rear[ $r_size ], $r_load, $r_speed, $const, $strict_specs );
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
			$fc = self::check_constraints( $front[ $f_size ], $f_load, $f_speed, $const, $strict_specs );
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
			$rc = self::check_constraints( $rear[ $r_size ], $r_load, $r_speed, $const, $strict_specs );
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
	 * @param string $version_slug Published version slug.
	 * @param string $pos_filter   Optional '' | 'front' | 'rear' | 'kit'.
	 * @return list<int>
	 */
	public static function matching_product_ids( string $version_slug, string $pos_filter = '' ): array {
		$clean_slug = sanitize_title( $version_slug );
		$clean_pos  = in_array( $pos_filter, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ), true ) ? $pos_filter : '';
		if ( '' === $clean_slug ) {
			return array();
		}
		$strict = Config::strict_specs();
		$key    = 'mids2_' . md5( $clean_slug . '|' . $clean_pos . '|' . ( $strict ? '1' : '0' ) );

		// Per-request memo: the shop query, the facet sidebar and the active-bike bar ask for the same list.
		if ( isset( self::$ids_memo[ $key ] ) ) {
			return self::$ids_memo[ $key ];
		}

		self::$ids_memo[ $key ] = CompatCache::remember(
			$key,
			static function () use ( $clean_slug, $clean_pos, $strict ): array {
				$profile = self::bike_profile( $clean_slug );
				if ( null === $profile ) {
					return array();
				}
				$front_ids = array();
				foreach ( $profile['front'] as $item ) {
					$front_ids[] = (int) $item['size_id'];
				}
				$rear_ids = array();
				foreach ( $profile['rear'] as $item ) {
					$rear_ids[] = (int) $item['size_id'];
				}
				$candidates = ProductIndexRepository::query_candidates_for_sizes( $front_ids, $rear_ids, $clean_pos );
				$ranked     = array();
				foreach ( $candidates as $row ) {
					$eval = self::evaluate_product( $row, $profile, $strict );
					if ( $eval['compatible'] ) {
						$ranked[] = array( (int) $row['product_id'], self::match_priority( $eval['match'] ) );
					}
				}
				// Best fit first (OEM > alternative > equivalent); usort is stable, so ties keep index order.
				usort( $ranked, static fn( array $a, array $b ): int => $b[1] <=> $a[1] );
				return array_map( static fn( array $r ): int => $r[0], $ranked );
			},
			CompatCache::BUCKET_MATCHES,
			CompatCache::TTL_MATCHES
		);
		return self::$ids_memo[ $key ];
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
		$spec_key = 'pm_' . md5(
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
	 * "Benelli TRK 502 2017–actualidad" or "Honda CBR900RR Fireblade SC28 1992–1995".
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
	 * Formats a compact, human-readable year range label ("2001–2024" or "2020–actualidad").
	 *
	 * @param int $year_from Start year.
	 * @param int $year_to   End year (0 = present).
	 */
	public static function format_years( int $year_from, int $year_to ): string {
		if ( 0 === $year_to ) {
			return $year_from . '–' . __( 'present', 'motored-core' );
		}
		if ( $year_from === $year_to ) {
			return (string) $year_from;
		}
		return $year_from . '–' . $year_to;
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
		$fitments = CatalogRepository::fitments_for_version( (int) $ver['id'] );

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
			$equivs = CatalogRepository::equivalences_from_sizes( array_values( array_unique( $oem_ids[ $pos ] ) ), $pos );
			foreach ( $equivs as $eq ) {
				$dst_canon = $eq['target_canonical'];
				// Direct OEM or ALT fitment on this bike always beats an equivalence entry.
				if ( isset( $axes[ $pos ][ $dst_canon ] ) ) {
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
		$pos   = (string) $fit['position'];
		$const = isset( $fit['construction'] ) && is_string( $fit['construction'] ) ? $fit['construction'] : '';

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
				$const,
				$strict
			);
			$rear_hits  = self::index_hits_by_version(
				ProductIndexRepository::query_version_fitments_for_tire_size( $r_id, TireSpecs::POS_REAR ),
				$fit['rear_load'],
				$fit['rear_speed_rank'],
				$const,
				$strict
			);
			$out        = array();
			foreach ( $front_hits as $vid => $fh ) {
				if ( ! isset( $rear_hits[ $vid ] ) ) {
					continue;
				}
				$rh    = $rear_hits[ $vid ];
				$out[] = array(
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
				$const,
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
				$const,
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
		$map = array();
		foreach ( $rows as $row ) {
			$req = array(
				'min_load'  => null !== $row['min_load'] ? (int) $row['min_load'] : null,
				'min_speed' => null !== $row['min_speed_rank'] ? (int) $row['min_speed_rank'] : null,
				'req_const' => null !== $row['req_construction'] ? (string) $row['req_construction'] : null,
			);
			$chk = self::check_constraints( $req, $load, $speed_rank, $construction, $strict );
			if ( ! $chk['ok'] ) {
				continue;
			}
			$vid  = (int) $row['version_id'];
			$type = (string) $row['fit_type'];
			if ( isset( $map[ $vid ] ) && self::match_priority( $map[ $vid ]['match'] ) >= self::match_priority( $type ) ) {
				continue;
			}
			$map[ $vid ] = array(
				'slug'       => (string) $row['version_slug'],
				'brand'      => (string) $row['brand_name'],
				'model'      => (string) $row['model_name'],
				'aliases'    => (string) $row['aliases'],
				'years'      => self::format_years( (int) $row['year_from'], (int) $row['year_to'] ),
				'variant'    => (string) $row['variant'],
				'match'      => $type,
				'equiv_from' => (string) ( $row['equiv_from'] ?? '' ),
				'caution'    => $chk['caution'],
			);
		}
		return $map;
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
