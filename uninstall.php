<?php
/**
 * Uninstall routine. Scheduled jobs are always removed; data only when "Delete data on uninstall" is enabled.
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Deleting plugins already needs `delete_plugins` (administrators); stay defensive when a user is logged in (WP-CLI has none).
if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! current_user_can( 'delete_plugins' ) ) {
	return;
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'motored-core' );
}

$motored_general = get_option( 'motored_general', array() );

if ( ! is_array( $motored_general ) || ! isset( $motored_general['delete_data_on_uninstall'] ) || 'yes' !== $motored_general['delete_data_on_uninstall'] ) {
	return;
}

global $wpdb;

// Remove the stored credential even if the general option discovery query fails.
delete_option( 'motored_waha_secret' );

// Options (motored_*) and transients used by the Cache fallback (motored_<md5>).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Discover plugin-owned options for one-off removal.
$motored_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'motored_' ) . '%', $wpdb->esc_like( '_transient_motored_' ) . '%', $wpdb->esc_like( '_transient_timeout_motored_' ) . '%' ) );
foreach ( $motored_options as $motored_option ) {
	delete_option( $motored_option );
}

// Settings of our WooCommerce e-mails (stored by WC_Settings_API).
delete_option( 'woocommerce_motored_abandoned_cart_settings' );
delete_option( 'woocommerce_motored_pending_payment_settings' );
delete_option( 'woocommerce_motored_tracking_settings' );

// Recovery & Compatibility tables (WooCommerce pa_* attributes and terms are preserved on purpose).
foreach ( array(
	'motored_recoveries',
	'motored_recovery_events',
	'motored_suppressions',
	'motored_compat_brands',
	'motored_compat_models',
	'motored_compat_versions',
	'motored_compat_sizes',
	'motored_compat_fitments',
	'motored_compat_equivalences',
	'motored_compat_product_index',
	'motored_seo_redirects',
	'motored_bike_demand',
) as $motored_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off uninstall cleanup; fixed table names.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$motored_table}" );
}

if ( function_exists( 'delete_metadata' ) ) {
	delete_metadata( 'user', 0, '_motored_garage', '', true );
	delete_metadata( 'post', 0, '_motored_install_photos', '', true );
	delete_metadata( 'post', 0, '_motored_review_gallery', '', true );
	delete_metadata( 'post', 0, '_motored_img_opt', '', true );
}

// Persistent object cache entries expire by TTL. Order meta (_motored_*) is kept on purpose: it is part of the order history.
