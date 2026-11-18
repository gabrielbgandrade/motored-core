<?php
/**
 * Suppression list management for opt-outs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table name comes from Db::table() (validated); values are always prepared.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Stores salted hashes of opted-out phone numbers or emails.
 * PII is never stored in plaintext in the suppressions table.
 */
final class Suppression {

	/**
	 * Computes a salted SHA-256 hash of a phone or email.
	 *
	 * @param string $value Phone or email.
	 */
	public static function hash( string $value ): string {
		$normalized = self::normalize( $value );
		if ( '' === $normalized ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'motored_suppression' ) : 'motored_default_salt';
		return hash_hmac( 'sha256', $normalized, $salt );
	}

	/**
	 * Suppresses a phone or email for a specific channel or 'all'.
	 *
	 * @param string $value   Phone or email.
	 * @param string $channel 'whatsapp', 'email', or 'all'.
	 * @return bool True if stored or already suppressed.
	 */
	public static function suppress( string $value, string $channel = 'all' ): bool {
		global $wpdb;
		$hash = self::hash( $value );
		if ( '' === $hash ) {
			return false;
		}

		$channel = in_array( $channel, array( 'whatsapp', 'email', 'all' ), true ) ? $channel : 'all';
		$table   = Db::table( 'suppressions' );
		$now     = gmdate( 'Y-m-d H:i:s' );

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE hash = %s AND (channel = %s OR channel = 'all') LIMIT 1",
				$hash,
				$channel
			)
		);

		if ( null !== $exists ) {
			return true;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'hash'        => $hash,
				'channel'     => $channel,
				'created_gmt' => $now,
			),
			array( '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Checks if a phone or email is suppressed for a given channel.
	 *
	 * @param string $value   Phone or email.
	 * @param string $channel 'whatsapp' or 'email'.
	 */
	public static function is_suppressed( string $value, string $channel ): bool {
		global $wpdb;
		$hash = self::hash( $value );
		if ( '' === $hash ) {
			return false;
		}

		$table  = Db::table( 'suppressions' );
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE hash = %s AND (channel = %s OR channel = 'all') LIMIT 1",
				$hash,
				$channel
			)
		);

		return null !== $exists;
	}

	/**
	 * Normalizes input: if email, lowercase & trim; if phone, digits only.
	 *
	 * @param string $value Raw input.
	 */
	public static function normalize( string $value ): string {
		$value = trim( $value );
		if ( str_contains( $value, '@' ) ) {
			return strtolower( $value );
		}
		$digits = Phone::digits( $value );
		return '' !== $digits ? $digits : strtolower( $value );
	}
}
