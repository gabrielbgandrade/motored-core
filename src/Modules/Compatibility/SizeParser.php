<?php
/**
 * Motorcycle tire size parser and canonicalizer.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Pure parser for metric, imperial (preserving trailing zeroes as strings) and alpha tire sizes.
 */
final class SizeParser {

	public const NOTATION_METRIC   = 'metric';
	public const NOTATION_IMPERIAL = 'imperial';
	public const NOTATION_ALPHA    = 'alpha';

	/**
	 * Parses a raw tire size string into canonical form and components, or null if invalid.
	 *
	 * Never casts imperial widths to float so trailing zeroes ("4.10", "3.00") remain intact.
	 *
	 * @param string $raw Raw input (e.g. "120/70ZR17", "4,10-18", "MT90B16").
	 * @return array{canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null
	 */
	public static function parse( string $raw ): ?array {
		$norm = self::preprocess( $raw );
		if ( '' === $norm ) {
			return null;
		}

		// Metric: 120/70-17, 120/70ZR17, 120/70 17, 120 70 17, 150/80B16, 120/70 VB 17, 80/100-21.
		if ( 1 === preg_match( '/^(\d{2,3})\s*(?:\/|\s+)\s*(\d{2,3})\s*(?:ZR|VR|VB|R|B|D|-|\s+)\s*(\d{1,2})(?:\b|$)/', $norm, $m ) ) {
			$width  = (int) $m[1];
			$aspect = (int) $m[2];
			$rim    = (int) $m[3];
			if ( $width < 50 || $width > 360 || $aspect < 25 || $aspect > 100 || $rim < 4 || $rim > 30 ) {
				return null;
			}
			$width_str = (string) $width;
			return array(
				'canonical' => $width_str . '/' . $aspect . '-' . $rim,
				'notation'  => self::NOTATION_METRIC,
				'width'     => $width_str,
				'aspect'    => $aspect,
				'rim'       => $rim,
			);
		}

		// Alpha (cruiser/custom): MT90-16, MU85B16, MH90-21, MV85B15.
		if ( 1 === preg_match( '/^(M[HJMNPRSTUV]\d{2})\s*(?:ZR|R|B|D|-)?\s*(\d{1,2})(?:\b|$)/', $norm, $m ) ) {
			$width_str = $m[1];
			$rim       = (int) $m[2];
			if ( $rim < 12 || $rim > 23 ) {
				return null;
			}
			return array(
				'canonical' => $width_str . '-' . $rim,
				'notation'  => self::NOTATION_ALPHA,
				'width'     => $width_str,
				'aspect'    => null,
				'rim'       => $rim,
			);
		}

		// Imperial: 4.10-18, 3.00-18, 2.75-18, 3.50-10, 4.10R18, 4.10B18, 4.10 18.
		if ( 1 === preg_match( '/^(\d+\.\d{1,2})\s*(?:R|B|X|-|\s+)\s*(\d{1,2})(?:\b|$)/', $norm, $m ) ) {
			$width_str = self::pad_imperial_width( $m[1] );
			$width_num = (float) $width_str;
			$rim       = (int) $m[2];
			if ( $width_num < 1.50 || $width_num > 7.50 || $rim < 4 || $rim > 30 ) {
				return null;
			}
			return array(
				'canonical' => $width_str . '-' . $rim,
				'notation'  => self::NOTATION_IMPERIAL,
				'width'     => $width_str,
				'aspect'    => null,
				'rim'       => $rim,
			);
		}

		return null;
	}

