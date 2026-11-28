<?php
/**
 * ProductBanner configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ProductBanner;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_product_banner` (autoload=false) and the product meta `_motored_banner`.
 */
final class Config {

	public const OPTION_KEY = 'product_banner';
	public const META_KEY   = '_motored_banner';

	public const MODE_DEFAULT = 'default';
	public const MODE_OWN     = 'own';
	public const MODE_NONE    = 'none';

	public const DEFAULTS = array(
		'enabled'   => 'no',
		'image_id'  => '',
		'link'      => '',
		'until'     => '',
		'countdown' => 'yes',
	);

	/**
	 * The general banner as resolver input.
	 *
	 * @return array{enabled:bool,image_id:int,link:string,until:int,countdown:bool}
	 */
	public static function general(): array {
		return array(
			'enabled'   => Options::is_enabled( self::OPTION_KEY, 'enabled' ),
			'image_id'  => self::int( Options::get( self::OPTION_KEY, 'image_id', '' ) ),
			'link'      => self::str( Options::get( self::OPTION_KEY, 'link', '' ) ),
			'until'     => self::timestamp( self::str( Options::get( self::OPTION_KEY, 'until', '' ) ) ),
			'countdown' => Options::is_enabled( self::OPTION_KEY, 'countdown' ),
		);
	}

	/**
	 * The product's own settings as resolver input.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{mode:string,image_id:int,link:string,until:int,countdown:bool}
	 */
	public static function own( \WC_Product $product ): array {
		$raw = $product->get_meta( self::META_KEY );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'mode'      => self::mode( $raw['mode'] ?? '' ),
			'image_id'  => self::int( $raw['image_id'] ?? 0 ),
			'link'      => self::str( $raw['link'] ?? '' ),
			'until'     => self::timestamp( self::str( $raw['until'] ?? '' ) ),
			'countdown' => ! empty( $raw['countdown'] ),
		);
	}

	/**
	 * A valid mode (default when unknown).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function mode( $value ): string {
		return in_array( $value, array( self::MODE_DEFAULT, self::MODE_OWN, self::MODE_NONE ), true ) ? (string) $value : self::MODE_DEFAULT;
	}

	/**
	 * "2026-12-01T23:59" in the site timezone to a Unix timestamp (0 when empty or invalid).
	 *
	 * @param string $local Local date and time.
	 */
	public static function timestamp( string $local ): int {
		$local = trim( str_replace( 'T', ' ', $local ) );
		if ( '' === $local ) {
			return 0;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $local, wp_timezone() );
		return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : 0;
	}

	/**
	 * Positive integer or 0.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function int( $value ): int {
		return is_scalar( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Trimmed string or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function str( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
