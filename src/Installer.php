<?php

declare( strict_types=1 );

namespace Switchyard;

use Switchyard\Logger\LogTable;

final class Installer {

	public const DB_VERSION_OPTION = 'switchyard_db_version';
	public const DB_VERSION        = 2;

	public static function activate(): void {
		self::maybe_upgrade();

		if ( ! get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		if ( ! get_option( Webhook\Secret::OPTION ) ) {
			add_option( Webhook\Secret::OPTION, Webhook\Secret::generate() );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'switchyard_prune_logs' );
		wp_clear_scheduled_hook( 'switchyard_refresh_tags' );
	}

	/**
	 * Runs on activation and on every load where the stored schema version is
	 * behind, so an update deployed by file copy still lands its migrations.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		LogTable::create();

		if ( $installed > 0 ) {
			self::split_purchase_tags();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Product tags used to double as access tags and purchase tags. Copy the
	 * stored value to the purchase key so existing products keep tagging buyers.
	 */
	private static function split_purchase_tags(): void {
		global $wpdb;

		$types = array( 'product', 'product_variation', 'download' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				SELECT m.post_id, %s, m.meta_value
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = %s
				AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ")
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = m.post_id AND x.meta_key = %s
				)",
				array_merge( array( '_switchyard_purchase_tags', '_switchyard_tags' ), $types, array( '_switchyard_purchase_tags' ) )
			)
		);
	}
}
