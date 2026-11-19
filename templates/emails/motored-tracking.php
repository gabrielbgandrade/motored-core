<?php
/**
 * Order tracking e-mail, HTML template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/motored-tracking.php.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name = trim( (string) $order->get_billing_first_name() );
?>

<p>
<?php
if ( '' !== $first_name ) {
	/* translators: %s: customer first name */
	printf( esc_html__( 'Hi %s!', 'motored-core' ), esc_html( $first_name ) );
} else {
	esc_html_e( 'Hi!', 'motored-core' );
}
?>
</p>

<p>
<?php
/* translators: %s: order number */
printf( esc_html__( 'You can now track your order #%s:', 'motored-core' ), esc_html( $order->get_order_number() ) );
?>
</p>

<p>
	<?php if ( '' !== $tracking['carrier'] ) : ?>
		<strong><?php esc_html_e( 'Carrier:', 'motored-core' ); ?></strong> <?php echo esc_html( $tracking['carrier'] ); ?><br>
	<?php endif; ?>
	<?php if ( '' !== $tracking['code'] ) : ?>
		<strong><?php esc_html_e( 'Tracking code:', 'motored-core' ); ?></strong> <?php echo esc_html( $tracking['code'] ); ?>
	<?php endif; ?>
</p>

<?php if ( '' !== $tracking['url'] ) : ?>
	<p style="text-align: center; margin: 30px 0;">
		<a href="<?php echo esc_url( $tracking['url'] ); ?>" style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
			<?php esc_html_e( 'Track shipment', 'motored-core' ); ?>
		</a>
	</p>
<?php endif; ?>

<?php if ( ! empty( $additional_content ) ) : ?>
	<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
