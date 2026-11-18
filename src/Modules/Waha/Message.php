<?php
/**
 * Message built by a module handler at send time.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

defined( 'ABSPATH' ) || exit;

/**
 * Phone (any human format) + plain text. Lives only in memory during the send; never persisted
 * by the Sender and never logged.
 */
final class Message {

	/**
	 * Constructor.
	 *
	 * @param string $phone Recipient in any common format (normalized by Phone).
	 * @param string $text  Plain text body.
	 */
	public function __construct(
		public readonly string $phone,
		public readonly string $text
	) {
	}
}
