<?php
/**
 * Per-axle technical sheet of a kit product (front + rear tire sold as one simple product).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for the `_motored_tire_spec` product meta.
 *
 * WooCommerce global attributes hold one set of values per product, so they cannot say "52H front, 62H rear".
 * The meta is the source of truth for each axle of a kit; the product index copies it into its per-axle columns.
 * Attributes keep only what is true for the whole kit (position, both sizes, shared construction/tube, models).
 */
final class KitSpec {

	public const META    = '_motored_tire_spec';
	public const VERSION = 1;

	/**
	 * Allowed tube slugs (same fixed terms as the Tube type attribute).
	 *
	 * @var list<string>
	 */
	public const TUBE_SLUGS = array( 'tl', 'tt', 'tl-tt' );

	/**
	 * Builds one axle from form values, or null when the size is not valid.
	 *
	 * Load and speed typed after the size ("130/70-17 62H") fill the empty fields.
	 *
	 * @param string $raw_size     Size as printed on the tire.
	 * @param string $line         Tire model (e.g. "MT60 RS").
	 * @param string $load         Load index field value.
	 * @param string $speed        Speed rating field value.
	 * @param string $construction Construction override ('' = from the printed size).
	 * @param string $tube         Tube slug.
	 * @return array{size:string,designation:string,line:string,load:int|null,speed:string,construction:string,tube:string}|null
	 */
	public static function axle( string $raw_size, string $line, string $load, string $speed, string $construction, string $tube ): ?array {
		$parsed = SizeParser::parse( $raw_size );
		$desig  = SizeParser::designation( $raw_size );
		if ( null === $parsed || null === $desig ) {
			return null;
		}
		$suffix   = TireSpecs::parse_load_speed_suffix( $raw_size );
		$load_val = '' !== trim( $load ) ? TireSpecs::parse_load_index( $load ) : $suffix['load'];
		$speed    = strtoupper( trim( $speed ) );
		if ( '' === $speed ) {
			$speed = $suffix['speed'];
		}
		$const = TireSpecs::normalize_construction( $construction );
		if ( '' === $const ) {
			$const = $desig['construction'];
		}
		return array(
			'size'         => $parsed['canonical'],
			'designation'  => $desig['designation'],
			'line'         => trim( $line ),
			'load'         => $load_val,
			'speed'        => isset( TireSpecs::SPEED_RANKS[ $speed ] ) ? $speed : '',
			'construction' => $const,
			'tube'         => in_array( $tube, self::TUBE_SLUGS, true ) ? $tube : '',
		);
	}

	/**
	 * Reads a stored spec (array or JSON string) and returns a clean copy, or null when it is not a usable kit sheet.
	 *
	 * @param mixed $raw Stored meta value.
	 * @return array{v:int,front:array{size:string,designation:string,line:string,load:int|null,speed:string,construction:string,tube:string},rear:array{size:string,designation:string,line:string,load:int|null,speed:string,construction:string,tube:string}}|null
	 */
	public static function normalize( $raw ): ?array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$front = self::clean_axle( $raw[ TireSpecs::POS_FRONT ] ?? null );
		$rear  = self::clean_axle( $raw[ TireSpecs::POS_REAR ] ?? null );
		if ( null === $front || null === $rear ) {
			return null;
		}
		return array(
			'v'                  => self::VERSION,
			TireSpecs::POS_FRONT => $front,
			TireSpecs::POS_REAR  => $rear,
		);
	}

	/**
	 * Whether the spec describes the sizes the product attributes currently say (an ERP may rewrite attributes).
	 *
	 * @param array<string,mixed> $spec            Normalized spec.
	 * @param string|null         $front_canonical Front size from the attributes.
	 * @param string|null         $rear_canonical  Rear size from the attributes.
	 */
	public static function matches_sizes( array $spec, ?string $front_canonical, ?string $rear_canonical ): bool {
		return null !== $front_canonical && null !== $rear_canonical
			&& ( $spec[ TireSpecs::POS_FRONT ]['size'] ?? '' ) === $front_canonical
			&& ( $spec[ TireSpecs::POS_REAR ]['size'] ?? '' ) === $rear_canonical;
	}

	/**
	 * Value shared by both axles ('' when they differ or are empty), used for whole-product attributes.
	 *
	 * @param array<string,mixed> $spec Normalized spec.
	 * @param string              $key  Axle key (construction, tube, line).
	 */
	public static function shared( array $spec, string $key ): string {
		$f = (string) ( $spec[ TireSpecs::POS_FRONT ][ $key ] ?? '' );
		$r = (string) ( $spec[ TireSpecs::POS_REAR ][ $key ] ?? '' );
		return ( '' !== $f && $f === $r ) ? $f : '';
	}

	/**
	 * Whether the sheet carries any rating (load, speed or construction) on any axle.
	 *
	 * @param array<string,mixed> $spec Normalized spec.
	 */
	public static function has_ratings( array $spec ): bool {
		foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $axle ) {
			$a = $spec[ $axle ] ?? array();
			if ( null !== ( $a['load'] ?? null ) || '' !== ( $a['speed'] ?? '' ) || '' !== ( $a['construction'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stable string used in the product index hash ('' when there is no spec).
	 *
	 * @param array<string,mixed>|null $spec Normalized spec.
	 */
	public static function fingerprint( ?array $spec ): string {
		return null === $spec ? '' : (string) wp_json_encode( $spec );
	}

	/**
	 * Sanitizes one stored axle.
	 *
	 * @param mixed $axle Raw axle array.
	 * @return array{size:string,designation:string,line:string,load:int|null,speed:string,construction:string,tube:string}|null
	 */
	private static function clean_axle( $axle ): ?array {
		if ( ! is_array( $axle ) ) {
			return null;
		}
		$size = SizeParser::canonical( (string) ( $axle['size'] ?? '' ) );
		if ( null === $size ) {
			return null;
		}
		$desig = SizeParser::designation( (string) ( $axle['designation'] ?? '' ) );
		$load  = isset( $axle['load'] ) && is_scalar( $axle['load'] ) ? TireSpecs::parse_load_index( (string) $axle['load'] ) : null;
		$speed = strtoupper( trim( (string) ( $axle['speed'] ?? '' ) ) );
		$tube  = (string) ( $axle['tube'] ?? '' );
		return array(
			'size'         => $size,
			'designation'  => null !== $desig && SizeParser::canonical( $desig['designation'] ) === $size ? $desig['designation'] : $size,
			// Sanitized with sanitize_text_field() when saved; escaped on output.
			'line'         => trim( (string) ( $axle['line'] ?? '' ) ),
			'load'         => $load,
			'speed'        => isset( TireSpecs::SPEED_RANKS[ $speed ] ) ? $speed : '',
			'construction' => TireSpecs::normalize_construction( (string) ( $axle['construction'] ?? '' ) ),
			'tube'         => in_array( $tube, self::TUBE_SLUGS, true ) ? $tube : '',
		);
	}
}
