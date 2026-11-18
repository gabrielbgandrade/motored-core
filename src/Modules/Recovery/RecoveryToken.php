<?php
/**
 * Recovery and opt-out token generation and verification.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Handles raw random_bytes tokens, SHA-256 hashed storage, hash_equals verification,
 * and recovery / opt-out URL generation.
 */
final class RecoveryToken {

	public const CACHE_PREFIX = 'token:';

	/**
	 * Generates a new random token, updates token_hash and token_expires_gmt in the DB,
	 * caches the raw token for reuse, and returns the raw token.
	 *
	 * @param int $recovery_id   Row ID.
	 * @param int $validity_days Token validity in days.
	 */
	public static function generate( int $recovery_id, int $validity_days = 7 ): string {
		$validity_days = max( 1, $validity_days );
		$raw_token     = bin2hex( random_bytes( 16 ) );
		$hash          = hash( 'sha256', $raw_token );
		$now           = time();
		$expires_gmt   = gmdate( 'Y-m-d H:i:s', $now + ( $validity_days * 86400 ) );

		Repository::update(
			$recovery_id,
			array(
				'token_hash'        => $hash,
				'token_expires_gmt' => $expires_gmt,
			)
		);

		Cache::set( self::CACHE_PREFIX . $recovery_id, $raw_token, $validity_days * 86400, CaptureLimiter::BUCKET );

		return $raw_token;
	}

	/**
	 * Returns the existing raw token if cached and not expired; otherwise generates a new one.
	 *
	 * @param int $recovery_id   Row ID.
	 * @param int $validity_days Token validity in days.
	 */
	public static function get_or_generate( int $recovery_id, int $validity_days = 7 ): string {
		$found  = false;
		$cached = Cache::get( self::CACHE_PREFIX . $recovery_id, CaptureLimiter::BUCKET, $found );
		if ( $found && is_string( $cached ) && '' !== $cached ) {
			$row = Repository::get( $recovery_id );
			if ( null !== $row && ! empty( $row['token_hash'] ) && ! empty( $row['token_expires_gmt'] ) ) {
				$expires = Status::timestamp( (string) $row['token_expires_gmt'] );
				if ( null !== $expires && $expires > time() && hash_equals( (string) $row['token_hash'], hash( 'sha256', $cached ) ) ) {
					return $cached;
				}
			}
		}

		return self::generate( $recovery_id, $validity_days );
	}

	/**
	 * Verifies whether a provided raw token matches the stored hash and is not expired.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token from URL.
	 * @param bool   $check_expiry Whether to enforce token_expires_gmt (true for recovery, false for opt-out).
	 */
	public static function verify( int $recovery_id, string $raw_token, bool $check_expiry = true ): bool {
		if ( $recovery_id <= 0 || '' === trim( $raw_token ) ) {
			return false;
		}

		$row = Repository::get( $recovery_id );
		if ( null === $row || empty( $row['token_hash'] ) ) {
			return false;
		}

		if ( $check_expiry && ! empty( $row['token_expires_gmt'] ) ) {
			$expires = Status::timestamp( (string) $row['token_expires_gmt'] );
			if ( null === $expires || $expires < time() ) {
				return false;
			}
		}

		$expected_hash = (string) $row['token_hash'];
		$actual_hash   = hash( 'sha256', $raw_token );

		return hash_equals( $expected_hash, $actual_hash );
	}

	/**
	 * Generates the full recovery URL for a cart.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token.
	 */
	public static function recovery_url( int $recovery_id, string $raw_token ): string {
		return add_query_arg( 'motored_recover', $recovery_id . '.' . $raw_token, home_url( '/' ) );
	}

	/**
	 * Generates the full opt-out URL for a cart.
	 *
	 * @param int    $recovery_id Row ID.
	 * @param string $raw_token   Raw token.
	 */
	public static function optout_url( int $recovery_id, string $raw_token ): string {
		return add_query_arg( 'motored_optout', $recovery_id . '.' . $raw_token, home_url( '/' ) );
	}
}
