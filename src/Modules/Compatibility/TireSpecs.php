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
	 * ETRTO/ISO single-tire load index -> max weight capacity in kg (20..120).
	 *
	 * @var array<int,int|float>
	 */
	public const LOAD_KG = array(
		20  => 80,
		21  => 82.5,
		22  => 85,
		23  => 87.5,
		24  => 90,
		25  => 92.5,
		26  => 95,
		27  => 97.5,
		28  => 100,
		29  => 103,
		30  => 106,
		31  => 109,
		32  => 112,
		33  => 115,
		34  => 118,
		35  => 121,
		36  => 125,
		37  => 128,
		38  => 132,
		39  => 136,
		40  => 140,
		41  => 145,
		42  => 150,
		43  => 155,
		44  => 160,
		45  => 165,
		46  => 170,
		47  => 175,
		48  => 180,
		49  => 185,
		50  => 190,
		51  => 195,
		52  => 200,
		53  => 206,
		54  => 212,
		55  => 218,
		56  => 224,
		57  => 230,
		58  => 236,
		59  => 243,
		60  => 250,
		61  => 257,
		62  => 265,
		63  => 272,
		64  => 280,
		65  => 290,
		66  => 300,
		67  => 307,
		68  => 315,
		69  => 325,
		70  => 335,
		71  => 345,
		72  => 355,
		73  => 365,
		74  => 375,
		75  => 387,
		76  => 400,
		77  => 412,
		78  => 425,
		79  => 437,
		80  => 450,
		81  => 462,
		82  => 475,
		83  => 487,
		84  => 500,
		85  => 515,
		86  => 530,
		87  => 545,
		88  => 560,
		89  => 580,
		90  => 600,
		91  => 615,
		92  => 630,
		93  => 650,
		94  => 670,
		95  => 690,
		96  => 710,
		97  => 730,
		98  => 750,
		99  => 775,
		100 => 800,
		101 => 825,
		102 => 850,
		103 => 875,
		104 => 900,
		105 => 925,
		106 => 950,
		107 => 975,
		108 => 1000,
		109 => 1030,
		110 => 1060,
		111 => 1090,
		112 => 1120,
		113 => 1150,
		114 => 1180,
		115 => 1215,
		116 => 1250,
		117 => 1285,
		118 => 1320,
		119 => 1360,
		120 => 1400,
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
	 * Reads the load index + speed rating printed after a size ("130/70-17 62H", "120/70ZR17 M/C (58W) TL").
	 *
	 * Only a contiguous token preceded by a space or "(" counts, so the size itself ("120/70R17") never matches.
	 *
	 * @param string $raw Size as printed on the tire.
	 * @return array{load:int|null,speed:string} Speed is an ETRTO code ("H", "(W)") or ''.
	 */
	public static function parse_load_speed_suffix( string $raw ): array {
		$out = array(
			'load'  => null,
			'speed' => '',
		);
		$s   = strtoupper( trim( $raw ) );
		if ( '' === $s || 1 !== preg_match( '/(?:^|[\s(])(\d{2,3})(\(W\)|[JKLMNPQRSTUHVWY])\)?(?=$|[\s)])/', $s, $m ) ) {
			return $out;
		}
		$out['load']  = self::parse_load_index( $m[1] );
		$out['speed'] = $m[2];
		return $out;
	}

	/**
	 * Returns the single-tire max load in kg for a load index (20..120), or null when unknown.
	 *
	 * @param int $load Load index.
	 * @return int|float|null
	 */
	public static function load_kg( int $load ) {
		return self::LOAD_KG[ $load ] ?? null;
	}

	/**
	 * Formats combined load index and speed rating with kg / km/h context (call after init).
	 *
	 * @param int|null $load       Numeric load index (20..120) or null.
	 * @param string   $speed_code ETRTO speed rating code (e.g. "H", "(W)") or slug ("w-par").
	 */
	public static function format_load_speed( ?int $load, string $speed_code ): string {
		$speed = strtoupper( trim( $speed_code ) );
		if ( 'W-PAR' === $speed ) {
			$speed = '(W)';
		}
		if ( '' !== $speed && ! isset( self::SPEED_RANKS[ $speed ] ) ) {
			$speed = '';
		}

		$valid_load = ( null !== $load && $load >= 20 && $load <= 120 ) ? $load : null;
		$code       = ( null !== $valid_load ? (string) $valid_load : '' ) . $speed;
		if ( '' === $code ) {
			return '';
		}

		$parts = array();
		if ( null !== $valid_load ) {
			$kg = self::load_kg( $valid_load );
			if ( null !== $kg ) {
				$parts[] = sprintf(
					/* translators: %s: maximum weight in kilograms per tire. */
					__( 'up to %s kg', 'motored-core' ),
					(string) $kg
				);
			}
		}

		if ( '' !== $speed ) {
			$kmh = self::SPEED_KMH[ $speed ];
			if ( '(W)' === $speed ) {
				$parts[] = sprintf(
					/* translators: %d: speed threshold in km/h above 270. */
					__( '+%d km/h', 'motored-core' ),
					$kmh
				);
			} elseif ( null !== $valid_load ) {
				$parts[] = sprintf(
					/* translators: %d: maximum rated speed in km/h. */
					__( '%d km/h', 'motored-core' ),
					$kmh
				);
			} else {
				$parts[] = sprintf(
					/* translators: %d: maximum rated speed in km/h. */
					__( 'up to %d km/h', 'motored-core' ),
					$kmh
				);
			}
		}

		return empty( $parts ) ? $code : $code . ' (' . implode( ' · ', $parts ) . ')';
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
	 * Single source of truth for tire position labels (call after init).
	 *
	 * @param string $position 'front'|'rear'|'both'|'kit'.
	 * @param bool   $plural   Whether to return the plural tab label ('Front tires', 'Rear tires', 'Kits').
	 */
	public static function position_label( string $position, bool $plural = false ): string {
		if ( self::POS_KIT === $position ) {
			return $plural ? __( 'Kits', 'motored-core' ) : __( 'Complete kit', 'motored-core' );
		}
		// Context "tire position": the label qualifies a tire ("cubierta", feminine in es_AR), unlike the
		// masculine axle/attribute terms that share the plain "Front"/"Rear" msgids in the admin.
		if ( self::POS_BOTH === $position ) {
			return _x( 'Front / Rear', 'tire position', 'motored-core' );
		}
		if ( self::POS_FRONT === $position ) {
			return $plural ? __( 'Front tires', 'motored-core' ) : _x( 'Front', 'tire position', 'motored-core' );
		}
		if ( self::POS_REAR === $position ) {
			return $plural ? __( 'Rear tires', 'motored-core' ) : _x( 'Rear', 'tire position', 'motored-core' );
		}
		return '';
	}

	/**
	 * Human position label for matched axles ("Complete kit" only for kit products; dual-axle single tires get "Front / Rear").
	 *
	 * @param array<int,string> $positions        Matched axles.
	 * @param string            $product_position Product position (front|rear|both|kit).
	 */
	public static function positions_label( array $positions, string $product_position = '' ): string {
		if ( self::POS_KIT === $product_position ) {
			return self::position_label( self::POS_KIT );
		}
		$front = in_array( self::POS_FRONT, $positions, true );
		$rear  = in_array( self::POS_REAR, $positions, true );
		if ( $front && $rear ) {
			return self::position_label( self::POS_BOTH );
		}
		if ( $front ) {
			return self::position_label( self::POS_FRONT );
		}
		return $rear ? self::position_label( self::POS_REAR ) : '';
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
