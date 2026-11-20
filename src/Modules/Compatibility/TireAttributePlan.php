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
	 * @param array{position?:string,size?:string,rear_size?:string,construction?:string,speed?:string,load?:string,tube?:string,usage?:string,line?:string} $in Raw (already sanitized) form values.
	 * @return array{errors:list<string>,terms:array<string,array{name:string,slug:string}|null>}
	 *         `terms` has one entry per managed attribute: a term to assign, or null to clear the attribute.
	 */
	public static function build( array $in ): array {
		$errors = array();
		$terms  = array();
		foreach ( self::MANAGED as $slug ) {
			$terms[ $slug ] = null;
		}

		$position = TireSpecs::normalize_position( (string) ( $in['position'] ?? '' ) );
		if ( '' === $position ) {
			return array(
				'errors' => array( __( 'Choose where this tire goes: front, rear, both axles or a front + rear kit.', 'motored-core' ) ),
				'terms'  => $terms,
			);
		}

		$fixed = AttributeInstaller::fixed_term_sets();
		$pos_t = self::POSITION_TERMS[ $position ];

		$terms[ TireSpecs::ATTR_POSITION ] = array(
			'name' => (string) ( $fixed[ TireSpecs::ATTR_POSITION ][ $pos_t ] ?? $pos_t ),
			'slug' => $pos_t,
		);

		$front_raw = trim( (string) ( $in['size'] ?? '' ) );
		$rear_raw  = TireSpecs::POS_KIT === $position ? trim( (string) ( $in['rear_size'] ?? '' ) ) : '';
		$front     = '' !== $front_raw ? SizeParser::designation( $front_raw ) : null;
		$rear      = '' !== $rear_raw ? SizeParser::designation( $rear_raw ) : null;

		if ( '' === $front_raw || null === $front ) {
			$errors[] = TireSpecs::POS_KIT === $position
				? __( 'Enter a valid front size for the kit, as printed on the tire (for example 120/70ZR17).', 'motored-core' )
				: __( 'Enter a valid tire size, as printed on the tire (for example 160/60ZR17 or 110/80-19).', 'motored-core' );
			return array(
				'errors' => $errors,
				'terms'  => $terms,
			);
		}
		if ( TireSpecs::POS_KIT === $position && ( '' === $rear_raw || null === $rear ) ) {
			$errors[] = __( 'Enter a valid rear size for the kit, as printed on the tire (for example 160/60ZR17).', 'motored-core' );
			return array(
				'errors' => $errors,
				'terms'  => $terms,
			);
		}

		$front_parsed = SizeParser::parse( $front_raw );
		if ( null === $front_parsed ) {
			return array(
				'errors' => $errors,
				'terms'  => $terms,
			);
		}

		if ( TireSpecs::POS_REAR === $position ) {
			$terms[ TireSpecs::ATTR_REAR_SIZE ] = self::size_term( $front_parsed['canonical'] );
		} else {
			$terms[ TireSpecs::ATTR_FRONT_SIZE ] = self::size_term( $front_parsed['canonical'] );
		}

		if ( TireSpecs::POS_KIT === $position ) {
			$rear_parsed = SizeParser::parse( $rear_raw );
			if ( null !== $rear_parsed ) {
				$terms[ TireSpecs::ATTR_REAR_SIZE ] = self::size_term( $rear_parsed['canonical'] );
			}
		} else {
			// Single tires publish their dimensions so the "by size" catalog filters find them.
			$terms[ TireSpecs::ATTR_WIDTH ] = self::plain_term( $front_parsed['width'] );
			$terms[ TireSpecs::ATTR_RIM ]   = self::plain_term( (string) $front_parsed['rim'] );
			if ( null !== $front_parsed['aspect'] ) {
				$terms[ TireSpecs::ATTR_PROFILE ] = self::plain_term( (string) $front_parsed['aspect'] );
			}

			$const = TireSpecs::normalize_construction( (string) ( $in['construction'] ?? '' ) );
			if ( '' === $const ) {
				$const = $front['construction'];
			}
			if ( '' !== $const ) {
				$terms[ TireSpecs::ATTR_CONSTRUCTION ] = array(
					'name' => (string) ( $fixed[ TireSpecs::ATTR_CONSTRUCTION ][ $const ] ?? $const ),
					'slug' => $const,
				);
			}

			$speed = strtoupper( trim( (string) ( $in['speed'] ?? '' ) ) );
			if ( '' !== $speed ) {
				if ( isset( TireSpecs::SPEED_RANKS[ $speed ] ) ) {
					$speed_slug                            = '(W)' === $speed ? 'w-par' : strtolower( $speed );
					$terms[ TireSpecs::ATTR_SPEED_RATING ] = array(
						'name' => $speed,
						'slug' => $speed_slug,
					);
				} else {
					$errors[] = __( 'Unknown speed rating.', 'motored-core' );
				}
			}

			$load_raw = trim( (string) ( $in['load'] ?? '' ) );
			if ( '' !== $load_raw ) {
				$load = TireSpecs::parse_load_index( $load_raw );
				if ( null === $load ) {
					$errors[] = __( 'The load index must be a number between 20 and 120.', 'motored-core' );
				} else {
					$terms[ TireSpecs::ATTR_LOAD_INDEX ] = self::plain_term( (string) $load );
				}
			}

			$tube = (string) ( $in['tube'] ?? '' );
			if ( isset( $fixed[ TireSpecs::ATTR_TUBE ][ $tube ] ) ) {
				$terms[ TireSpecs::ATTR_TUBE ] = array(
					'name' => (string) $fixed[ TireSpecs::ATTR_TUBE ][ $tube ],
					'slug' => $tube,
				);
			}
		}

		$usage = (string) ( $in['usage'] ?? '' );
		if ( isset( $fixed[ TireSpecs::ATTR_USAGE ][ $usage ] ) ) {
			$terms[ TireSpecs::ATTR_USAGE ] = array(
				'name' => (string) $fixed[ TireSpecs::ATTR_USAGE ][ $usage ],
				'slug' => $usage,
			);
		}

		$line = trim( (string) ( $in['line'] ?? '' ) );
		if ( '' !== $line ) {
			$line_slug = AttributeInstaller::size_slug( $line );
			if ( '' !== $line_slug ) {
				$terms[ TireSpecs::ATTR_LINE ] = array(
					'name' => $line,
					'slug' => $line_slug,
				);
			}
		}

		return array(
			'errors' => $errors,
			'terms'  => $terms,
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
