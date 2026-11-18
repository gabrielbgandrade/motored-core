<?php
/**
 * Recovery and opt-out token generation and verification.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Tokens are HMACs keyed with a WordPress salt, so the raw token is never stored anywhere
 * (no cache, no DB) and can be re-derived for the next message:
 *
 * - Recovery token = HMAC( id | token_expires_gmt ). Only its SHA-256 is stored (token_hash); verification
 *   uses hash_equals and the stored expiry. A new token is issued only when the current one expired, so
 *   links already sent keep working until then (no rotation on cache loss).
 * - Opt-out token = HMAC( id | optout ): no expiry and no DB state, so every opt-out link ever sent works.
 *
 * Rotating the WordPress salts invalidates all links (intended).
 */
final class RecoveryToken {

	public const LENGTH = 32;

	/**
	 * Issues a new recovery token (new expiry) and stores its hash.
	 *
	 * @param int $recovery_id   Row ID.
	 * @param int $validity_days Token validity in days.
	 */
	public static function generate( int $recovery_id, int $validity_days = 7 ): string {
		$expires_gmt = gmdate( 'Y-m-d H:i:s', time() + ( max( 1, $validity_days ) * 86400 ) );
		$raw_token   = self::derive( $recovery_id . '|' . $expires_gmt );

		Repository::update(
			$recovery_id,
			array(
				'token_hash'        => hash( 'sha256', $raw_token ),
				'token_expires_gmt' => $expires_gmt,
			)
		);

		return $raw_token;
	}

	/**
	 * Returns the current recovery token while it is valid; otherwise issues a new one.
	 *
	 * @param int $recovery_id   Row ID.
	 * @param int $validity_days Token validity in days.
	 */
	public static function get_or_generate( int $recovery_id, int $validity_days = 7 ): string {
		$row = Repository::get( $recovery_id );
		if ( null !== $row && ! empty( $row['token_hash'] ) && ! empty( $row['token_expires_gmt'] ) ) {
			$expires = Status::timestamp( (string) $row['token_expires_gmt'] );
			$raw     = self::derive( $recovery_id . '|' . (string) $row['token_expires_gmt'] );
			if ( null !== $expires && $expires > time() && hash_equals( (string) $row['token_hash'], hash( 'sha256', $raw ) ) ) {
				return $raw;
			}
		}
		return self::generate( $recovery_id, $validity_days );
	}

	/**
	 * Verifies a recovery token against the stored hash and expiry.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token from the URL.
	 */
	public static function verify( int $recovery_id, string $raw_token ): bool {
		if ( $recovery_id <= 0 || '' === trim( $raw_token ) ) {
			return false;
		}
		$row = Repository::get( $recovery_id );
		if ( null === $row || empty( $row['token_hash'] ) || empty( $row['token_expires_gmt'] ) ) {
			return false;
		}
		$expires = Status::timestamp( (string) $row['token_expires_gmt'] );
		if ( null === $expires || $expires < time() ) {
			return false;
		}
		return hash_equals( (string) $row['token_hash'], hash( 'sha256', strtolower( $raw_token ) ) );
	}

	/**
	 * Opt-out token of a row (stateless).
	 *
	 * @param int $recovery_id Row ID.
	 */
	public static function optout_token( int $recovery_id ): string {
		return self::derive( $recovery_id . '|optout' );
	}

	/**
	 * Verifies an opt-out token.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token from the URL.
	 */
	public static function verify_optout( int $recovery_id, string $raw_token ): bool {
		return $recovery_id > 0 && hash_equals( self::optout_token( $recovery_id ), strtolower( trim( $raw_token ) ) );
	}

	/**
	 * Full recovery URL.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token.
	 */
	public static function recovery_url( int $recovery_id, string $raw_token ): string {
		return add_query_arg( 'motored_recover', $recovery_id . '.' . $raw_token, home_url( '/' ) );
	}

	/**
	 * Full opt-out URL.
	 *
	 * @param int $recovery_id Row ID.
	 */
	public static function optout_url( int $recovery_id ): string {
		return add_query_arg( 'motored_optout', $recovery_id . '.' . self::optout_token( $recovery_id ), home_url( '/' ) );
	}

	/**
	 * HMAC-SHA256 with the plugin's WordPress salt, truncated to LENGTH hex chars.
	 *
	 * @param string $data Data.
	 */
	private static function derive( string $data ): string {
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'motored_recovery' ) : 'motored-core-test-key';
		return substr( hash_hmac( 'sha256', $data, $key ), 0, self::LENGTH );
	}
}
