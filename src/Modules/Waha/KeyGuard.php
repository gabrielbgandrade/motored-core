<?php
/**
 * Guards the WAHA URL and the stored API key against exfiltration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Support\Logger;
use Motored\Core\Support\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on `pre_update_option_motored_waha` (any save of the module option, whoever does it):
 * only `manage_options` users may change the URL, a URL with embedded credentials is rejected, and a change of
 * host/port deletes the stored key so it can never be sent to a different server.
 */
final class KeyGuard {

	public const CAPABILITY = 'manage_options';

	/**
	 * Registers the filter.
	 */
	public static function register(): void {
		add_filter( 'pre_update_option_motored_waha', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed $new_value New option value.
	 * @param mixed $old_value Current option value.
	 * @return mixed
	 */
	public static function filter( $new_value, $old_value ) {
		if ( ! is_array( $new_value ) ) {
			return $new_value;
		}
		$old     = is_array( $old_value ) ? $old_value : array();
		$old_url = self::url_of( $old );
		$new_url = self::url_of( $new_value );
		if ( $new_url === $old_url ) {
			return $new_value;
		}

		// A logged-in user without the capability can never change the URL (CLI/cron have no user: allowed).
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! current_user_can( self::CAPABILITY ) ) {
			$new_value['url'] = $old_url;
			return $new_value;
		}

		if ( self::has_credentials( $new_url ) ) {
			$new_value['url'] = $old_url;
			self::notice( __( 'The WAHA URL cannot contain a user or password. The URL was not changed.', 'motored-core' ) );
			return $new_value;
		}

		if ( self::origin( $new_url ) !== self::origin( $old_url ) && Secrets::has( Config::SECRET_OPTION ) ) {
			Secrets::forget( Config::SECRET_OPTION );
			Logger::info(
				'WAHA API key deleted: the WAHA server changed.',
				array( 'user' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 )
			);
			self::notice( __( 'The WAHA server changed, so the saved API key was deleted. Enter the API key again.', 'motored-core' ) );
		}
		return $new_value;
	}

	/**
	 * Whether a URL carries a user or password ("https://user:pass@host").
	 *
	 * @param string $url URL.
	 */
	public static function has_credentials( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) );
	}

	/**
	 * "host:port" (lowercase, default port by scheme) used to compare servers; '' for invalid URLs.
	 *
	 * @param string $url URL.
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === $scheme ? 80 : 443 );
		return strtolower( (string) $parts['host'] ) . ':' . $port;
	}

	/**
	 * Trimmed URL of an option array.
	 *
	 * @param array<mixed> $values Option value.
	 */
	private static function url_of( array $values ): string {
		return isset( $values['url'] ) && is_scalar( $values['url'] ) ? trim( (string) $values['url'] ) : '';
	}

	/**
	 * Shows a notice on the settings page.
	 *
	 * @param string $text Message.
	 */
	private static function notice( string $text ): void {
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( $text );
		}
	}
}
