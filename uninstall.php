<?php
/**
 * Removes everything Switchyard CRM stored.
 *
 * Contact IDs and cached tags go too: leaving them behind would let a
 * reinstall silently reuse stale mappings against a different CRM account.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$switchyard_options = array(
	'switchyard_settings',
	'switchyard_field_map',
	'switchyard_webhook_secret',
	'switchyard_db_version',
);

foreach ( $switchyard_options as $switchyard_option ) {
	delete_option( $switchyard_option );
}

// Per-integration settings.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'switchyard_integration_%'" );

// Cached catalogues, circuit-breaker state and OAuth tokens.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_switchyard_%' OR option_name LIKE '_transient_timeout_switchyard_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'switchyard\_contact\_id%' OR meta_key LIKE 'switchyard\_tags%' OR meta_key LIKE 'switchyard\_last\_sync%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_switchyard\_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'switchyard\_%'" );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}switchyard_log" );

wp_clear_scheduled_hook( 'switchyard_prune_logs' );
wp_clear_scheduled_hook( 'switchyard_refresh_tags' );
