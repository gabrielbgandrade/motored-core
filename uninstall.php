<?php
/**
 * Uninstall routine. Scheduled jobs are always removed; data only when "Delete data on uninstall" is enabled.
 *
 * @package Motored\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Jobs are not data: always remove them, or Action Scheduler keeps running hooks with no listener.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'motored_waha_send', 'motored_recovery_cleanup', 'motored_recovery_scan', 'motored_plans_reconcile' ) as $motored_hook ) {
		as_unschedule_all_actions( $motored_hook, array(), 'motored-core' );
	}
}

// Deleting plugins already needs `delete_plugins` (administrators); stay defensive when a user is logged in (WP-CLI has none).
if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! current_user_can( 'delete_plugins' ) ) {
	return;
}

$motored_general = get_option( 'motored_general', array() );

if ( ! is_array( $motored_general ) || ! isset( $motored_general['delete_data_on_uninstall'] ) || 'yes' !== $motored_general['delete_data_on_uninstall'] ) {
	return;
}

global $wpdb;

// Options (motored_*) and transients used by the Cache fallback (motored_<md5>).
$motored_patterns = array(
	$wpdb->esc_like( 'motored_' ) . '%',
	$wpdb->esc_like( '_transient_motored_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_motored_' ) . '%',
);

foreach ( $motored_patterns as $motored_pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $motored_pattern ) );
}

// Settings of our WooCommerce e-mails (stored by WC_Settings_API).
delete_option( 'woocommerce_motored_abandoned_cart_settings' );
delete_option( 'woocommerce_motored_pending_payment_settings' );

// Recovery tables.
foreach ( array( 'motored_recoveries', 'motored_recovery_events', 'motored_suppressions' ) as $motored_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off uninstall cleanup; fixed table names.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$motored_table}" );
}

// Persistent object cache entries expire by TTL. Order meta (_motored_*) is kept on purpose: it is part of the order history.
