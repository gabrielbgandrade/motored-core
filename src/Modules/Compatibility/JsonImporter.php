<?php
/**
 * Schema-validated JSON importer with dry-run preview and chunked UPSERT.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and imports schema_version 1 JSON payloads (brands/models/versions/fitments + equivalences).
 */
final class JsonImporter {

	public const MAX_BYTES      = 2097152; // 2 MB.
	public const SCHEMA_VERSION = 1;

	/**
	 * Decodes and validates top-level JSON structure and byte size.
	 *
	 * @param string $raw_json Raw JSON string.
	 * @return array{ok:bool,error:string,brands:list<array<string,mixed>>,equivalences:list<array<string,mixed>>,versions_count:int}
	 */
	public static function parse_payload( string $raw_json ): array {
		if ( '' === trim( $raw_json ) ) {
			return self::parse_error( __( 'The JSON payload is empty.', 'motored-core' ) );
		}
		if ( strlen( $raw_json ) > self::MAX_BYTES ) {
			return self::parse_error( __( 'The JSON payload exceeds the 2 MB maximum size.', 'motored-core' ) );
		}
		$decoded = json_decode( $raw_json, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return self::parse_error( __( 'Invalid JSON syntax.', 'motored-core' ) );
		}
		$ver = isset( $decoded['schema_version'] ) ? (int) $decoded['schema_version'] : 0;
		if ( self::SCHEMA_VERSION !== $ver ) {
			return self::parse_error( __( 'Unsupported or missing schema_version (expected 1).', 'motored-core' ) );
		}
		$brands = isset( $decoded['brands'] ) && is_array( $decoded['brands'] ) ? array_values( $decoded['brands'] ) : array();
		$equivs = isset( $decoded['equivalences'] ) && is_array( $decoded['equivalences'] ) ? array_values( $decoded['equivalences'] ) : array();

		$versions_count = 0;
		foreach ( $brands as $b ) {
			if ( ! is_array( $b ) || empty( $b['models'] ) || ! is_array( $b['models'] ) ) {
				continue;
			}
			foreach ( $b['models'] as $m ) {
				if ( is_array( $m ) && isset( $m['versions'] ) && is_array( $m['versions'] ) ) {
					$versions_count += count( $m['versions'] );
				}
			}
		}

		return array(
			'ok'             => true,
			'error'          => '',
			'brands'         => $brands,
			'equivalences'   => $equivs,
			'versions_count' => $versions_count,
		);
	}

