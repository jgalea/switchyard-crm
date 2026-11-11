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

$switchyard_clean_site = static function (): void {
	global $wpdb;

	$options = array(
		'switchyard_settings',
		'switchyard_credentials',
		'switchyard_field_map',
		'switchyard_webhook_secret',
		'switchyard_db_version',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Per-integration settings.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'switchyard_integration_%'" );

	// Cached catalogues, circuit-breaker state and OAuth tokens.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_switchyard_%' OR option_name LIKE '_transient_timeout_switchyard_%'" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_switchyard\_%'" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'switchyard\_%'" );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}switchyard_log" );

	// Queued jobs hold guest emails and submitted form values in their arguments.
	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) === $actions ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE l FROM {$logs} l INNER JOIN {$actions} a ON a.action_id = l.action_id INNER JOIN {$groups} g ON g.group_id = a.group_id WHERE g.slug = 'switchyard'" );
		$wpdb->query( "DELETE a FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id WHERE g.slug = 'switchyard'" );
		// phpcs:enable
	}

	wp_clear_scheduled_hook( 'switchyard_prune_logs' );
	wp_clear_scheduled_hook( 'switchyard_refresh_tags' );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $switchyard_blog_id ) {
		switch_to_blog( (int) $switchyard_blog_id );
		$switchyard_clean_site();
		restore_current_blog();
	}
} else {
	$switchyard_clean_site();
}

// User meta is shared by every site on a network, so it is cleared once. The
// per-site keys carry a blog ID suffix on multisite.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'switchyard\_contact\_id%' OR meta_key LIKE 'switchyard\_tags%' OR meta_key LIKE 'switchyard\_last\_sync%' OR meta_key LIKE 'switchyard\_wc\_%' OR meta_key LIKE 'switchyard\_edd\_%'" );
