<?php
/**
 * Pure tracking code / URL sanitizing and link building.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

defined( 'ABSPATH' ) || exit;

/**
 * Final link = manual URL (https only) > carrier template with {code} > empty. No I/O.
 */
final class TrackingLink {

	public const CODE_MAX = 40;
	public const URL_MAX  = 300;

	/**
	 * Keeps letters, digits and "-", "_", "." (max 40 characters).
	 *
	 * @param string $code Raw code.
	 */
	public static function sanitize_code( string $code ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_.\-]/', '', $code );
		return substr( is_string( $clean ) ? $clean : '', 0, self::CODE_MAX );
	}

	/**
	 * Manual link: https only, esc_url_raw, max 300 characters. Invalid input returns ''.
	 *
	 * @param string $url Raw URL.
	 */
	public static function sanitize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::URL_MAX || ! self::is_safe( $url, true ) ) {
			return '';
		}
		$clean = function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( $url, array( 'https' ) ) : $url;
		return strlen( $clean ) <= self::URL_MAX && self::is_safe( $clean, true ) ? $clean : '';
	}

	/**
	 * Carrier URL template: http(s) and it must contain {code}.
	 *
	 * @param string $template Raw template.
	 */
	public static function sanitize_template( string $template ): string {
		$template = trim( $template );
		if ( strlen( $template ) > self::URL_MAX || false === strpos( $template, '{code}' ) || ! self::is_safe( $template, false ) ) {
			return '';
		}
		return $template;
	}

	/**
	 * Builds the final URL.
	 *
	 * @param string $override Manual URL (already sanitized or raw: it is validated again).
	 * @param string $template Carrier template with {code} ('' when none).
	 * @param string $code     Tracking code.
	 */
	public static function build( string $override, string $template, string $code ): string {
		$manual = self::sanitize_url( $override );
		if ( '' !== $manual ) {
			return $manual;
		}
		$code     = self::sanitize_code( $code );
		$template = self::sanitize_template( $template );
		if ( '' === $code || '' === $template ) {
			return '';
		}
		return str_replace( '{code}', rawurlencode( $code ), $template );
	}

	/**
	 * Scheme + host check without whitespace or markup characters.
	 *
	 * @param string $url         URL.
	 * @param bool   $https_only  Require https.
	 */
	private static function is_safe( string $url, bool $https_only ): bool {
		if ( 1 === preg_match( '/[\s<>"\'\x5c]/', $url ) ) {
			return false;
		}
		$pattern = $https_only ? '#^https://[^/?\#]+#i' : '#^https?://[^/?\#]+#i';
		return 1 === preg_match( $pattern, $url );
	}
}