	/**
	 * Performs a dry-run simulation without mutating any database tables.
	 *
	 * @param string                                                                   $raw_json Raw JSON string.
	 * @param array{default_status?:string,import_constraints?:bool,force_draft?:bool} $options  Import options.
	 * @return array<string,mixed>
	 */
	public static function dry_run( string $raw_json, array $options = array() ): array {
		$parsed = self::parse_payload( $raw_json );
		$counts = self::empty_counts();
		if ( ! $parsed['ok'] ) {
			$counts['ok']         = false;
			$counts['messages'][] = $parsed['error'];
			return $counts;
		}

		$default_status     = ( isset( $options['default_status'] ) && 'published' === $options['default_status'] ) ? 'published' : 'draft';
		$force_draft        = ! empty( $options['force_draft'] );
		$import_constraints = ! empty( $options['import_constraints'] );
		$seen_sizes         = array();
		$seen_ranges        = array(); // key: brand_slug|model_slug|variant_slug => list of [from, to].

		foreach ( $parsed['brands'] as $b_idx => $brand ) {
			if ( ! is_array( $brand ) || '' === trim( (string) ( $brand['name'] ?? '' ) ) ) {
				++$counts['rejected'];
				self::add_msg( $counts, sprintf( 'Brand #%d skipped: missing name.', $b_idx ) );
				continue;
			}
			$b_name = trim( (string) $brand['name'] );
			$b_slug = sanitize_title( (string) ( $brand['slug'] ?? $b_name ) );
			if ( '' === $b_slug ) {
				++$counts['rejected'];
				continue;
			}
			$existing_brand = CatalogRepository::find_brand_by_slug( $b_slug );
			if ( null !== $existing_brand ) {
				++$counts['brands_existing'];
			} else {
				++$counts['brands_new'];
			}

			$models = isset( $brand['models'] ) && is_array( $brand['models'] ) ? $brand['models'] : array();
			foreach ( $models as $m_idx => $model ) {
				if ( ! is_array( $model ) || '' === trim( (string) ( $model['name'] ?? '' ) ) ) {
					++$counts['rejected'];
					self::add_msg( $counts, sprintf( '%s model #%d skipped: missing name.', $b_name, $m_idx ) );
					continue;
				}
				$m_name         = trim( (string) $model['name'] );
				$m_slug         = sanitize_title( (string) ( $model['slug'] ?? $m_name ) );
				$existing_model = null !== $existing_brand ? CatalogRepository::find_model( $existing_brand['id'], $m_slug ) : null;
				if ( null !== $existing_model ) {
					++$counts['models_existing'];
				} else {
					++$counts['models_new'];
				}

				$versions = isset( $model['versions'] ) && is_array( $model['versions'] ) ? $model['versions'] : array();
				foreach ( $versions as $v ) {
					if ( ! is_array( $v ) ) {
						++$counts['rejected'];
						continue;
					}
					$year_from = (int) ( $v['year_from'] ?? 0 );
					$year_to   = (int) ( $v['year_to'] ?? 0 );
					if ( $year_from < 1950 || $year_from > 2100 || ( $year_to > 0 && $year_to < $year_from ) ) {
						++$counts['rejected'];
						self::add_msg( $counts, sprintf( '%s %s skipped: invalid years %d-%d.', $b_name, $m_name, $year_from, $year_to ) );
						continue;
					}
					$variant      = CatalogRepository::clean_variant( (string) ( $v['variant'] ?? '' ) );
					$variant_slug = '' !== $variant ? sanitize_title( $variant ) : '';
					$status       = $force_draft
						? 'draft'
						: ( isset( $v['status'] ) && in_array( $v['status'], array( 'draft', 'published' ), true ) ? (string) $v['status'] : $default_status );
					$verified     = ! empty( $v['verified'] ) ? 1 : 0;
					$notes        = trim( (string) ( $v['notes'] ?? '' ) );
					if ( 'published' === $status ) {
						++$counts['versions_published'];
					} else {
						++$counts['versions_draft'];
					}

					$existing_ver = null !== $existing_model
						? CatalogRepository::find_version_by_natural_key( $existing_model['id'], $year_from, $year_to, $variant_slug )
						: null;

					if ( null !== $existing_ver ) {
						$unchanged = ( (string) $existing_ver['status'] === $status && (int) $existing_ver['verified'] === $verified && (string) $existing_ver['notes'] === $notes );
						if ( $unchanged ) {
							++$counts['versions_unchanged'];
						} else {
							++$counts['versions_updated'];
						}
					} else {
						++$counts['versions_new'];
						if ( null !== $existing_model && CatalogRepository::has_year_overlap( $existing_model['id'], $variant_slug, $year_from, $year_to ) ) {
							++$counts['overlaps'];
							self::add_msg( $counts, sprintf( 'Year overlap in DB: %s %s (%d-%d).', $b_name, $m_name, $year_from, $year_to ) );
						}
					}

					$range_key = $b_slug . '|' . $m_slug . '|' . $variant_slug;
					foreach ( $seen_ranges[ $range_key ] ?? array() as $prev ) {
						if ( $prev[0] === $year_from && $prev[1] === $year_to ) {
							continue;
						}
						if ( CatalogRepository::ranges_overlap( $year_from, $year_to, $prev[0], $prev[1] ) ) {
							++$counts['overlaps'];
							self::add_msg( $counts, sprintf( 'Year overlap in file: %s %s (%d-%d vs %d-%d).', $b_name, $m_name, $year_from, $year_to, $prev[0], $prev[1] ) );
						}
					}
					$seen_ranges[ $range_key ][] = array( $year_from, $year_to );

					$fitments      = self::normalize_fitments( $v, $import_constraints );
					$existing_fits = array();
					if ( null !== $existing_ver ) {
						foreach ( CatalogRepository::fitments_for_version( (int) $existing_ver['id'] ) as $ef ) {
							$existing_fits[ $ef['position'] . '|' . $ef['canonical'] ] = true;
						}
					}
					foreach ( $fitments as $fit ) {
						if ( ! $fit['valid'] ) {
							++$counts['rejected'];
							self::add_msg( $counts, sprintf( '%s %s invalid size "%s".', $b_name, $m_name, $fit['raw_size'] ) );
							continue;
						}
						if ( $fit['suspicious'] ) {
							++$counts['suspicious'];
							self::add_msg( $counts, sprintf( '%s %s suspicious rim in "%s".', $b_name, $m_name, $fit['canonical'] ) );
						}
						if ( ! isset( $seen_sizes[ $fit['canonical'] ] ) ) {
							$seen_sizes[ $fit['canonical'] ] = true;
							if ( null === CatalogRepository::find_size_by_canonical( $fit['canonical'] ) ) {
								++$counts['sizes_new'];
							}
						}
						$fkey = $fit['position'] . '|' . $fit['canonical'];
						if ( isset( $existing_fits[ $fkey ] ) ) {
							++$counts['fitments_updated'];
						} else {
							++$counts['fitments_new'];
						}
					}
				}
			}
		}

		foreach ( $parsed['equivalences'] as $eq ) {
			if ( ! is_array( $eq ) ) {
				++$counts['rejected'];
				continue;
			}
			$src = SizeParser::parse( (string) ( $eq['source'] ?? '' ) );
			$dst = SizeParser::parse( (string) ( $eq['target'] ?? '' ) );
			if ( null === $src || null === $dst || $src['canonical'] === $dst['canonical'] ) {
				++$counts['rejected'];
				self::add_msg( $counts, sprintf( 'Invalid equivalence: %s -> %s.', (string) ( $eq['source'] ?? '' ), (string) ( $eq['target'] ?? '' ) ) );
				continue;
			}
			if ( $src['rim'] !== $dst['rim'] ) {
				++$counts['rejected'];
				self::add_msg( $counts, sprintf( 'Invalid equivalence (rim mismatch): %s -> %s.', (string) ( $eq['source'] ?? '' ), (string) ( $eq['target'] ?? '' ) ) );
				continue;
			}
			if ( SizeParser::is_suspicious_rim( $src['rim'] ) || SizeParser::is_suspicious_rim( $dst['rim'] ) ) {
				++$counts['suspicious'];
			}
			foreach ( array( $src['canonical'], $dst['canonical'] ) as $c ) {
				if ( ! isset( $seen_sizes[ $c ] ) ) {
					$seen_sizes[ $c ] = true;
					if ( null === CatalogRepository::find_size_by_canonical( $c ) ) {
						++$counts['sizes_new'];
					}
				}
			}
			++$counts['equivalences_new'];
			if ( ! empty( $eq['create_inverse'] ) ) {
				++$counts['equivalences_new'];
			}
		}

		return $counts;
	}

