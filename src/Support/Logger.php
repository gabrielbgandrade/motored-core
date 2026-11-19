<?php
/**
 * Logging wrapper.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over WC_Logger (source "motored-core") honoring the configured
 * threshold. NEVER pass message bodies or full personal data: mask phone/email.
 */
final class Logger {

	public const SOURCE   = 'motored-core';
	public const REDACTED = '[redacted]';

	/**
	 * Registered secret values (keys of the array).
	 *
	 * @var array<string,bool>
	 */
	private static array $secrets = array();

	/**
	 * Severity order (lower = more severe).
	 *
	 * @var array<string,int>
	 */
	private const LEVELS = array(
		'emergency' => 0,
		'alert'     => 1,
		'critical'  => 2,
		'error'     => 3,
		'warning'   => 4,
		'notice'    => 5,
		'info'      => 6,
		'debug'     => 7,
	);

	/**
	 * Logs a message when its level passes the configured threshold.
	 *
	 * @param string              $level   One of the PSR-3 levels.
	 * @param string              $message Message WITHOUT personal data.
	 * @param array<string,mixed> $context Extra context (no PII).
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! self::should_log( $level, (string) Options::get( 'general', 'log_level', 'warning' ) ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context = self::redact( $context );
		wc_get_logger()->log( $level, self::redact_string( $message ), array_merge( is_array( $context ) ? $context : array(), array( 'source' => self::SOURCE ) ) );
	}

	/**
	 * Registers a secret value so it is removed from every log message and context.
	 *
	 * @param string $secret Secret (ignored when shorter than 4 characters).
	 */
	public static function protect( string $secret ): void {
		if ( strlen( $secret ) >= 4 ) {
			self::$secrets[ $secret ] = true;
		}
	}

	/**
	 * Removes secrets from a string: registered values and "X-Api-Key: value" pairs.
	 *
	 * @param string $text Text.
	 */
	public static function redact_string( string $text ): string {
		foreach ( array_keys( self::$secrets ) as $secret ) {
			$text = str_replace( (string) $secret, self::REDACTED, $text );
		}
		$clean = preg_replace( '/(x-api-key|api[-_]?key|authorization)(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i', '$1$2' . self::REDACTED, $text );
		return is_string( $clean ) ? $clean : $text;
	}

	/**
	 * Redacts a value recursively: secret-looking keys (X-Api-Key, api_key, Authorization) lose their value,
	 * and registered secrets are removed from every string.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_string( $value ) ) {
			return self::redact_string( $value );
		}
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && 1 === preg_match( '/^(x[-_]?api[-_]?key|api[-_]?key|authorization)$/i', $key ) ) {
					$clean[ $key ] = self::REDACTED;
					continue;
				}
				$clean[ is_string( $key ) ? self::redact_string( $key ) : $key ] = self::redact( $item );
			}
			return $clean;
		}
		if ( is_object( $value ) ) {
			// Objects (exceptions, WP_Error, responses) are never logged raw: they may carry headers.
			return '[object ' . get_class( $value ) . ']';
		}
		return $value;
	}

	/**
	 * Forgets the registered secrets (tests).
	 */
	public static function reset_secrets(): void {
		self::$secrets = array();
	}

	/**
	 * Logs at error level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Logs at warning level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Logs at info level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Logs at debug level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Pure threshold check. Unknown levels are never logged; unknown thresholds fall back to "warning".
	 *
	 * @param string $level     Level of the entry.
	 * @param string $threshold Configured minimum level.
	 */
	public static function should_log( string $level, string $threshold ): bool {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			return false;
		}
		$limit = self::LEVELS[ $threshold ] ?? self::LEVELS['warning'];
		return self::LEVELS[ $level ] <= $limit;
	}

	/**
	 * Masks a phone number, keeping only the last 3 digits ("+5491155551234" -> "***234").
	 *
	 * @param string $phone Phone number.
	 */
	public static function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		$digits = is_string( $digits ) ? $digits : '';
		if ( strlen( $digits ) <= 3 ) {
			return '***';
		}
		return '***' . substr( $digits, -3 );
	}

	/**
	 * Masks an e-mail address ("juan@example.com" -> "j***@e***.com").
	 *
	 * @param string $email E-mail address.
	 */
	public static function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return '***';
		}
		$domain = explode( '.', $parts[1], 2 );
		$tld    = isset( $domain[1] ) ? '.' . $domain[1] : '';
		return substr( $parts[0], 0, 1 ) . '***@' . substr( $domain[0], 0, 1 ) . '***' . $tld;
	}
}
