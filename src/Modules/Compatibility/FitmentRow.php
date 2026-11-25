<?php
/**
 * Pure parser for one row of a motorcycle's tire sizes (admin editor, Quick add).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * The owner types a size the way the manual prints it ("120/70ZR17 58W"). That gives the matching key
 * (120/70-17), the printed designation (120/70ZR17), the construction rule (radial) and the minimum load/speed the
 * tire must meet (58 / W). Explicit fields win over what the size says.
 */
final class FitmentRow {

	/**
	 * Parses one row.
	 *
	 * @param array<string,string> $in Keys: size, position, fit_type, min_load, min_speed, req_construction, note.
	 * @return array{ok:bool,error:string,position:string,canonical:string,designation:string,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,note:string}
	 */
	public static function parse( array $in ): array {
		$out = array(
			'ok'               => false,
			'error'            => '',
			'position'         => TireSpecs::normalize_position( (string) ( $in['position'] ?? '' ) ),
			'canonical'        => '',
			'designation'      => '',
			'fit_type'         => TireSpecs::FIT_ALT === ( $in['fit_type'] ?? '' ) ? TireSpecs::FIT_ALT : TireSpecs::FIT_OEM,
			'min_load'         => null,
			'min_speed_rank'   => null,
			'req_construction' => null,
			'note'             => trim( (string) ( $in['note'] ?? '' ) ),
		);
		if ( ! in_array( $out['position'], array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true ) ) {
			$out['error'] = 'position';
			return $out;
		}
		$raw    = trim( (string) ( $in['size'] ?? '' ) );
		$parsed = '' !== $raw ? SizeParser::parse( $raw ) : null;
		$desig  = '' !== $raw ? SizeParser::designation( $raw ) : null;
		if ( null === $parsed || null === $desig ) {
			$out['error'] = 'size';
			return $out;
		}
		$suffix = TireSpecs::parse_load_speed_suffix( $raw );

		$load_raw = trim( (string) ( $in['min_load'] ?? '' ) );
		$load     = '' !== $load_raw ? TireSpecs::parse_load_index( $load_raw ) : $suffix['load'];
		if ( '' !== $load_raw && null === $load ) {
			$out['error'] = 'load';
			return $out;
		}
		$speed_raw = strtoupper( trim( (string) ( $in['min_speed'] ?? '' ) ) );
		$speed     = '' !== $speed_raw ? $speed_raw : $suffix['speed'];
		$rank      = '' !== $speed ? TireSpecs::speed_rank( $speed ) : null;
		if ( '' !== $speed_raw && null === $rank ) {
			$out['error'] = 'speed';
			return $out;
		}
		$const = TireSpecs::normalize_construction( (string) ( $in['req_construction'] ?? '' ) );
		if ( '' === $const ) {
			$const = $desig['construction'];
		}

		$out['ok']               = true;
		$out['canonical']        = $parsed['canonical'];
		$out['designation']      = $desig['designation'];
		$out['min_load']         = $load;
		$out['min_speed_rank']   = $rank;
		$out['req_construction'] = '' !== $const ? $const : null;
		return $out;
	}
}
