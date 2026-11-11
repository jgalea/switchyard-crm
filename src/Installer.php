<?php

declare( strict_types=1 );

namespace Switchyard;

use Switchyard\Admin\PostMetaBox;
use Switchyard\Logger\LogTable;

final class Installer {

	public const DB_VERSION_OPTION = 'switchyard_db_version';
	public const DB_VERSION        = 3;

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

		if ( $installed > 0 && $installed < 2 ) {
			self::split_purchase_tags();
		}

		if ( $installed > 0 && is_multisite() ) {
			self::scope_user_meta();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Contact IDs and tags used to share one unscoped key across a network. Copy
	 * them to this site's scoped keys so members keep their access. The option
	 * that gates this is per site, so each site runs it once, on its first load.
	 */
	private static function scope_user_meta(): void {
		global $wpdb;

		foreach ( array( Sync\ContactResolver::META_CONTACT_ID, Sync\TagEngine::META_TAGS, Sync\UserSync::META_LAST_SYNC ) as $base ) {
			$scoped = Sync\ContactResolver::meta_key( $base );

			do {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT m.user_id, m.meta_value FROM {$wpdb->usermeta} m
						WHERE m.meta_key = %s
						AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} x WHERE x.user_id = m.user_id AND x.meta_key = %s )
						LIMIT 500",
						$base,
						$scoped
					)
				);
				// phpcs:enable

				$copied = 0;

				foreach ( (array) $rows as $row ) {
					$copied += add_user_meta( (int) $row->user_id, $scoped, maybe_unserialize( $row->meta_value ), true ) ? 1 : 0;
				}
			} while ( $copied > 0 );
		}
	}

	/**
	 * Product tags used to double as access tags and purchase tags. Copy the
	 * stored value to the purchase key so existing products keep tagging buyers.
	 */
	private static function split_purchase_tags(): void {
		global $wpdb;

		$types        = array( 'product', 'product_variation', 'download' );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$from         = "FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = %s
			AND p.post_type IN ({$placeholders})
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = m.post_id AND x.meta_key = %s
			)";
		$args         = array_merge( array( '_switchyard_tags' ), $types, array( PostMetaBox::META_PURCHASE_TAGS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id {$from}", $args ) );

		if ( array() === $ids ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT m.post_id, %s, m.meta_value {$from}",
				array_merge( array( PostMetaBox::META_PURCHASE_TAGS ), $args )
			)
		);
		// phpcs:enable

		// The insert bypassed the meta API, so a persistent object cache still holds the old rows.
		foreach ( $ids as $id ) {
			wp_cache_delete( (int) $id, 'post_meta' );
		}
	}
}
