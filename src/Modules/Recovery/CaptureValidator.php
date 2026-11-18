<?php
/**
 * Validation of the contact data sent by the checkout script (pure).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Modules\Waha\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts e-mail, phone and first name only; discards obvious junk.
 */
final class CaptureValidator {

	private const JUNK_DOMAINS = array( 'example.com', 'example.org', 'example.net', 'test.com', 'prueba.com', 'email.com' );
	private const JUNK_LOCALS  = array( 'test', 'testing', 'prueba', 'asd', 'asdf', 'qwerty', 'xxx', 'abc', 'admin', 'none', 'no' );

	/**
	 * Validates a payload.
	 *
	 * @param array<string,mixed> $input        Raw values (email, phone, name).
	 * @param string              $country_code Default calling code for local phones.
	 * @return array{email:string,phone:string,first_name:string}|null Null when neither e-mail nor phone is usable.
	 */
	public static function validate( array $input, string $country_code ): ?array {
		$email = self::email( self::text( $input['email'] ?? '' ) );
		$phone = self::phone( self::text( $input['phone'] ?? '' ), $country_code );
		if ( '' === $email && '' === $phone ) {
			return null;
		}
		return array(
			'email'      => $email,
			'phone'      => $phone,
			'first_name' => self::name( self::text( $input['name'] ?? '' ) ),
		);
	}

	/**
	 * Valid, lowercase e-mail or ''.
	 *
	 * @param string $raw Input.
	 */
	public static function email( string $raw ): string {
		$email = strtolower( trim( $raw ) );
		if ( strlen( $email ) < 6 || strlen( $email ) > 100 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( in_array( $domain, self::JUNK_DOMAINS, true ) || in_array( $local, self::JUNK_LOCALS, true ) || 1 === preg_match( '/^(.)\1+$/', $local ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Normalized phone ("+549...") or ''.
	 *
	 * @param string $raw          Input.
	 * @param string $country_code Default calling code.
	 */
	public static function phone( string $raw, string $country_code ): string {
		$phone = Phone::normalize( $raw, $country_code );
		if ( null === $phone ) {
			return '';
		}
		$tail = substr( Phone::digits( $phone ), -8 );
		if ( 1 === preg_match( '/^(\d)\1{7}$/', $tail ) || false !== strpos( '0123456789012345678', $tail ) || false !== strpos( '9876543210987654321', $tail ) ) {
			return '';
		}
		return $phone;
	}

	/**
	 * First name: letters, spaces, dots, apostrophes and hyphens only, max 60 chars; otherwise ''.
	 *
	 * @param string $raw Input.
	 */
	public static function name( string $raw ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', $raw ) ?? '' );
		if ( '' === $name || 1 !== preg_match( "/^[\\p{L}][\\p{L} .'-]*$/u", $name ) ) {
			return '';
		}
		return mb_substr( $name, 0, 60 );
	}

	/**
	 * Scalar string of bounded length (arrays and long values are discarded).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return ( is_string( $value ) && strlen( $value ) <= 200 ) ? $value : '';
	}
}
