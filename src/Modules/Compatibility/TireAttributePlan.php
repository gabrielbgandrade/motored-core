<?php
/**
 * Pure translation of the "Tire" product box form into the global attribute terms of a product.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a shop owner types (position + the size as printed on the sidewall + a few optional specs) into the
 * term of every global attribute that Compatibility and the Catalog Filters read. No WordPress calls here, so it is
 * unit tested; ProductTireAdmin applies the result to the product.
 */
final class TireAttributePlan {

	/**
	 * Attributes owned by the tire box (unprefixed slugs). Anything else on the product is left untouched.
	 */
	public const MANAGED = array(
		TireSpecs::ATTR_POSITION,
		TireSpecs::ATTR_FRONT_SIZE,
		TireSpecs::ATTR_REAR_SIZE,
		TireSpecs::ATTR_WIDTH,
		TireSpecs::ATTR_PROFILE,
		TireSpecs::ATTR_RIM,
		TireSpecs::ATTR_CONSTRUCTION,
		TireSpecs::ATTR_SPEED_RATING,
		TireSpecs::ATTR_LOAD_INDEX,
		TireSpecs::ATTR_TUBE,
		TireSpecs::ATTR_USAGE,
		TireSpecs::ATTR_LINE,
	);

	/**
	 * Box position choices -> term slug of the Position attribute.
	 */
	public const POSITION_TERMS = array(
		TireSpecs::POS_FRONT => 'delantero',
		TireSpecs::POS_REAR  => 'trasero',
		TireSpecs::POS_BOTH  => 'delantero-trasero',
		TireSpecs::POS_KIT   => 'kit',
	);

	/**
	 * Builds the plan.
	 *
	 * Single tires: `size` + optional `load`, `speed`, `construction`, `tube`, `line`.
	 * Kits: the same keys describe the front tire; `rear_size`, `rear_line`, `rear_load`, `rear_speed`,
	 * `rear_construction`, `rear_tube` describe the rear tire. Load/speed typed after a size ("130/70-17 62H")
	 * fill the empty fields.
	 *
	 * @param array<string,string> $in Raw (already sanitized) form values.
	 * @return array{errors:list<string>,terms:array<string,array{name:string,slug:string}|null>,extra_terms:array<string,list<array{name:string,slug:string}>>,spec:array<string,mixed>|null}
	 *         `terms` has one entry per managed attribute: a term to assign, or null to clear the attribute.
	 *         `extra_terms` adds more terms to an attribute (a kit with a different model per axle).
	 *         `spec` is the per-axle kit sheet (KitSpec::META) or null for single tires.
	 */
	public static function build( array $in ): array {
		$errors = array();
		$terms  = array();
		foreach ( self::MANAGED as $slug ) {
			$terms[ $slug ] = null;
		}

		$position = TireSpecs::normalize_position( (string) ( $in['position'] ?? '' ) );
		if ( '' === $position ) {
			return self::result( array( __( 'Choose where this tire goes: front, rear, both axles or a front + rear kit.', 'motored-core' ) ), $terms );
		}

		$fixed = AttributeInstaller::fixed_term_sets();
		$pos_t = self::POSITION_TERMS[ $position ];

		$terms[ TireSpecs::ATTR_POSITION ] = array(
			'name' => (string) ( $fixed[ TireSpecs::ATTR_POSITION ][ $pos_t ] ?? $pos_t ),
			'slug' => $pos_t,
		);

		if ( TireSpecs::POS_KIT === $position ) {
			return self::build_kit( $in, $terms, $fixed );
		}

		$front_raw = trim( (string) ( $in['size'] ?? '' ) );
		$front     = '' !== $front_raw ? SizeParser::designation( $front_raw ) : null;
		$parsed    = '' !== $front_raw ? SizeParser::parse( $front_raw ) : null;
		if ( null === $front || null === $parsed ) {
			return self::result( array( __( 'Enter a valid tire size, as printed on the tire (for example 160/60ZR17 or 110/80-19).', 'motored-core' ) ), $terms );
		}

		if ( TireSpecs::POS_REAR === $position ) {
			$terms[ TireSpecs::ATTR_REAR_SIZE ] = self::size_term( $parsed['canonical'] );
		} else {
			$terms[ TireSpecs::ATTR_FRONT_SIZE ] = self::size_term( $parsed['canonical'] );
		}

		// Single tires publish their dimensions so the "by size" catalog filters find them.
		$terms[ TireSpecs::ATTR_WIDTH ] = self::plain_term( $parsed['width'] );
		$terms[ TireSpecs::ATTR_RIM ]   = self::plain_term( (string) $parsed['rim'] );
		if ( null !== $parsed['aspect'] ) {
			$terms[ TireSpecs::ATTR_PROFILE ] = self::plain_term( (string) $parsed['aspect'] );
		}

		$const = TireSpecs::normalize_construction( (string) ( $in['construction'] ?? '' ) );
		if ( '' === $const ) {
			$const = $front['construction'];
		}
		$terms[ TireSpecs::ATTR_CONSTRUCTION ] = self::fixed_term( $fixed, TireSpecs::ATTR_CONSTRUCTION, $const );

		$suffix = TireSpecs::parse_load_speed_suffix( $front_raw );
		$speed  = strtoupper( trim( (string) ( $in['speed'] ?? '' ) ) );
		if ( '' === $speed ) {
			$speed = $suffix['speed'];
		}
		if ( '' === $speed && 1 === preg_match( '/\d{2,3}\s*\/\s*\d{2,3}\s*(ZR|VR)\s*\d/i', $front_raw, $sm ) ) {
			$speed = strtoupper( $sm[1] );
		}
		if ( '' !== $speed ) {
			if ( isset( TireSpecs::SPEED_RANKS[ $speed ] ) ) {
				$terms[ TireSpecs::ATTR_SPEED_RATING ] = self::speed_term( $speed );
			} else {
				$errors[] = __( 'Unknown speed rating.', 'motored-core' );
			}
		}

		$load_raw = trim( (string) ( $in['load'] ?? '' ) );
		$load     = '' !== $load_raw ? TireSpecs::parse_load_index( $load_raw ) : $suffix['load'];
		if ( '' !== $load_raw && null === $load ) {
			$errors[] = __( 'The load index must be a number between 20 and 120.', 'motored-core' );
		} elseif ( null !== $load ) {
			$terms[ TireSpecs::ATTR_LOAD_INDEX ] = self::plain_term( (string) $load );
		}

		$terms[ TireSpecs::ATTR_TUBE ]  = self::fixed_term( $fixed, TireSpecs::ATTR_TUBE, (string) ( $in['tube'] ?? '' ) );
		$terms[ TireSpecs::ATTR_USAGE ] = self::fixed_term( $fixed, TireSpecs::ATTR_USAGE, (string) ( $in['usage'] ?? '' ) );
		$terms[ TireSpecs::ATTR_LINE ]  = self::line_term( (string) ( $in['line'] ?? '' ) );

		return self::result( $errors, $terms );
	}