	/**
	 * Extracts all valid tire sizes from a free-text query (including kits/pairs such as
	 * "160/60 17 + 120/70 17" or "120/70ZR17 y 180/55ZR17").
	 *
	 * @param string $raw Raw input.
	 * @return list<array{canonical:string,notation:string,width:string,aspect:int|null,rim:int}>
	 */
	public static function parse_all( string $raw ): array {
		$norm = self::preprocess( $raw );
		if ( '' === $norm ) {
			return array();
		}

		$found = array();
		$seen  = array();

		$add = static function ( array $item, string $raw_token ) use ( &$found, &$seen ): void {
			$desig                = self::designation( $raw_token );
			$item['designation']  = null !== $desig ? $desig['designation'] : $item['canonical'];
			$item['construction'] = null !== $desig ? $desig['construction'] : '';
			$dedup_key            = $item['designation'];
			if ( ! isset( $seen[ $dedup_key ] ) ) {
				$seen[ $dedup_key ] = true;
				$found[]            = $item;
			}
		};

		// Metric sizes anywhere in the query.
		if ( preg_match_all( '/(?<!\d)(\d{2,3})\s*(?:\/|\s+)\s*(\d{2,3})\s*(?:ZR|VR|VB|R|B|D|-|\s+)\s*(\d{1,2})(?!\d)/', $norm, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$width  = (int) $m[1];
				$aspect = (int) $m[2];
				$rim    = (int) $m[3];
				if ( $width >= 50 && $width <= 360 && $aspect >= 25 && $aspect <= 100 && $rim >= 4 && $rim <= 30 ) {
					$width_str = (string) $width;
					$add(
						array(
							'canonical' => $width_str . '/' . $aspect . '-' . $rim,
							'notation'  => self::NOTATION_METRIC,
							'width'     => $width_str,
							'aspect'    => $aspect,
							'rim'       => $rim,
						),
						(string) $m[0]
					);
				}
			}
		}

		// Alpha sizes.
		if ( preg_match_all( '/\b(M[HJMNPRSTUV]\d{2})\s*(?:ZR|R|B|D|-)?\s*(\d{1,2})(?!\d)/', $norm, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$rim = (int) $m[2];
				if ( $rim >= 12 && $rim <= 23 ) {
					$add(
						array(
							'canonical' => $m[1] . '-' . $rim,
							'notation'  => self::NOTATION_ALPHA,
							'width'     => $m[1],
							'aspect'    => null,
							'rim'       => $rim,
						),
						(string) $m[0]
					);
				}
			}
		}

		// Imperial sizes.
		if ( preg_match_all( '/(?<!\d)(\d+\.\d{1,2})\s*(?:R|B|X|-|\s+)\s*(\d{1,2})(?!\d)/', $norm, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$width_str = self::pad_imperial_width( $m[1] );
				$width_num = (float) $width_str;
				$rim       = (int) $m[2];
				if ( $width_num >= 1.50 && $width_num <= 7.50 && $rim >= 4 && $rim <= 30 ) {
					$add(
						array(
							'canonical' => $width_str . '-' . $rim,
							'notation'  => self::NOTATION_IMPERIAL,
							'width'     => $width_str,
							'aspect'    => null,
							'rim'       => $rim,
						),
						(string) $m[0]
					);
				}
			}
		}

		return $found;
	}

	/**
	 * Reads the manufacturer designation of a size: the printed form ("120/70ZR17", "150/70R17", "110/80-19")
	 * and the construction it implies (R/ZR/VR = radial, B/VB = belted, D = diagonal, "-" = not stated).
	 * Matching always uses the canonical key; this only adds the OEM construction rule.
	 *
	 * @param string $raw Raw size input.
	 * @return array{designation:string,construction:string}|null Null when the size is invalid.
	 */
	public static function designation( string $raw ): ?array {
		$parsed = self::parse( $raw );
		if ( null === $parsed ) {
			return null;
		}
		$norm   = self::preprocess( $raw );
		$marker = '';
		if ( self::NOTATION_METRIC === $parsed['notation'] ) {
			preg_match( '/^\d{2,3}\s*\/\s*\d{2,3}\s*(ZR|VR|VB|R|B|D|-)\s*\d/', $norm, $m );
		} elseif ( self::NOTATION_ALPHA === $parsed['notation'] ) {
			preg_match( '/^M[HJMNPRSTUV]\d{2}\s*(ZR|R|B|D|-)?\s*\d/', $norm, $m );
		} else {
			preg_match( '/^\d+\.\d{1,2}\s*(R|B|X|-)\s*\d/', $norm, $m );
		}
		if ( isset( $m[1] ) ) {
			$marker = $m[1];
		}
		$construction = '';
		if ( in_array( $marker, array( 'ZR', 'VR', 'R' ), true ) ) {
			$construction = TireSpecs::CONST_RADIAL;
		} elseif ( in_array( $marker, array( 'B', 'VB' ), true ) ) {
			$construction = TireSpecs::CONST_BELTED;
		} elseif ( 'D' === $marker ) {
			$construction = TireSpecs::CONST_DIAGONAL;
		}
		$sep = in_array( $marker, array( '', 'X', 'D' ), true ) ? '-' : $marker;
		if ( self::NOTATION_METRIC === $parsed['notation'] ) {
			$full = $parsed['width'] . '/' . $parsed['aspect'] . $sep . $parsed['rim'];
		} else {
			$full = $parsed['width'] . $sep . $parsed['rim'];
		}
		return array(
			'designation'  => $full,
			'construction' => $construction,
		);
	}

