<?php
/**
 * Phone normalization (pure functions).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

defined( 'ABSPATH' ) || exit;

/**
 * Best-effort E.164 normalization. The result is only a CANDIDATE: the heuristics for Argentina
 * are fragile, so callers must confirm it with WAHA check-exists (see ChatResolver).
 *
 * Argentine mobile = +54 9 {area}{number} (10 national digits): no leading 0 trunk prefix and
 * no "15" mobile prefix. Other countries only get the generic rule (trunk "0" removed, country
 * code prepended, 8-15 digits).
 */
final class Phone {

	/**
	 * Normalizes to "+<digits>" or null when the input cannot be a phone number.
	 *
	 * @param string $raw          Input in any common format.
	 * @param string $country_code Default country calling code (digits, e.g. "54").
	 */
	public static function normalize( string $raw, string $country_code = '54' ): ?string {
		$raw = trim( $raw );
		$cc  = preg_replace( '/\D+/', '', $country_code );
		if ( '' === $raw || ! is_string( $cc ) || '' === $cc ) {
			return null;
		}
		// Only digits, spaces and common separators are accepted.
		if ( 1 === preg_match( '/[^\d\s().+\-\/]/', $raw ) ) {
			return null;
		}

		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return null;
		}
		$international = str_starts_with( $raw, '+' );
		if ( str_starts_with( $digits, '00' ) ) {
			$digits        = substr( $digits, 2 );
			$international = true;
		}

		if ( $international ) {
			if ( ! str_starts_with( $digits, $cc ) ) {
				return self::generic_valid( $digits ); // Foreign number: trust the caller's prefix.
			}
			return '54' === $cc ? self::argentina( substr( $digits, 2 ) ) : self::generic_valid( $digits );
		}

		if ( '54' === $cc ) {
			if ( str_starts_with( $digits, '54' ) && strlen( $digits ) >= 12 ) {
				$digits = substr( $digits, 2 );
			}
			return self::argentina( $digits );
		}

		if ( str_starts_with( $digits, $cc ) && strlen( $digits ) >= 11 ) {
			return self::generic_valid( $digits );
		}
		return self::generic_valid( $cc . ltrim( $digits, '0' ) );
	}

	/**
	 * WAHA chat id for an E.164 number ("+5491155551234" -> "5491155551234@c.us").
	 *
	 * @param string $e164 Normalized number.
	 */
	public static function chat_id( string $e164 ): string {
		return self::digits( $e164 ) . '@c.us';
	}

	/**
	 * Digits only (no "+").
	 *
	 * @param string $e164 Normalized number.
	 */
	public static function digits( string $e164 ): string {
		$digits = preg_replace( '/\D+/', '', $e164 );
		return is_string( $digits ) ? $digits : '';
	}

	/**
	 * Argentine mobile from the national part (country code already removed).
	 *
	 * @param string $national Digits.
	 */
	private static function argentina( string $national ): ?string {
		if ( strlen( $national ) > 10 && str_starts_with( $national, '9' ) ) {
			$national = substr( $national, 1 ); // Mobile indicator.
		}
		if ( str_starts_with( $national, '0' ) ) {
			$national = substr( $national, 1 ); // Trunk prefix.
		}
		if ( 12 === strlen( $national ) ) {
			// Old "15" mobile prefix right after a 2, 3 or 4 digit area code.
			foreach ( array( 2, 3, 4 ) as $area_length ) {
				if ( '15' === substr( $national, $area_length, 2 ) ) {
					$national = substr( $national, 0, $area_length ) . substr( $national, $area_length + 2 );
					break;
				}
			}
		}
		if ( 1 !== preg_match( '/^[1-3]\d{9}$/', $national ) ) {
			return null;
		}
		return '+549' . $national;
	}

	/**
	 * "+digits" when the length is plausible (8-15 digits, E.164 maximum).
	 *
	 * @param string $digits Digits with country code.
	 */
	private static function generic_valid( string $digits ): ?string {
		$length = strlen( $digits );
		return ( $length >= 8 && $length <= 15 && ! str_starts_with( $digits, '0' ) ) ? '+' . $digits : null;
	}
}
