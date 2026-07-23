<?php

declare( strict_types=1 );

namespace Conduit\Logger;

final class LogTable {

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'conduit_log';
	}

	public static function create(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(60) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY user_id (user_id)
		) {$collate};";

		dbDelta( $sql );
	}

	public static function drop(): void {
		global $wpdb;

		$table = self::name();

		// Table name is built from $wpdb->prefix; identifiers cannot be bound.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
