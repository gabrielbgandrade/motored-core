<?php
/**
 * Uninstall routine. Removes data only when "Delete data on uninstall" is enabled.
 *
 * @package Motored\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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

// Pending WhatsApp jobs (Action Scheduler; guarded, see docs/VERIFY.md).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'motored_waha_send', array(), 'motored-core' );
}

// Recovery: cleanup job, scanner job, and tables.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'motored_recovery_cleanup', array(), 'motored-core' );
	as_unschedule_all_actions( 'motored_recovery_scan', array(), 'motored-core' );
}
foreach ( array( 'motored_recoveries', 'motored_recovery_events', 'motored_suppressions' ) as $motored_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off uninstall cleanup; fixed table names.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$motored_table}" );
}

// Persistent object cache entries expire by TTL. Future modules: drop their tables here.
