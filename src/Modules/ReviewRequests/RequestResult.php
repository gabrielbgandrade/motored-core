<?php
/**
 * Outcome of a review request: private order note and meta, no phone or text.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\WooCommerce\OrderHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Listener of `motored_waha_result`.
 */
final class RequestResult {

	/**
	 * Listener.
	 *
	 * @param string      $kind   Job kind.
	 * @param int         $ref_id Order ID.
	 * @param string|null $step   null or 'manual'.
	 * @param Result      $result Outcome.
	 */
	public static function on_result( string $kind, int $ref_id, ?string $step, Result $result ): void {
		if ( Scheduler::KIND !== $kind || $ref_id <= 0 || ( ! $result->ok && 'skipped' === $result->code ) ) {
			return;
		}
		OrderHooks::add_private_note( $ref_id, self::note( $result->ok, RequestHandler::MANUAL === $step ) );
		if ( $result->ok ) {
			OrderHooks::set_meta( $ref_id, Scheduler::META, 'sent' );
		}
	}

	/**
	 * Private note text.
	 *
	 * @param bool $ok     Whether it was sent.
	 * @param bool $manual Whether an administrator asked for it.
	 */
	public static function note( bool $ok, bool $manual ): string {
		$source = $manual ? __( 'manual', 'motored-core' ) : __( 'automatic', 'motored-core' );
		return $ok
			/* translators: %s: automatic or manual. */
			? sprintf( __( 'Motored: review request sent by WhatsApp (%s).', 'motored-core' ), $source )
			/* translators: %s: automatic or manual. */
			: sprintf( __( 'Motored: review request by WhatsApp could not be sent (%s).', 'motored-core' ), $source );
	}
}