	/**
	 * Flattens a parsed payload into discrete version items + equivalences so batch runners can slice at exact 50-version boundaries.
	 *
	 * @param array{brands:list<array<string,mixed>>,equivalences:list<array<string,mixed>>} $parsed Parsed payload.
	 * @return array{versions:list<array<string,mixed>>,equivalences:list<array<string,mixed>>}
	 */
	public static function flatten_items( array $parsed ): array {
		$versions = array();
		foreach ( $parsed['brands'] as $brand ) {
			if ( ! is_array( $brand ) ) {
				continue;
			}
			$b_name = trim( (string) ( $brand['name'] ?? '' ) );
			$b_slug = sanitize_title( (string) ( $brand['slug'] ?? $b_name ) );
			if ( '' === $b_name || '' === $b_slug ) {
				continue;
			}
			$models = isset( $brand['models'] ) && is_array( $brand['models'] ) ? $brand['models'] : array();
			foreach ( $models as $model ) {
				if ( ! is_array( $model ) ) {
					continue;
				}
				$m_name = trim( (string) ( $model['name'] ?? '' ) );
				$m_slug = sanitize_title( (string) ( $model['slug'] ?? $m_name ) );
				if ( '' === $m_name || '' === $m_slug ) {
					continue;
				}
				$cc      = isset( $model['cc'] ) && is_numeric( $model['cc'] ) ? max( 1, (int) $model['cc'] ) : null;
				$aliases = trim( (string) ( $model['aliases'] ?? '' ) );
				$m_vers  = isset( $model['versions'] ) && is_array( $model['versions'] ) ? $model['versions'] : array();
				foreach ( $m_vers as $ver ) {
					if ( ! is_array( $ver ) ) {
						continue;
					}
					$versions[] = array(
						'brand_name' => $b_name,
						'brand_slug' => $b_slug,
						'model_name' => $m_name,
						'model_slug' => $m_slug,
						'cc'         => $cc,
						'aliases'    => $aliases,
						'version'    => $ver,
					);
				}
			}
		}

		return array(
			'versions'     => $versions,
			'equivalences' => $parsed['equivalences'],
		);
	}