	/**
	 * Returns only the canonical key or null when invalid.
	 *
	 * @param string $raw Raw size input.
	 */
	public static function canonical( string $raw ): ?string {
		$parsed = self::parse( $raw );
		return null === $parsed ? null : $parsed['canonical'];
	}

	/**
	 * Flags suspicious rim diameters outside standard motorcycle range (8 to 23 inches).
	 *
	 * @param int $rim Rim diameter in inches.
	 */
	public static function is_suspicious_rim( int $rim ): bool {
		return $rim < 8 || $rim > 23;
	}

	/**
	 * Builds a human-readable full tire size (e.g. "120/70ZR17" or "4.10-18") from parsed parts.
	 *
	 * @param array{canonical:string,notation:string,width:string,aspect:int|null,rim:int} $parsed       Parsed size.
	 * @param string                                                                       $construction Normalized construction slug.
	 * @param string                                                                       $speed        Speed code (W, H, V, ZR...).
	 */
	public static function format_full_size( array $parsed, string $construction = '', string $speed = '' ): string {
		$speed_upper = strtoupper( trim( $speed ) );
		if ( self::NOTATION_METRIC === $parsed['notation'] && null !== $parsed['aspect'] ) {
			$sep = '-';
			if ( 'ZR' === $speed_upper || '(W)' === $speed_upper || 'W' === $speed_upper || 'Y' === $speed_upper ) {
				$sep = 'ZR';
			} elseif ( 'VR' === $speed_upper ) {
				$sep = 'VR';
			} elseif ( TireSpecs::CONST_RADIAL === $construction ) {
				$sep = 'R';
			} elseif ( TireSpecs::CONST_BELTED === $construction ) {
				$sep = 'B';
			}
			return $parsed['width'] . '/' . $parsed['aspect'] . $sep . $parsed['rim'];
		}

		if ( self::NOTATION_ALPHA === $parsed['notation'] && TireSpecs::CONST_BELTED === $construction ) {
			return $parsed['width'] . 'B' . $parsed['rim'];
		}

		return $parsed['canonical'];
	}

	/**
	 * Normalizes separators, comma decimals and removes trailing/parenthesized speed/load/tube tokens.
	 *
	 * @param string $raw Raw input.
	 */
	private static function preprocess( string $raw ): string {
		$s = strtoupper( trim( $raw ) );
		if ( '' === $s ) {
			return '';
		}
		// Normalize decimal commas ("4,10-18") and unicode dashes.
		$s = str_replace( array( ',', '–', '—', '−' ), array( '.', '-', '-', '-' ), $s );
		// Strip parenthesized descriptors such as "(58W)".
		$s = (string) preg_replace( '/\([^)]*\)/', ' ', $s );
		// Collapse whitespace.
		$s = trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		// Drop known trailing markers that are not part of the size code.
		$s = (string) preg_replace( '/\b(M\/C|MC|TL\/TT|TL-TT|TL|TT|REINFORCED|REINF|NHS|RF|FRONT|REAR|DELANTERO|TRASERO|DEL|TRAS)\b/', ' ', $s );
		$s = trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		return $s;
	}

	/**
	 * Pads single-decimal imperial strings (e.g. "4.1" -> "4.10") without float conversion.
	 *
	 * @param string $width Numeric string containing a dot.
	 */
	private static function pad_imperial_width( string $width ): string {
		$dot = strpos( $width, '.' );
		if ( false === $dot ) {
			return $width;
		}
		$decimals = substr( $width, $dot + 1 );
		if ( 1 === strlen( $decimals ) ) {
			return $width . '0';
		}
		return $width;
	}
}
