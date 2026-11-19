<?php
/**
 * HTTP client for WAHA.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\Waha;

use Motored\Core\Modules\Waha\Config;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Thin transport: sendText, checkExists, sessionStatus. Never call from a customer request
 * (only the admin test buttons and Action Scheduler workers do).
 *
 * Limits: connect timeout 5 s (via http_api_curl, unverified), total 10 s, no redirects,
 * TLS verified, https mandatory unless the `motored_waha_allow_insecure_http` filter allows http.
 */
class WahaClient {

	public const CONNECT_TIMEOUT = 5;
	public const TIMEOUT         = 10;

	/**
	 * Sends a text message.
	 *
	 * @param string $chat_id Chat id ("{digits}@c.us").
	 * @param string $text    Message text (never logged).
	 */
	public function sendText( string $chat_id, string $text ): Result { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by the module API.
		$result = $this->request( 'POST', Endpoints::SEND_TEXT, array(), Endpoints::send_text_body( Config::session(), $chat_id, $text ) );
		if ( $result->ok ) {
			// Drop the response body: it may echo the recipient or the text.
			$result = Result::success( $result->http_status );
		}
		return $this->log( 'sendText', $result );
	}

	/**
	 * Shows "typing..." in the chat (best effort; callers ignore failures).
	 *
	 * @param string $chat_id Chat id.
	 */
	public function startTyping( string $chat_id ): Result { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->log( 'startTyping', $this->request( 'POST', Endpoints::START_TYPING, array(), Endpoints::typing_body( Config::session(), $chat_id ) ) );
	}

	/**
	 * Clears "typing..." in the chat (best effort).
	 *
	 * @param string $chat_id Chat id.
	 */
	public function stopTyping( string $chat_id ): Result { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->log( 'stopTyping', $this->request( 'POST', Endpoints::STOP_TYPING, array(), Endpoints::typing_body( Config::session(), $chat_id ) ) );
	}

	/**
	 * Checks whether a number has WhatsApp. On success `data` has `exists` (bool) and `chat_id`.
	 *
	 * @param string $phone Digits only (E.164 without "+").
	 */
	public function checkExists( string $phone ): Result { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$result = $this->request( 'GET', Endpoints::CHECK_EXISTS, Endpoints::check_exists_query( Config::session(), $phone ) );
		if ( $result->ok ) {
			$exists = $result->data['numberExists'] ?? null;
			if ( ! is_bool( $exists ) ) {
				$result = Result::failure( 'invalid_response', 'Unexpected response shape.', false, $result->http_status );
			} else {
				$chat_id = isset( $result->data['chatId'] ) && is_string( $result->data['chatId'] ) ? $result->data['chatId'] : '';
				$result  = Result::success(
					$result->http_status,
					array(
						'exists'  => $exists,
						'chat_id' => $chat_id,
					)
				);
			}
		}
		return $this->log( 'checkExists', $result );
	}

	/**
	 * Session state. On success `data` has `status` (e.g. WORKING, STOPPED, SCAN_QR_CODE).
	 */
	public function sessionStatus(): Result { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$result = $this->request( 'GET', Endpoints::session( Config::session() ) );
		if ( $result->ok ) {
			$status = $result->data['status'] ?? null;
			if ( ! is_string( $status ) || '' === $status ) {
				$result = Result::failure( 'invalid_response', 'Unexpected response shape.', false, $result->http_status );
			} else {
				$result = Result::success( $result->http_status, array( 'status' => $status ) );
			}
		}
		return $this->log( 'sessionStatus', $result );
	}

	/**
	 * Performs one request and classifies the outcome.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Endpoint path.
	 * @param array<string,string>      $query  Query args.
	 * @param array<string,string>|null $body   JSON body.
	 */
	private function request( string $method, string $path, array $query = array(), ?array $body = null ): Result {
		$base = Config::url();
		if ( '' === $base || '' === Config::api_key() ) {
			return Result::failure( 'not_configured', 'WAHA URL or API key missing.' );
		}
		$scheme = strtolower( (string) wp_parse_url( $base, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $base, PHP_URL_HOST );
		$user   = (string) wp_parse_url( $base, PHP_URL_USER );
		$pass   = (string) wp_parse_url( $base, PHP_URL_PASS );
		if ( '' === $host || '' !== $user || '' !== $pass || ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return Result::failure( 'invalid_url', 'WAHA URL is invalid.' );
		}
		if ( 'https' !== $scheme && true !== apply_filters( 'motored_waha_allow_insecure_http', false ) ) {
			return Result::failure( 'insecure_url', 'WAHA URL must use https.' );
		}

		$url = $base . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'X-Api-Key' => Config::api_key(),
				'Accept'    => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$connect_timeout = static function ( $handle ): void {
			if ( ( is_resource( $handle ) || is_object( $handle ) ) && defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, WahaClient::CONNECT_TIMEOUT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Only way to set the connect timeout.
			}
		};
		add_action( 'http_api_curl', $connect_timeout );
		$response = wp_remote_request( $url, $args );
		remove_action( 'http_api_curl', $connect_timeout );

		return $this->classify( $response );
	}

	/**
	 * Maps a wp_remote_request() return value to a Result.
	 *
	 * @param mixed $response WP_Error or response array.
	 */
	private function classify( $response ): Result {
		if ( is_wp_error( $response ) ) {
			$timeout = false !== stripos( $response->get_error_message(), 'timed out' );
			return Result::failure( $timeout ? 'timeout' : 'transport', $timeout ? 'Request timed out.' : 'Connection failed.', true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status || 403 === $status ) {
			return Result::failure( 'auth', 'Authentication rejected.', false, $status );
		}
		if ( 404 === $status ) {
			return Result::failure( 'not_found', 'Resource not found.', false, $status );
		}
		if ( 429 === $status ) {
			return Result::failure( 'rate_limited', 'Too many requests.', true, $status );
		}
		if ( $status >= 500 ) {
			return Result::failure( 'server_error', 'WAHA server error.', true, $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			return Result::failure( 'http_error', 'Unexpected HTTP status.', false, $status );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			// Not retryable: the action may already have happened (avoid duplicate messages).
			return Result::failure( 'invalid_response', 'Response is not valid JSON.', false, $status );
		}
		return Result::success( $status, $decoded );
	}

	/**
	 * Logs failures without personal data (no phone, no text, no body).
	 *
	 * @param string $endpoint Endpoint label.
	 * @param Result $result   Result.
	 */
	private function log( string $endpoint, Result $result ): Result {
		if ( ! $result->ok ) {
			Logger::warning(
				'WAHA request failed.',
				array(
					'endpoint' => $endpoint,
					'code'     => $result->code,
					'status'   => $result->http_status,
				)
			);
		}
		return $result;
	}
}