	/**
	 * Kit branch: both sizes are required; each axle keeps its own ratings in the spec meta.
	 *
	 * Attributes never get kit load/speed/width/profile/rim (the Catalog Filters facets exclude kits from them, so a
	 * term would make the native WooCommerce filter disagree with the facet counts). Construction and tube are only
	 * set when both axles agree, so "Radial" never lists a half-radial kit.
	 *
	 * @param array<string,string>                              $in    Form values.
	 * @param array<string,array{name:string,slug:string}|null> $terms Terms with the position already set.
	 * @param array<string,array<int|string,string>>            $fixed Fixed term labels.
	 * @return array{errors:list<string>,terms:array<string,array{name:string,slug:string}|null>,extra_terms:array<string,list<array{name:string,slug:string}>>,spec:array<string,mixed>|null}
	 */
	private static function build_kit( array $in, array $terms, array $fixed ): array {
		$errors = array();
		$axles  = array();
		foreach ( array(
			TireSpecs::POS_FRONT => '',
			TireSpecs::POS_REAR  => 'rear_',
		) as $axle => $prefix ) {
			$size_key = TireSpecs::POS_FRONT === $axle ? 'size' : 'rear_size';
			$raw_size = trim( (string) ( $in[ $size_key ] ?? '' ) );
			$load_raw = trim( (string) ( $in[ $prefix . 'load' ] ?? '' ) );
			$speed    = strtoupper( trim( (string) ( $in[ $prefix . 'speed' ] ?? '' ) ) );
			$built    = '' !== $raw_size
				? KitSpec::axle(
					$raw_size,
					(string) ( $in[ $prefix . 'line' ] ?? '' ),
					$load_raw,
					$speed,
					(string) ( $in[ $prefix . 'construction' ] ?? '' ),
					(string) ( $in[ $prefix . 'tube' ] ?? '' )
				)
				: null;
			if ( null === $built ) {
				$errors[] = TireSpecs::POS_FRONT === $axle
					? __( 'Enter a valid front size for the kit, as printed on the tire (for example 120/70ZR17).', 'motored-core' )
					: __( 'Enter a valid rear size for the kit, as printed on the tire (for example 160/60ZR17).', 'motored-core' );
				continue;
			}
			if ( '' !== $load_raw && null === $built['load'] ) {
				$errors[] = __( 'The load index must be a number between 20 and 120.', 'motored-core' );
			}
			if ( '' !== $speed && '' === $built['speed'] ) {
				$errors[] = __( 'Unknown speed rating.', 'motored-core' );
			}
			$axles[ $axle ] = $built;
		}

		if ( ! isset( $axles[ TireSpecs::POS_FRONT ], $axles[ TireSpecs::POS_REAR ] ) ) {
			return self::result( $errors, $terms );
		}

		$spec = array(
			'v'                  => KitSpec::VERSION,
			TireSpecs::POS_FRONT => $axles[ TireSpecs::POS_FRONT ],
			TireSpecs::POS_REAR  => $axles[ TireSpecs::POS_REAR ],
		);

		$terms[ TireSpecs::ATTR_FRONT_SIZE ]   = self::size_term( $axles[ TireSpecs::POS_FRONT ]['size'] );
		$terms[ TireSpecs::ATTR_REAR_SIZE ]    = self::size_term( $axles[ TireSpecs::POS_REAR ]['size'] );
		$terms[ TireSpecs::ATTR_CONSTRUCTION ] = self::fixed_term( $fixed, TireSpecs::ATTR_CONSTRUCTION, KitSpec::shared( $spec, 'construction' ) );
		$terms[ TireSpecs::ATTR_TUBE ]         = self::fixed_term( $fixed, TireSpecs::ATTR_TUBE, KitSpec::shared( $spec, 'tube' ) );
		$terms[ TireSpecs::ATTR_USAGE ]        = self::fixed_term( $fixed, TireSpecs::ATTR_USAGE, (string) ( $in['usage'] ?? '' ) );

		// Model: one term when both axles share it (or only one says it), two when the kit mixes models.
		$lines = array();
		foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $axle ) {
			$term = self::line_term( $axles[ $axle ]['line'] );
			if ( null !== $term ) {
				$lines[ $term['slug'] ] = $term;
			}
		}
		$lines                         = array_values( $lines );
		$terms[ TireSpecs::ATTR_LINE ] = $lines[0] ?? null;
		$extra                         = isset( $lines[1] ) ? array( TireSpecs::ATTR_LINE => array( $lines[1] ) ) : array();

