<?php
/**
 * Motorcycle tire attribute slugs, ETRTO speed rating order and domain constants.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Pure domain constants and normalization helpers shared by AttributeInstaller, sync and matching.
 */
final class TireSpecs {

	public const POS_FRONT = 'front';
	public const POS_REAR  = 'rear';
	public const POS_BOTH  = 'both';
	public const POS_KIT   = 'kit';

	public const CONST_RADIAL   = 'radial';
	public const CONST_DIAGONAL = 'diagonal';
	public const CONST_BELTED   = 'cinturado';

	public const FIT_OEM   = 'oem';
	public const FIT_ALT   = 'alt';
	public const FIT_EQUIV = 'equiv';

	public const ATTR_POSITION     = 'posicion';
	public const ATTR_FRONT_SIZE   = 'medida-delantera';
	public const ATTR_REAR_SIZE    = 'medida-trasera';
	public const ATTR_WIDTH        = 'ancho';
	public const ATTR_PROFILE      = 'perfil';
	public const ATTR_RIM          = 'rodado';
	public const ATTR_LOAD_INDEX   = 'indice-carga';
	public const ATTR_SPEED_RATING = 'indice-velocidad';
	public const ATTR_CONSTRUCTION = 'construccion';
	public const ATTR_TUBE         = 'camara';
	public const ATTR_USAGE        = 'uso';
	public const ATTR_LINE         = 'linea';

	/**
	 * Position attribute slugs -> internal position keys.
	 *
	 * @var array<string,string>
	 */
	public const POSITION_SLUGS = array(
		'delantero'         => self::POS_FRONT,
		'delantera'         => self::POS_FRONT,
		'front'             => self::POS_FRONT,
		'trasero'           => self::POS_REAR,
		'trasera'           => self::POS_REAR,
		'rear'              => self::POS_REAR,
		'ambas'             => self::POS_BOTH,
		'delantero-trasero' => self::POS_BOTH,
		'both'              => self::POS_BOTH,
		'universal'         => self::POS_BOTH,
		'kit'               => self::POS_KIT,
		'combo'             => self::POS_KIT,
	);

	/**
	 * Construction slugs/labels -> normalized keys.
	 *
	 * @var array<string,string>
	 */
	public const CONSTRUCTION_SLUGS = array(
		'radial'       => self::CONST_RADIAL,
		'r'            => self::CONST_RADIAL,
		'diagonal'     => self::CONST_DIAGONAL,
		'convencional' => self::CONST_DIAGONAL,
		'bias'         => self::CONST_DIAGONAL,
		'd'            => self::CONST_DIAGONAL,
		'cinturado'    => self::CONST_BELTED,
		'cinturado-b'  => self::CONST_BELTED,
		'bias-belted'  => self::CONST_BELTED,
		'b'            => self::CONST_BELTED,
	);

	/**
	 * ETRTO speed rating rank (strictly non-alphabetical: H is above U, V is above H).
	 *
	 * @var array<string,int>
	 */
	public const SPEED_RANKS = array(
		'J'   => 1,
		'K'   => 2,
		'L'   => 3,
		'M'   => 4,
		'N'   => 5,
		'P'   => 6,
		'Q'   => 7,
		'R'   => 8,
		'S'   => 9,
		'T'   => 10,
		'U'   => 11,
		'H'   => 12,
		'V'   => 13,
		'VR'  => 13,
		'ZR'  => 14,
		'W'   => 15,
		'(W)' => 16,
		'Y'   => 17,
	);

	/**
	 * ETRTO speed code -> km/h table (used for display and admin reference).
	 *
	 * @var array<string,int>
	 */
	public const SPEED_KMH = array(
		'J'   => 100,
		'K'   => 110,
		'L'   => 120,
		'M'   => 130,
		'N'   => 140,
		'P'   => 150,
		'Q'   => 160,
		'R'   => 170,
		'S'   => 180,
		'T'   => 190,
		'U'   => 200,
		'H'   => 210,
		'V'   => 240,
		'VR'  => 240,
		'ZR'  => 240,
		'W'   => 270,
		'(W)' => 270,
		'Y'   => 300,
	);

