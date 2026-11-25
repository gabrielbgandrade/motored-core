<?php
/**
 * Stock alert HTML email (Avisame).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/motored-stock-alert.php.
 *
 * @package Motored\Core
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $bike
 * @var string $axle
 * @var string $catalog_url
 * @var string $cancel_url
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'Hi!', 'motored-core' ); ?></p>

<p>
<?php
if ( '' !== $axle ) {
	/* translators: 1: motorcycle, 2: axle (front, rear...). */
	printf( esc_html__( 'You asked us to let you know: we now have compatible tires for your %1$s (%2$s).', 'motored-core' ), '<strong>' . esc_html( $bike ) . '</strong>', esc_html( $axle ) );
} else {
	/* translators: %s: motorcycle. */
	printf( esc_html__( 'You asked us to let you know: we now have compatible tires for your %s.', 'motored-core' ), '<strong>' . esc_html( $bike ) . '</strong>' );
}
?>
</p>

<p><a class="button" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'View compatible tires', 'motored-core' ); ?></a></p>

<p><?php esc_html_e( 'Check current stock and the load and speed ratings before buying.', 'motored-core' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

if ( '' !== $cancel_url ) :
	?>
	<p><small>
		<?php esc_html_e( 'This was a one-time alert.', 'motored-core' ); ?>
		<a href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel this alert and delete my contact', 'motored-core' ); ?></a>
	</small></p>
	<?php
endif;

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
