<?php
/**
 * Outcome of one WAHA call.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\Waha;

use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result. `message` is a short English technical text (never a response body,
 * never personal data); user-facing wording is translated by the admin layer from `code`.
 * `message` and `data` pass through the central secret redaction (API key, X-Api-Key).
 */
final class Result {

	/**
	 * Whether the call succeeded.
	 *
	 * @var bool
	 */
	public readonly bool $ok;

	/**
	 * HTTP status (0 when there was no response).
	 *
	 * @var int
	 */
	public readonly int $http_status;

	/**
	 * Machine code ("ok", "timeout", "auth", ...).
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * Short technical message (redacted).
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Whether retrying later may succeed.
	 *
	 * @var bool
	 */
	public readonly bool $retryable;

	/**
	 * Parsed, whitelisted response fields (redacted: never carries the API key or headers).
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $data;

	/**
	 * Constructor.
	 *
	 * @param bool                $ok          Whether the call succeeded.
	 * @param int                 $http_status HTTP status (0 when there was no response).
	 * @param string              $code        Machine code ("ok", "timeout", "auth", ...).
	 * @param string              $message     Short technical message.
	 * @param bool                $retryable   Whether retrying later may succeed.
	 * @param array<string,mixed> $data        Parsed, whitelisted response fields.
	 */
	public function __construct( bool $ok, int $http_status, string $code, string $message, bool $retryable, array $data = array() ) {
		$clean             = Logger::redact( $data );
		$this->ok          = $ok;
		$this->http_status = $http_status;
		$this->code        = $code;
		$this->message     = Logger::redact_string( $message );
		$this->retryable   = $retryable;
		$this->data        = is_array( $clean ) ? $clean : array();
	}

	/**
	 * Successful result.
	 *
	 * @param int                 $http_status HTTP status.
	 * @param array<string,mixed> $data        Parsed fields.
	 */
	public static function success( int $http_status, array $data = array() ): self {
		return new self( true, $http_status, 'ok', 'OK', false, $data );
	}

	/**
	 * Failed result.
	 *
	 * @param string $code        Machine code.
	 * @param string $message     Short technical message.
	 * @param bool   $retryable   Whether a retry may help.
	 * @param int    $http_status HTTP status, 0 when none.
	 */
	public static function failure( string $code, string $message, bool $retryable = false, int $http_status = 0 ): self {
		return new self( false, $http_status, $code, $message, $retryable );
	}
}
