<?php
/**
 * Removes everything Conduit CRM stored.
 *
 * Contact IDs and cached tags go too: leaving them behind would let a
 * reinstall silently reuse stale mappings against a different CRM account.
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$options = array(
	'conduit_settings',
	'conduit_field_map',
	'conduit_webhook_secret',
	'conduit_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Per-integration settings.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'conduit_integration_%'" );

// Cached catalogues, circuit-breaker state and OAuth tokens.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_conduit_%' OR option_name LIKE '_transient_timeout_conduit_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('conduit_contact_id','conduit_tags','conduit_last_sync')" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_conduit\_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'conduit\_%'" );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}conduit_log" );

wp_clear_scheduled_hook( 'conduit_prune_logs' );
wp_clear_scheduled_hook( 'conduit_refresh_tags' );
