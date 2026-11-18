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

// Persistent object cache entries expire by TTL; nothing else to remove in stage 1.
// Future modules: drop their tables and unschedule their Action Scheduler actions here.
