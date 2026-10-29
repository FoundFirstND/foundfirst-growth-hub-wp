<?php
/**
 * Runs when the plugin is deleted from the Plugins screen (not on deactivation).
 * Removes the queue table, every ffgh_ option and transient, and the cron events.
 *
 * @package FoundFirst\GrowthHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'ffgh_process_queue' );
wp_clear_scheduled_hook( 'ffgh_queue_sweep' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ffgh_lead_queue" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'ffgh_' ) . '%',
		$wpdb->esc_like( '_transient_ffgh_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ffgh_' ) . '%'
	)
);
// phpcs:enable

wp_cache_flush();