	/**
	 * Imports a flattened chunk of versions and/or equivalences via idempotent UPSERT.
	 *
	 * @param list<array<string,mixed>>                                                                     $versions_chunk Version items from flatten_items().
	 * @param list<array<string,mixed>>                                                                     $equivs_chunk Equivalences list.
	 * @param array{default_status?:string,import_constraints?:bool,allow_overlaps?:bool,force_draft?:bool} $options Import options.
	 * @return array{versions:int,fitments:int,equivalences:int,rejected:int}
	 */
	public static function import_chunk( array $versions_chunk, array $equivs_chunk = array(), array $options = array() ): array {
		$default_status     = ( isset( $options['default_status'] ) && 'published' === $options['default_status'] ) ? 'published' : 'draft';
		$force_draft        = ! empty( $options['force_draft'] );
		$import_constraints = ! empty( $options['import_constraints'] );
		$stats              = array(
			'versions'     => 0,
			'fitments'     => 0,
			'equivalences' => 0,
			'rejected'     => 0,
		);

		foreach ( $versions_chunk as $item ) {
			$v         = is_array( $item['version'] ?? null ) ? $item['version'] : array();
			$year_from = (int) ( $v['year_from'] ?? 0 );
			$year_to   = (int) ( $v['year_to'] ?? 0 );
			if ( $year_from < 1950 || $year_from > 2100 || ( $year_to > 0 && $year_to < $year_from ) ) {
				++$stats['rejected'];
				continue;
			}
			$brand_id = CatalogRepository::upsert_brand( (string) $item['brand_name'], (string) $item['brand_slug'] );
			if ( $brand_id <= 0 ) {
				++$stats['rejected'];
				continue;
			}
			$model_id = CatalogRepository::upsert_model(
				$brand_id,
				(string) $item['model_name'],
				(string) $item['model_slug'],
				null !== $item['cc'] ? (int) $item['cc'] : null,
				(string) $item['aliases']
			);
			if ( $model_id <= 0 ) {
				++$stats['rejected'];
				continue;
			}

			$variant      = CatalogRepository::clean_variant( (string) ( $v['variant'] ?? '' ) );
			$variant_slug = '' !== $variant ? sanitize_title( $variant ) : '';
			$existing_ver = CatalogRepository::find_version_by_natural_key( $model_id, $year_from, $year_to, $variant_slug );
			if ( null === $existing_ver && empty( $options['allow_overlaps'] ) && CatalogRepository::has_year_overlap( $model_id, $variant_slug, $year_from, $year_to ) ) {
				++$stats['rejected'];
				continue;
			}

			$status     = $force_draft
				? 'draft'
				: ( isset( $v['status'] ) && in_array( $v['status'], array( 'draft', 'published' ), true ) ? (string) $v['status'] : $default_status );
			$version_id = CatalogRepository::upsert_version(
				array(
					'model_id'   => $model_id,
					'brand_slug' => (string) $item['brand_slug'],
					'model_slug' => (string) $item['model_slug'],
					'year_from'  => $year_from,
					'year_to'    => $year_to,
					'variant'    => $variant,
					'status'     => $status,
					'verified'   => ! empty( $v['verified'] ) ? 1 : 0,
					'notes'      => trim( (string) ( $v['notes'] ?? '' ) ),
				)
			);
			if ( $version_id <= 0 ) {
				++$stats['rejected'];
				continue;
			}
			++$stats['versions'];

			foreach ( self::normalize_fitments( $v, $import_constraints ) as $fit ) {
				if ( ! $fit['valid'] ) {
					++$stats['rejected'];
					continue;
				}
				$size = CatalogRepository::ensure_size( $fit['canonical'] );
				if ( null === $size ) {
					++$stats['rejected'];
					continue;
				}
				$fid = CatalogRepository::upsert_fitment(
					array(
						'version_id'       => $version_id,
						'position'         => $fit['position'],
						'size_id'          => $size['id'],
						'fit_type'         => $fit['fit_type'],
						'min_load'         => $fit['min_load'],
						'min_speed_rank'   => $fit['min_speed_rank'],
						'req_construction' => $fit['req_construction'],
						'designation'      => $fit['designation'],
						'source'           => $fit['source'],
						'note'             => $fit['note'],
					)
				);
				if ( $fid > 0 ) {
					++$stats['fitments'];
				}
			}
		}

		foreach ( $equivs_chunk as $eq ) {
			if ( ! is_array( $eq ) ) {
				++$stats['rejected'];
				continue;
			}
			$src = CatalogRepository::ensure_size( (string) ( $eq['source'] ?? '' ) );
			$dst = CatalogRepository::ensure_size( (string) ( $eq['target'] ?? '' ) );
			if ( null === $src || null === $dst || $src['id'] === $dst['id'] || $src['rim'] !== $dst['rim'] ) {
				++$stats['rejected'];
				continue;
			}
			$scope = in_array( (string) ( $eq['position_scope'] ?? 'any' ), array( 'any', TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true )
				? (string) $eq['position_scope']
				: 'any';
			$eid   = CatalogRepository::upsert_equivalence(
				array(
					'source_size_id' => $src['id'],
					'target_size_id' => $dst['id'],
					'position_scope' => $scope,
					'is_approved'    => ! isset( $eq['is_approved'] ) || ! empty( $eq['is_approved'] ) ? 1 : 0,
					'is_approximate' => ! isset( $eq['is_approximate'] ) || ! empty( $eq['is_approximate'] ) ? 1 : 0,
					'note'           => trim( (string) ( $eq['note'] ?? '' ) ),
				),
				! empty( $eq['create_inverse'] )
			);
			if ( $eid > 0 ) {
				++$stats['equivalences'];
			}
		}

		return $stats;
	}

	/**
	 * Normalizes fitment entries from canonical `fitments` or `front`/`rear` shorthand arrays.
	 *
	 * @param array<string,mixed> $version            Version JSON object.
	 * @param bool                $import_constraints Whether optional load/speed/construction minimums are accepted.
	 * @return list<array{valid:bool,raw_size:string,canonical:string,suspicious:bool,position:string,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,designation:string|null,source:string,note:string}>
	 */
	private static function normalize_fitments( array $version, bool $import_constraints ): array {
		$raw_list = array();
		if ( isset( $version['fitments'] ) && is_array( $version['fitments'] ) ) {
			$raw_list = $version['fitments'];
		} else {
			foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $pos ) {
				if ( isset( $version[ $pos ] ) && is_array( $version[ $pos ] ) ) {
					foreach ( $version[ $pos ] as $entry ) {
						if ( is_string( $entry ) ) {
							$raw_list[] = array(
								'position' => $pos,
								'size'     => $entry,
								'fit_type' => TireSpecs::FIT_OEM,
							);
						} elseif ( is_array( $entry ) ) {
							$entry['position'] = $pos;
							$raw_list[]        = $entry;
						}
					}
				}
			}
		}

		$out = array();
		foreach ( $raw_list as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$pos = TireSpecs::normalize_position( (string) ( $f['position'] ?? '' ) );
			if ( ! in_array( $pos, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true ) ) {
				$out[] = self::invalid_fitment( (string) ( $f['size'] ?? '' ) );
				continue;
			}
			$raw_size = trim( (string) ( $f['size'] ?? '' ) );
			$parsed   = SizeParser::parse( $raw_size );
			if ( null === $parsed ) {
				$out[] = self::invalid_fitment( $raw_size );
				continue;
			}

			// "compatible" (replacement-catalogue data, e.g. tire maker lists) is NOT first-equipment: store as alternative.
			$fit_type = in_array( strtolower( (string) ( $f['fit_type'] ?? '' ) ), array( TireSpecs::FIT_ALT, 'compatible', 'alternative' ), true )
				? TireSpecs::FIT_ALT
				: TireSpecs::FIT_OEM;
			$source   = in_array( (string) ( $f['source'] ?? '' ), array( 'manual', 'manufacturer', 'third_party' ), true )
				? (string) $f['source']
				: 'third_party';
			$note     = trim( (string) ( $f['note'] ?? '' ) );

			// The OEM designation ("120/70ZR17", "150/70R17") carries the construction rule; "-" states none.
			$desig     = SizeParser::designation( $raw_size );
			$min_load  = null;
			$min_speed = null;
			$req_const = ( null !== $desig && '' !== $desig['construction'] ) ? $desig['construction'] : null;
			if ( $import_constraints ) {
				if ( isset( $f['min_load'] ) && is_numeric( $f['min_load'] ) ) {
					$min_load = TireSpecs::parse_load_index( (string) $f['min_load'] );
				}
				if ( isset( $f['min_speed'] ) && '' !== (string) $f['min_speed'] ) {
					$min_speed = TireSpecs::speed_rank( (string) $f['min_speed'] );
				}
				if ( isset( $f['req_construction'] ) && '' !== (string) $f['req_construction'] ) {
					$norm_c    = TireSpecs::normalize_construction( (string) $f['req_construction'] );
					$req_const = '' !== $norm_c ? $norm_c : null;
				}
			}

			$out[] = array(
				'valid'            => true,
				'raw_size'         => $raw_size,
				'canonical'        => $parsed['canonical'],
				'suspicious'       => SizeParser::is_suspicious_rim( $parsed['rim'] ),
				'position'         => $pos,
				'fit_type'         => $fit_type,
				'min_load'         => $min_load,
				'min_speed_rank'   => $min_speed,
				'req_construction' => $req_const,
				'designation'      => ( null !== $desig && $desig['designation'] !== $parsed['canonical'] ) ? $desig['designation'] : null,
				'source'           => $source,
				'note'             => $note,
			);
		}
		return $out;
	}

