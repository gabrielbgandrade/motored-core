<?php
/**
 * Stock alert plain text email (Avisame).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/motored-stock-alert.php.
 *
 * @package Motored\Core
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $bike
 * @var string $axle
 * @var string $catalog_url
 * @var string $cancel_url
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

esc_html_e( 'Hi!', 'motored-core' );
echo "\n\n";

if ( '' !== $axle ) {
	/* translators: 1: motorcycle, 2: axle (front, rear...). */
	printf( esc_html__( 'You asked us to let you know: we now have compatible tires for your %1$s (%2$s).', 'motored-core' ), esc_html( $bike ), esc_html( $axle ) );
} else {
	/* translators: %s: motorcycle. */
	printf( esc_html__( 'You asked us to let you know: we now have compatible tires for your %s.', 'motored-core' ), esc_html( $bike ) );
}
echo "\n\n";

echo esc_html__( 'View compatible tires', 'motored-core' ) . ': ' . esc_url_raw( $catalog_url ) . "\n\n";
esc_html_e( 'Check current stock and the load and speed ratings before buying.', 'motored-core' );
echo "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

if ( '' !== $cancel_url ) {
	echo esc_html__( 'Cancel this alert and delete my contact', 'motored-core' ) . ': ' . esc_url_raw( $cancel_url ) . "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo esc_html( wp_strip_all_tags( wptexturize( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
