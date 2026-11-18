<?php
/**
 * Resolves a human phone number to a confirmed WAHA chat id.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Phone -> candidate (Phone::normalize) -> check-exists -> chat id, cached:
 * positive 30 days, negative 24 h. The cache is only an optimization (a miss re-asks WAHA).
 * Keys use a hash, so no phone number is stored in key names.
 */
final class ChatResolver {

	public const POSITIVE_TTL = 2592000;
	public const NEGATIVE_TTL = 86400;
	public const BUCKET       = 'waha';

	/**
	 * Constructor.
	 *
	 * @param WahaClient $client       Transport.
	 * @param string     $country_code Default country code.
	 */
	public function __construct(
		private WahaClient $client,
		private string $country_code
	) {
	}

	/**
	 * Resolves a chat id. Success `data['chat_id']`; failures: invalid_phone, no_whatsapp (both
	 * not retryable) or the transport failure of check-exists.
	 *
	 * @param string $raw_phone Phone in any common format.
	 */
	public function resolve( string $raw_phone ): Result {
		$candidate = Phone::normalize( $raw_phone, $this->country_code );
		if ( null === $candidate ) {
			return Result::failure( 'invalid_phone', 'Phone number is not valid.' );
		}

		$key    = 'chat:' . md5( $candidate );
		$found  = false;
		$cached = Cache::get( $key, self::BUCKET, $found );
		if ( $found ) {
			if ( is_string( $cached ) && '' !== $cached ) {
				return Result::success( 0, array( 'chat_id' => $cached ) );
			}
			return Result::failure( 'no_whatsapp', 'Number is not on WhatsApp.' );
		}

		$check = $this->client->checkExists( Phone::digits( $candidate ) );
		if ( ! $check->ok ) {
			return $check;
		}
		if ( true !== ( $check->data['exists'] ?? false ) ) {
			Cache::set( $key, false, self::NEGATIVE_TTL, self::BUCKET );
			return Result::failure( 'no_whatsapp', 'Number is not on WhatsApp.' );
		}

		$chat_id = isset( $check->data['chat_id'] ) && is_string( $check->data['chat_id'] ) && '' !== $check->data['chat_id']
			? $check->data['chat_id']
			: Phone::chat_id( $candidate );
		Cache::set( $key, $chat_id, self::POSITIVE_TTL, self::BUCKET );
		return Result::success( 0, array( 'chat_id' => $chat_id ) );
	}
}
