<?php
/**
 * Private order note written for every recovery send.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\OrderHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves a PRIVATE note in the WooCommerce order (channel, source, result). Never the phone number,
 * the e-mail address or the message text.
 */
final class OrderNote {

	/**
	 * Writes the note when the recovery row belongs to an order.
	 *
	 * @param int    $recovery_id Recovery row ID.
	 * @param string $channel     "whatsapp" or "email".
	 * @param string $source      "auto" or "manual".
	 * @param bool   $ok          Whether the send succeeded.
	 */
	public static function record( int $recovery_id, string $channel, string $source, bool $ok ): void {
		$row = Repository::get( $recovery_id );
		if ( null === $row || 'order' !== (string) $row['type'] || (int) $row['order_id'] <= 0 ) {
			return;
		}
		OrderHooks::add_private_note( (int) $row['order_id'], self::text( $channel, $source, $ok ) );
	}

	/**
	 * Note text (pure apart from translation).
	 *
	 * @param string $channel "whatsapp" or "email".
	 * @param string $source  "auto" or "manual".
	 * @param bool   $ok      Whether the send succeeded.
	 */
	public static function text( string $channel, string $source, bool $ok ): string {
		$channel_label = 'whatsapp' === $channel ? 'WhatsApp' : __( 'e-mail', 'motored-core' );
		$source_label  = 'manual' === $source ? __( 'manual', 'motored-core' ) : __( 'automatic', 'motored-core' );
		return $ok
			/* translators: 1: channel (WhatsApp or e-mail), 2: automatic or manual. */
			? sprintf( __( 'Motored: payment reminder sent by %1$s (%2$s).', 'motored-core' ), $channel_label, $source_label )
			/* translators: 1: channel (WhatsApp or e-mail), 2: automatic or manual. */
			: sprintf( __( 'Motored: payment reminder by %1$s could not be sent (%2$s).', 'motored-core' ), $channel_label, $source_label );
	}
}
