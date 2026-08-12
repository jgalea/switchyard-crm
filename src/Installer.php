<?php

declare( strict_types=1 );

namespace Switchyard;

use Switchyard\Logger\LogTable;

final class Installer {

	public const DB_VERSION_OPTION = 'switchyard_db_version';
	public const DB_VERSION        = 1;

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

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