		return self::result( $errors, $terms, $extra, $spec );
	}

	/**
	 * Packs a plan result.
	 *
	 * @param array                                              $errors Errors.
	 * @param array<string,array{name:string,slug:string}|null>  $terms  Terms.
	 * @param array<string,list<array{name:string,slug:string}>> $extra  Additional terms per attribute.
	 * @param array<string,mixed>|null                           $spec   Kit spec.
	 * @phpstan-param list<string> $errors
	 * @return array{errors:list<string>,terms:array<string,array{name:string,slug:string}|null>,extra_terms:array<string,list<array{name:string,slug:string}>>,spec:array<string,mixed>|null}
	 */
	private static function result( array $errors, array $terms, array $extra = array(), ?array $spec = null ): array {
		return array(
			'errors'      => $errors,
			'terms'       => $terms,
			'extra_terms' => $extra,
			'spec'        => $spec,
		);
	}

	/**
	 * Term of a fixed-term attribute (construction, tube, usage), or null for an empty / unknown value.
	 *
	 * @param array<string,array<int|string,string>> $fixed Fixed term labels.
	 * @param string                                 $attr  Attribute slug.
	 * @param string                                 $value Term slug.
	 * @return array{name:string,slug:string}|null
	 */
	private static function fixed_term( array $fixed, string $attr, string $value ): ?array {
		if ( '' === $value || ! isset( $fixed[ $attr ][ $value ] ) ) {
			return null;
		}
		return array(
			'name' => (string) $fixed[ $attr ][ $value ],
			'slug' => $value,
		);
	}

	/**
	 * Speed rating term ("(W)" uses the slug "w-par").
	 *
	 * @param string $speed ETRTO speed code.
	 * @return array{name:string,slug:string}
	 */
	private static function speed_term( string $speed ): array {
		return array(
			'name' => $speed,
			'slug' => '(W)' === $speed ? 'w-par' : strtolower( $speed ),
		);
	}

	/**
	 * Model (line) term, or null when empty.
	 *
	 * @param string $line Tire model.
	 * @return array{name:string,slug:string}|null
	 */
	private static function line_term( string $line ): ?array {
		$line = trim( $line );
		$slug = '' !== $line ? AttributeInstaller::size_slug( $line ) : '';
		if ( '' === $slug ) {
			return null;
		}
		return array(
			'name' => $line,
			'slug' => $slug,
		);
	}

	/**
	 * Size term: canonical name, deterministic slug shared with AttributeInstaller::sync_size_term().
	 *
	 * @param string $canonical Canonical size (e.g. "160/60-17").
	 * @return array{name:string,slug:string}
	 */
	private static function size_term( string $canonical ): array {
		return array(
			'name' => $canonical,
			'slug' => AttributeInstaller::size_slug( $canonical ),
		);
	}

	/**
	 * Numeric-ish term (width, profile, rim, load) whose slug is the value itself ("4.10" -> "4-10").
	 *
	 * @param string $value Term name.
	 * @return array{name:string,slug:string}
	 */
	private static function plain_term( string $value ): array {
		return array(
			'name' => $value,
			'slug' => AttributeInstaller::size_slug( $value ),
		);
	}
}
