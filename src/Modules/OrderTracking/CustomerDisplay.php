<?php
/**
 * Customer-facing tracking block: order page and customer e-mails (core hooks only).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

defined( 'ABSPATH' ) || exit;

/**
 * Both callbacks read the order meta first (already loaded: 0 queries) and touch the option only when the order
 * has tracking and the module may show it.
 */
final class CustomerDisplay {

	/**
	 * Customer e-mails that carry the block (the dedicated e-mail has its own template).
	 */
	public const EMAIL_IDS = array( 'customer_processing_order', 'customer_completed_order' );

	/**
	 * Hook: woocommerce_order_details_after_order_table.
	 *
	 * @param mixed $order Order.
	 */
	public static function render_order_page( $order ): void {
		$data = self::data( $order );
		if ( null === $data ) {
			return;
		}
		echo '<section class="woocommerce-order-details motored-tracking">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Tracking', 'motored-core' ) . '</h2>';
		echo '<p>';
		if ( '' !== $data['carrier'] ) {
			echo '<strong>' . esc_html__( 'Carrier:', 'motored-core' ) . '</strong> ' . esc_html( $data['carrier'] ) . '<br>';
		}
		if ( '' !== $data['code'] ) {
			echo '<strong>' . esc_html__( 'Tracking code:', 'motored-core' ) . '</strong> ' . esc_html( $data['code'] );
		}
		echo '</p>';
		if ( '' !== $data['url'] ) {
			printf(
				'<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $data['url'] ),
				esc_html__( 'Track shipment', 'motored-core' )
			);
		}
		echo '</section>';
	}

	/**
	 * Hook: woocommerce_email_order_meta (customer processing / completed e-mails only).
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Whether the e-mail goes to the admin.
	 * @param mixed $plain_text    Plain text e-mail.
	 * @param mixed $email         E-mail object.
	 */
	public static function render_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! is_object( $email ) || ! isset( $email->id ) || ! in_array( $email->id, self::EMAIL_IDS, true ) ) {
			return;
		}
		$data = self::data( $order );
		if ( null === $data ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( mb_strtoupper( __( 'Tracking', 'motored-core' ) ) ) . "\n";
			if ( '' !== $data['carrier'] ) {
				echo esc_html( __( 'Carrier:', 'motored-core' ) . ' ' . $data['carrier'] ) . "\n";
			}
			if ( '' !== $data['code'] ) {
				echo esc_html( __( 'Tracking code:', 'motored-core' ) . ' ' . $data['code'] ) . "\n";
			}
			if ( '' !== $data['url'] ) {
				echo esc_url_raw( $data['url'] ) . "\n";
			}
		} else {
			echo '<h2>' . esc_html__( 'Tracking', 'motored-core' ) . '</h2><p>';
			if ( '' !== $data['carrier'] ) {
				echo '<strong>' . esc_html__( 'Carrier:', 'motored-core' ) . '</strong> ' . esc_html( $data['carrier'] ) . '<br>';
			}
			if ( '' !== $data['code'] ) {
				echo '<strong>' . esc_html__( 'Tracking code:', 'motored-core' ) . '</strong> ' . esc_html( $data['code'] ) . '<br>';
			}
			if ( '' !== $data['url'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $data['url'] ), esc_html__( 'Track shipment', 'motored-core' ) );
			}
			echo '</p>';
		}
		// The completed e-mail already carried the code: the dedicated e-mail is not sent for it.
		if ( 'customer_completed_order' === $email->id && $order instanceof \WC_Order ) {
			Tracking::mark_carried( $order, 'email' );
		}
	}

	/**
	 * Data to show, or null (no order, no tracking, or switched off).
	 *
	 * @param mixed $order Order.
	 * @return array{carrier:string,code:string,url:string}|null
	 */
	private static function data( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$tracking = Tracking::from_order( $order );
		if ( null === $tracking || ! Config::show_customer() ) {
			return null;
		}
		return Presenter::resolve( $tracking );
	}
}
