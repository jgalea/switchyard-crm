<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Tests\TestCase;

final class UninstallTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	public function test_uninstall_removes_store_meta_and_queued_jobs(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'switchyard-crm/switchyard-crm.php' );
		}

		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $options    = 'wp_options';
			public string $postmeta   = 'wp_postmeta';
			public string $termmeta   = 'wp_termmeta';
			public string $usermeta   = 'wp_usermeta';

			/** @var list<string> */
			public array $queries = array();

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return str_replace( '%s', (string) $args[0], $query );
			}

			public function get_var( string $query ): string {
				return 'wp_actionscheduler_actions';
			}

			public function query( string $query ): int {
				$this->queries[] = $query;

				return 0;
			}
		};

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );

		$this->assertStringContainsString( "switchyard\\_wc\\_%", $sql );
		$this->assertStringContainsString( 'wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g', $sql );
		$this->assertStringContainsString( "g.slug = 'switchyard'", $sql );
	}
}
