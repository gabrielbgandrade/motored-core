<?php
/**
 * Abandoned Cart HTML email template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/motored-abandoned-cart.php.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
$first_name = isset( $recovery['first_name'] ) && is_string( $recovery['first_name'] ) && '' !== trim( $recovery['first_name'] )
	? trim( $recovery['first_name'] )
	: '';
if ( '' !== $first_name ) {
	/* translators: %s: customer first name */
	printf( esc_html__( 'Hi %s!', 'motored-core' ), esc_html( $first_name ) );
} else {
	esc_html_e( 'Hi!', 'motored-core' );
}
?>
</p>

<p><?php esc_html_e( 'We noticed you left some products in your cart. To make your purchase easier, we saved the details:', 'motored-core' ); ?></p>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; margin-bottom: 24px; border: 1px solid #e5e5e5;" border="1">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align: left;"><?php esc_html_e( 'Product', 'motored-core' ); ?></th>
			<th class="td" scope="col" style="text-align: center;"><?php esc_html_e( 'Quantity', 'motored-core' ); ?></th>
			<th class="td" scope="col" style="text-align: right;"><?php esc_html_e( 'Price', 'motored-core' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td class="td" style="text-align: left; vertical-align: middle;">
					<?php echo esc_html( $item['name'] ); ?>
				</td>
				<td class="td" style="text-align: center; vertical-align: middle;">
					<?php echo (int) $item['qty']; ?>
				</td>
				<td class="td" style="text-align: right; vertical-align: middle;">
					<?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $item['line_total'] ) : (string) $item['line_total'] ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p style="text-align: center; margin: 30px 0;">
	<a href="<?php echo esc_url( $recovery_url ); ?>" style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
		<?php esc_html_e( 'Recover my cart', 'motored-core' ); ?>
	</a>
</p>

<?php if ( ! empty( $additional_content ) ) : ?>
	<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
<?php endif; ?>

<p style="font-size: 11px; color: #777777; text-align: center; margin-top: 30px; border-top: 1px solid #eeeeee; padding-top: 12px;">
	<?php esc_html_e( 'If you do not want to receive more reminders about this cart:', 'motored-core' ); ?>
	<a href="<?php echo esc_url( $optout_url ); ?>" style="color: #777777;"><?php esc_html_e( 'Unsubscribe', 'motored-core' ); ?></a>
</p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