	/**
	 * Global attribute definitions: slug (<= 28 chars) and order_by strategy.
	 * Labels are resolved on demand inside AttributeInstaller after init.
	 *
	 * @return array<string,string> Slug => order_by ('menu_order'|'name'|'name_num').
	 */
	public static function attribute_order_map(): array {
		return array(
			self::ATTR_POSITION     => 'menu_order',
			self::ATTR_FRONT_SIZE   => 'name',
			self::ATTR_REAR_SIZE    => 'name',
			self::ATTR_WIDTH        => 'name_num',
			self::ATTR_PROFILE      => 'name_num',
			self::ATTR_RIM          => 'name_num',
			self::ATTR_LOAD_INDEX   => 'name_num',
			self::ATTR_SPEED_RATING => 'menu_order',
			self::ATTR_CONSTRUCTION => 'menu_order',
			self::ATTR_TUBE         => 'menu_order',
			self::ATTR_USAGE        => 'name',
			self::ATTR_LINE         => 'name',
		);
	}

	/**
	 * WooCommerce taxonomy names monitored for automatic product-index synchronization.
	 *
	 * @return list<string>
	 */
	public static function indexed_taxonomies(): array {
		return array(
			'pa_' . self::ATTR_POSITION,
			'pa_' . self::ATTR_FRONT_SIZE,
			'pa_' . self::ATTR_REAR_SIZE,
			'pa_' . self::ATTR_LOAD_INDEX,
			'pa_' . self::ATTR_SPEED_RATING,
			'pa_' . self::ATTR_CONSTRUCTION,
		);
	}

	/**
	 * Converts an ETRTO speed rating code or slug to its comparable numeric rank, or null if unknown.
	 *
	 * @param string $code Speed rating code (e.g. "H", "V", "W", "(W)").
	 */
	public static function speed_rank( string $code ): ?int {
		$clean = strtoupper( trim( $code ) );
		if ( '' === $clean ) {
			return null;
		}
		if ( isset( self::SPEED_RANKS[ $clean ] ) ) {
			return self::SPEED_RANKS[ $clean ];
		}
		if ( 'W-PAR' === $clean || 'W_PAR' === $clean || 'W-PLUS' === $clean ) {
			return self::SPEED_RANKS['(W)'];
		}
		return null;
	}

	/**
	 * Returns the display code for a speed rank, or '' if unknown.
	 *
	 * @param int|null $rank Speed rank.
	 */
	public static function speed_code_for_rank( ?int $rank ): string {
		if ( null === $rank ) {
			return '';
		}
		foreach ( self::SPEED_RANKS as $code => $val ) {
			if ( $val === $rank ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Parses a numeric load index (valid motorcycle range 20..120), or null if invalid.
	 *
	 * @param string $raw Raw term name/slug.
	 */
	public static function parse_load_index( string $raw ): ?int {
		if ( 1 !== preg_match( '/^\s*(\d{2,3})\s*$/', $raw, $m ) ) {
			return null;
		}
		$val = (int) $m[1];
		return ( $val >= 20 && $val <= 120 ) ? $val : null;
	}

	/**
	 * Normalizes a position slug or label into front|rear|both|kit, or '' if unrecognized.
	 *
	 * @param string $raw Position slug or label.
	 */
	public static function normalize_position( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		$slug = str_replace( array( ' / ', '/', ' ' ), array( '-', '-', '-' ), $slug );
		return self::POSITION_SLUGS[ $slug ] ?? '';
	}

	/**
	 * Normalizes a construction slug/label into radial|diagonal|cinturado, or ''.
	 *
	 * @param string $raw Construction slug or label.
	 */
	public static function normalize_construction( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		$slug = str_replace( array( '(', ')', ' ' ), array( '', '', '-' ), $slug );
		$slug = trim( (string) preg_replace( '/-+/', '-', $slug ), '-' );
		return self::CONSTRUCTION_SLUGS[ $slug ] ?? '';
	}
}