	/**
	 * Invalid fitment placeholder.
	 *
	 * @param string $raw Raw size text.
	 * @return array{valid:bool,raw_size:string,canonical:string,suspicious:bool,position:string,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,designation:string|null,source:string,note:string}
	 */
	private static function invalid_fitment( string $raw ): array {
		return array(
			'valid'            => false,
			'raw_size'         => $raw,
			'canonical'        => '',
			'suspicious'       => false,
			'position'         => '',
			'fit_type'         => TireSpecs::FIT_OEM,
			'min_load'         => null,
			'min_speed_rank'   => null,
			'req_construction' => null,
			'designation'      => null,
			'source'           => 'third_party',
			'note'             => '',
		);
	}

	/**
	 * Builds a failed parse response.
	 *
	 * @param string $error Error text.
	 * @return array{ok:bool,error:string,brands:list<array<string,mixed>>,equivalences:list<array<string,mixed>>,versions_count:int}
	 */
	private static function parse_error( string $error ): array {
		return array(
			'ok'             => false,
			'error'          => $error,
			'brands'         => array(),
			'equivalences'   => array(),
			'versions_count' => 0,
		);
	}

	/**
	 * Initial dry-run summary array.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_counts(): array {
		return array(
			'ok'                   => true,
			'brands_new'           => 0,
			'brands_existing'      => 0,
			'models_new'           => 0,
			'models_existing'      => 0,
			'versions_new'         => 0,
			'versions_updated'     => 0,
			'versions_unchanged'   => 0,
			'versions_published'   => 0,
			'versions_draft'       => 0,
			'sizes_new'            => 0,
			'fitments_new'         => 0,
			'fitments_updated'     => 0,
			'equivalences_new'     => 0,
			'equivalences_updated' => 0,
			'rejected'             => 0,
			'suspicious'           => 0,
			'overlaps'             => 0,
			'messages'             => array(),
		);
	}

	/**
	 * Appends up to 50 diagnostic messages for the admin dry-run summary.
	 *
	 * @param array<string,mixed> $counts Summary array by ref.
	 * @param string              $msg    Message text.
	 */
	private static function add_msg( array &$counts, string $msg ): void {
		if ( count( $counts['messages'] ) < 50 ) {
			$counts['messages'][] = $msg;
		}
	}
}
