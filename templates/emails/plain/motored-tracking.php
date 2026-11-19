<?php
/**
 * Order tracking e-mail, plain text template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/motored-tracking.php.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

/* translators: %s: order number */
echo esc_html( sprintf( __( 'You can now track your order #%s:', 'motored-core' ), $order->get_order_number() ) ) . "\n\n";

if ( '' !== $tracking['carrier'] ) {
	echo esc_html( __( 'Carrier:', 'motored-core' ) . ' ' . $tracking['carrier'] ) . "\n";
}
if ( '' !== $tracking['code'] ) {
	echo esc_html( __( 'Tracking code:', 'motored-core' ) . ' ' . $tracking['code'] ) . "\n";
}
if ( '' !== $tracking['url'] ) {
	echo esc_url_raw( $tracking['url'] ) . "\n";
}

if ( ! empty( $additional_content ) ) {
	echo "\n----------\n\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n\n" . wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
