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

	public const SOURCE = 'motored-core';

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
		wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
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
