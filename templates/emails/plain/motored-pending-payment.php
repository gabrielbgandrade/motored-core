<?php
/**
 * Pending payment reminder, plain text template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/motored-pending-payment.php.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( $email_heading ) . " =\n\n";

$first_name = isset( $recovery['first_name'] ) && is_string( $recovery['first_name'] ) ? trim( $recovery['first_name'] ) : '';
if ( '' !== $first_name ) {
	/* translators: %s: customer first name */
	printf( esc_html__( 'Hi %s!', 'motored-core' ), esc_html( $first_name ) );
	echo "\n\n";
} else {
	esc_html_e( 'Hi!', 'motored-core' );
	echo "\n\n";
}

/* translators: %s: order number */
printf( esc_html__( 'Your order #%s is still waiting for payment:', 'motored-core' ), esc_html( $order->get_order_number() ) );
echo "\n\n";

foreach ( $items as $item ) {
	echo '- ' . (int) $item['qty'] . 'x ' . esc_html( $item['name'] ) . "\n";
}

echo "\n" . esc_html__( 'Complete the payment here:', 'motored-core' ) . "\n";
echo esc_url( $pay_url ) . "\n\n";

if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html__( 'To stop receiving reminders:', 'motored-core' ) . ' ' . esc_url( $optout_url ) . "\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo esc_html( wp_strip_all_tags( wptexturize( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
