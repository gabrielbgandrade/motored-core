<?php
/**
 * Short technical title for product cards: "160/60R17 69H TL" + the line "MT60 RS".
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the compatibility index (full size, primed in batch for product loops) and the tire attributes
 * (load index, speed rating, tube type, line). The product name is never changed: themes show this only as the
 * visible card title. Exposed through \Motored\Core\Api::tire_card().
 */
final class TireCard {

	/**
	 * Card title parts for a tire, or an empty array when the product is not an indexed tire.
	 * `load` / `speed` (1.9): `{code, kg}` / `{code, kmh}` of a single tire, empty for kits or missing data.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{size:string,model:string,kit:bool,load:array{code?:string,kg?:int|float},speed:array{code?:string,kmh?:int}}|array{}
	 */
	public static function for_product( \WC_Product $product ): array {
		if ( ! Config::enabled() ) {
			return array();
		}
		$size = Storefront::resolve_full_size_label( $product );
		if ( '' === $size ) {
			return array();
		}
		$fit   = Storefront::fit_for_product( $product->get_id() );
		$kit   = null !== $fit && TireSpecs::POS_KIT === ( $fit['position'] ?? '' );
		$parts   = array( $size );
		$indexes = array(
			'load'  => array(),
			'speed' => array(),
		);
		if ( ! $kit ) {
			$indexes = self::indexes( self::attribute( $product, TireSpecs::ATTR_LOAD_INDEX ), self::attribute( $product, TireSpecs::ATTR_SPEED_RATING ) );
			$code    = self::load_speed_code( self::attribute( $product, TireSpecs::ATTR_LOAD_INDEX ), self::attribute( $product, TireSpecs::ATTR_SPEED_RATING ) );
			if ( '' !== $code ) {
				$parts[] = $code;
			}
			$tube = self::tube_code( self::attribute( $product, TireSpecs::ATTR_TUBE ) );
			if ( '' !== $tube ) {
				$parts[] = $tube;
			}
		}
		return array(
			'size'  => implode( ' ', $parts ),
			'model' => self::attribute( $product, TireSpecs::ATTR_LINE ),
			'kit'   => $kit,
			'load'  => $indexes['load'],
			'speed' => $indexes['speed'],
		);
	}

	/**
	 * Load index with its kg and speed rating with its km/h, each empty when invalid or unknown.
	 *
	 * @param string $load  Load index value.
	 * @param string $speed Speed rating value or slug.
	 * @return array{load:array{code?:string,kg?:int|float},speed:array{code?:string,kmh?:int}}
	 */
	public static function indexes( string $load, string $speed ): array {
		$out   = array(
			'load'  => array(),
			'speed' => array(),
		);
		$index = TireSpecs::parse_load_index( $load );
		if ( null !== $index && $index >= 20 && $index <= 120 ) {
			$kg = TireSpecs::load_kg( $index );
			if ( null !== $kg ) {
				$out['load'] = array(
					'code' => (string) $index,
					'kg'   => $kg,
				);
			}
		}
		$code = strtoupper( trim( $speed ) );
		if ( 'W-PAR' === $code ) {
			$code = '(W)';
		}
		if ( '' !== $code && null !== TireSpecs::speed_rank( $code ) && isset( TireSpecs::SPEED_KMH[ $code ] ) ) {
			$out['speed'] = array(
				'code' => $code,
				'kmh'  => (int) TireSpecs::SPEED_KMH[ $code ],
			);
		}
		return $out;
	}

	/**
	 * "69H": valid load index (20..120) + known ETRTO speed code, either may be missing.
	 *
	 * @param string $load  Load index value.
	 * @param string $speed Speed rating value or slug.
	 */
	public static function load_speed_code( string $load, string $speed ): string {
		$index = TireSpecs::parse_load_index( $load );
		$index = null !== $index && $index >= 20 && $index <= 120 ? (string) $index : '';
		$code  = strtoupper( trim( $speed ) );
		if ( 'W-PAR' === $code ) {
			$code = '(W)';
		}
		if ( '' !== $code && null === TireSpecs::speed_rank( $code ) ) {
			$code = '';
		}
		return $index . $code;
	}

	/**
	 * "TL", "TT" or "TL/TT" from the tube attribute label (e.g. "Sin cámara (TL)").
	 *
	 * @param string $raw Tube attribute value.
	 */
	public static function tube_code( string $raw ): string {
		if ( preg_match( '/\b(TL\s*\/\s*TT|TT\s*\/\s*TL|TL|TT)\b/', strtoupper( $raw ), $match ) ) {
			return str_replace( ' ', '', $match[1] );
		}
		return '';
	}

	/**
	 * First value of a pa_* attribute (taxonomy term names or custom values).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $slug    Attribute slug without the pa_ prefix.
	 */
	private static function attribute( \WC_Product $product, string $slug ): string {
		$value = (string) $product->get_attribute( 'pa_' . $slug );
		$first = explode( ',', $value );
		return trim( (string) $first[0] );
	}
}
