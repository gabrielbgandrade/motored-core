<?php
/**
 * WAHA module configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_waha`. Constants MOTORED_WAHA_URL / _API_KEY / _SESSION override
 * the stored values (and the admin shows those fields read-only).
 */
final class Config {

	public const DEFAULTS = array(
		'url'               => '',
		'api_key'           => '',
		'session'           => 'default',
		'country_code'      => '54',
		'rate_per_minute'   => '6',
		'rate_per_hour'     => '100',
		'breaker_threshold' => '5',
		'breaker_minutes'   => '15',
		'interval_min'      => '20',
		'interval_max'      => '60',
		'daily_cap'         => '150',
		'low_percent'       => '60',
		'typing'            => 'yes',
		'enabled'           => 'no',
		'test_mode'         => 'yes',
		'test_numbers'      => '',
	);

	public const MODE_OFF  = 'off';
	public const MODE_TEST = 'test';
	public const MODE_LIVE = 'live';

	private const CONSTANTS = array(
		'url'     => 'MOTORED_WAHA_URL',
		'api_key' => 'MOTORED_WAHA_API_KEY',
		'session' => 'MOTORED_WAHA_SESSION',
	);

	/**
	 * Whether a key is overridden by a wp-config.php constant.
	 *
	 * @param string $key Config key.
	 */
	public static function is_locked( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] )
			&& defined( self::CONSTANTS[ $key ] )
			&& '' !== trim( (string) constant( self::CONSTANTS[ $key ] ) );
	}

	/**
	 * Name of the constant that overrides a key ('' when none exists for it).
	 *
	 * @param string $key Config key.
	 */
	public static function constant_name( string $key ): string {
		return self::CONSTANTS[ $key ] ?? '';
	}

	/**
	 * Base URL without trailing slash.
	 */
	public static function url(): string {
		return rtrim( self::text( 'url' ), '/' );
	}

	/**
	 * API key (secret: never log).
	 */
	public static function api_key(): string {
		return self::text( 'api_key' );
	}

	/**
	 * WAHA session name.
	 */
	public static function session(): string {
		$session = self::text( 'session' );
		return '' !== $session ? $session : self::DEFAULTS['session'];
	}

	/**
	 * Default country calling code (digits).
	 */
	public static function country_code(): string {
		$code = preg_replace( '/\D+/', '', self::text( 'country_code' ) );
		return is_string( $code ) && '' !== $code ? $code : self::DEFAULTS['country_code'];
	}

	/**
	 * Messages allowed per minute.
	 */
	public static function rate_per_minute(): int {
		return self::positive_int( 'rate_per_minute' );
	}

	/**
	 * Messages allowed per hour.
	 */
	public static function rate_per_hour(): int {
		return self::positive_int( 'rate_per_hour' );
	}

	/**
	 * Consecutive failures that open the circuit breaker.
	 */
	public static function breaker_threshold(): int {
		return self::positive_int( 'breaker_threshold' );
	}

	/**
	 * Minutes the breaker pauses sending.
	 */
	public static function breaker_minutes(): int {
		return self::positive_int( 'breaker_minutes' );
	}

	/**
	 * Minimum seconds between two messages (0 allowed).
	 */
	public static function interval_min(): int {
		$value = (int) self::text( 'interval_min' );
		return $value >= 0 ? $value : (int) self::DEFAULTS['interval_min'];
	}

	/**
	 * Maximum seconds between two messages (never below the minimum).
	 */
	public static function interval_max(): int {
		return max( self::interval_min(), self::positive_int( 'interval_max' ) );
	}

	/**
	 * Messages allowed per day (site timezone).
	 */
	public static function daily_cap(): int {
		return self::positive_int( 'daily_cap' );
	}

	/**
	 * Share (1-100) of the daily cap that low priority messages may use.
	 */
	public static function low_percent(): int {
		return min( 100, self::positive_int( 'low_percent' ) );
	}

	/**
	 * Whether to simulate typing before sending.
	 */
	public static function typing(): bool {
		return 'no' !== self::text( 'typing' );
	}

	/**
	 * Master switch "Send messages" (off by default: nothing leaves the store).
	 */
	public static function sending_enabled(): bool {
		return 'yes' === self::text( 'enabled' );
	}

	/**
	 * Test mode: only numbers of the allowed list receive messages. On unless the admin turns it off explicitly.
	 */
	public static function test_mode(): bool {
		return 'no' !== self::text( 'test_mode' );
	}

	/**
	 * Delivery state: off (nothing is sent), test (allowed list only) or live (real customers).
	 */
	public static function mode(): string {
		if ( ! self::sending_enabled() ) {
			return self::MODE_OFF;
		}
		return self::test_mode() ? self::MODE_TEST : self::MODE_LIVE;
	}

	/**
	 * Allowed numbers of the test mode, normalized to "+<digits>" (one per line or separated by , ;).
	 *
	 * @return list<string>
	 */
	public static function test_numbers(): array {
		$parts   = preg_split( '/[\r\n,;]+/', self::text( 'test_numbers' ) );
		$numbers = array();
		foreach ( false === $parts ? array() : $parts as $part ) {
			$phone = Phone::normalize( $part, self::country_code() );
			if ( null !== $phone ) {
				$numbers[ $phone ] = $phone;
			}
		}
		return array_values( $numbers );
	}

	/**
	 * Whether a message to this number may leave in the current mode (off: never; test: allowed list; live: always).
	 *
	 * @param string $phone Recipient in any common format.
	 */
	public static function may_send_to( string $phone ): bool {
		switch ( self::mode() ) {
			case self::MODE_LIVE:
				return true;
			case self::MODE_TEST:
				$normalized = Phone::normalize( $phone, self::country_code() );
				return null !== $normalized && in_array( $normalized, self::test_numbers(), true );
			default:
				return false;
		}
	}

	/**
	 * Whether URL and API key are available.
	 */
	public static function is_configured(): bool {
		return '' !== self::url() && '' !== self::api_key();
	}

	/**
	 * Trimmed string value (constant first).
	 *
	 * @param string $key Config key.
	 */
	private static function text( string $key ): string {
		if ( self::is_locked( $key ) ) {
			return trim( (string) constant( self::CONSTANTS[ $key ] ) );
		}
		$value = Options::get( 'waha', $key, self::DEFAULTS[ $key ] ?? '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Integer >= 1 (falls back to the default when invalid).
	 *
	 * @param string $key Config key.
	 */
	private static function positive_int( string $key ): int {
		$value = (int) self::text( $key );
		return $value >= 1 ? $value : (int) self::DEFAULTS[ $key ];
	}
}
