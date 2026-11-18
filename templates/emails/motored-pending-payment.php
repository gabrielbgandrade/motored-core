<?php
/**
 * Pending payment reminder, HTML template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/motored-pending-payment.php.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name = isset( $recovery['first_name'] ) && is_string( $recovery['first_name'] ) ? trim( $recovery['first_name'] ) : '';
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
printf( esc_html__( 'Your order #%s is still waiting for payment. These are the details:', 'motored-core' ), esc_html( $order->get_order_number() ) );
?>
</p>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; margin-bottom: 24px; border: 1px solid #e5e5e5;" border="1">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align: left;"><?php esc_html_e( 'Product', 'motored-core' ); ?></th>
			<th class="td" scope="col" style="text-align: center;"><?php esc_html_e( 'Quantity', 'motored-core' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td class="td" style="text-align: left;"><?php echo esc_html( $item['name'] ); ?></td>
				<td class="td" style="text-align: center;"><?php echo (int) $item['qty']; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p><strong><?php esc_html_e( 'Total:', 'motored-core' ); ?></strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>

<p style="text-align: center; margin: 30px 0;">
	<a href="<?php echo esc_url( $pay_url ); ?>" style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
		<?php esc_html_e( 'Complete the payment', 'motored-core' ); ?>
	</a>
</p>

<?php if ( ! empty( $additional_content ) ) : ?>
	<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
<?php endif; ?>

<p style="font-size: 11px; color: #777777; text-align: center; margin-top: 30px; border-top: 1px solid #eeeeee; padding-top: 12px;">
	<?php esc_html_e( 'If you do not want to receive more reminders about this order:', 'motored-core' ); ?>
	<a href="<?php echo esc_url( $optout_url ); ?>" style="color: #777777;"><?php esc_html_e( 'Unsubscribe', 'motored-core' ); ?></a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
