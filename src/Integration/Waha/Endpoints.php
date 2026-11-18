<?php
/**
 * WAHA endpoint definitions (all UNVERIFIED, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\Waha;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that knows WAHA paths and payload field names. If the instance's Swagger
 * differs, fix it here only.
 */
final class Endpoints {

	public const SEND_TEXT    = '/api/sendText';
	public const CHECK_EXISTS = '/api/contacts/check-exists';

	/**
	 * Path of a session resource.
	 *
	 * @param string $session Session name.
	 */
	public static function session( string $session ): string {
		return '/api/sessions/' . rawurlencode( $session );
	}

	/**
	 * JSON body of sendText.
	 *
	 * @param string $session Session.
	 * @param string $chat_id Chat id ("{digits}@c.us").
	 * @param string $text    Message text.
	 * @return array<string,string>
	 */
	public static function send_text_body( string $session, string $chat_id, string $text ): array {
		return array(
			'session' => $session,
			'chatId'  => $chat_id,
			'text'    => $text,
		);
	}

	/**
	 * Query args of check-exists.
	 *
	 * @param string $session Session.
	 * @param string $phone   Digits only.
	 * @return array<string,string>
	 */
	public static function check_exists_query( string $session, string $phone ): array {
		return array(
			'phone'   => $phone,
			'session' => $session,
		);
	}
}
