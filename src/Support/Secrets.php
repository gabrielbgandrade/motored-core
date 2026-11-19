<?php
/**
 * Encrypted storage for secrets saved from the admin panel.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * libsodium secretbox with a key derived from wp_salt( 'auth' ). Stored value: "v1:" + base64( nonce . ciphertext ).
 *
 * Each secret lives in its OWN option (autoload=false, never registered with register_setting, so it is not
 * exposed by the REST settings endpoint). Decryption failures (changed salts, corrupted data, no libsodium)
 * never throw: the secret simply reads as "not available" (state "unreadable").
 */
final class Secrets {

	public const PREFIX     = 'v1:';
	public const STATE_NONE = 'none';
	public const STATE_OK   = 'ok';
	public const STATE_BAD  = 'unreadable';

	private const LABEL     = 'motored-core|secrets|v1';
	private const KEY_BYTES = 32;
	private const NONCE_LEN = 24;

	/**
	 * Per-request decrypted values (null = unreadable), by option name.
	 *
	 * @var array<string,string|null>
	 */
	private static array $memo = array();

	/**
	 * Whether libsodium is usable (PHP ext or the WordPress polyfill).
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Encrypts a value. Null when libsodium is not available.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): ?string {
		if ( ! self::available() ) {
			return null;
		}
		try {
			$nonce  = random_bytes( self::NONCE_LEN );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $cipher );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Decrypts a stored payload. Null on ANY failure (tampering, other salts, bad format); never throws.
	 *
	 * @param string $payload Stored value.
	 */
	public static function decrypt( string $payload ): ?string {
		if ( ! self::available() || 0 !== strpos( $payload, self::PREFIX ) ) {
			return null;
		}
		try {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
			if ( false === $raw || strlen( $raw ) <= self::NONCE_LEN ) {
				return null;
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_LEN ), substr( $raw, 0, self::NONCE_LEN ), self::key() );
			return false === $plain ? null : $plain;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether the option holds a stored value (readable or not).
	 *
	 * @param string $option Option name.
	 */
	public static function has( string $option ): bool {
		return self::STATE_NONE !== self::state( $option );
	}

	/**
	 * "none" (nothing stored), "ok" (readable) or "unreadable" (stored but cannot be decrypted).
	 *
	 * @param string $option Option name.
	 */
	public static function state( string $option ): string {
		$stored = get_option( self::name( $option ), '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return self::STATE_NONE;
		}
		return null === self::read( $option ) ? self::STATE_BAD : self::STATE_OK;
	}

	/**
	 * Decrypted value, or null when nothing is stored or it cannot be read. Never throws.
	 *
	 * @param string $option Option name.
	 */
	public static function read( string $option ): ?string {
		$option = self::name( $option );
		if ( array_key_exists( $option, self::$memo ) ) {
			return self::$memo[ $option ];
		}
		$stored = get_option( $option, '' );
		$plain  = is_string( $stored ) && '' !== $stored ? self::decrypt( $stored ) : null;
		if ( null !== $plain ) {
			Logger::protect( $plain );
		}
		self::$memo[ $option ] = $plain;
		return $plain;
	}

	/**
	 * Encrypts and stores a value (autoload=false). False when it could not be stored.
	 *
	 * @param string $option Option name.
	 * @param string $plain  Plain text.
	 */
	public static function store( string $option, string $plain ): bool {
		$option = self::name( $option );
		$cipher = self::encrypt( $plain );
		if ( null === $cipher || '' === $plain ) {
			return false;
		}
		unset( self::$memo[ $option ] );
		update_option( $option, $cipher, false );
		return true;
	}

	/**
	 * Deletes the stored value.
	 *
	 * @param string $option Option name.
	 */
	public static function forget( string $option ): void {
		$option = self::name( $option );
		unset( self::$memo[ $option ] );
		delete_option( $option );
	}

	/**
	 * Clears the per-request memo (tests).
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * 32-byte key derived from the site's auth salt and a fixed plugin label.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( self::LABEL . '|' . wp_salt( 'auth' ), '', self::KEY_BYTES );
	}

	/**
	 * Validates the option name (only motored_* options).
	 *
	 * @param string $option Option name.
	 * @throws \InvalidArgumentException When the name is not ours.
	 */
	private static function name( string $option ): string {
		if ( 1 !== preg_match( '/^motored_[a-z0-9_]+$/', $option ) ) {
			throw new \InvalidArgumentException( 'Invalid secret option name.' );
		}
		return $option;
	}
}
