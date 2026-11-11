<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Logger\Logger;
use Switchyard\Privacy;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;
use WP_User;

final class PrivacyTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();

		$user             = new WP_User();
		$user->ID         = 4;
		$user->user_email = 'alice@example.com';

		$this->meta = array(
			4 => array(
				'switchyard_contact_id'     => 'C1',
				'switchyard_tags'           => array( '10' ),
				'switchyard_last_sync'      => 1700000000,
				'switchyard_wc_total_spent' => '55.00',
			),
		);

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->alias( fn ( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $id, string $key ): bool {
				$had = isset( $this->meta[ $id ][ $key ] );
				unset( $this->meta[ $id ][ $key ] );

				return $had;
			}
		);

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			/** @var list<string> */
			public array $queries = array();

			public function esc_like( string $text ): string {
				return $text;
			}

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function get_results( string $query ): array {
				return array();
			}

			public function get_var( string $query ): ?string {
				return null;
			}

			public function query( string $query ): int {
				$this->queries[] = $query;

				return 2;
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function privacy(): Privacy {
		$world = new World( new FakeCrm(), $this->createMock( Logger::class ) );

		return new Privacy( $world->resolver, $world->tags );
	}

	public function test_the_export_lists_the_contact_id_tags_and_sync_time(): void {
		$export = $this->privacy()->export( 'alice@example.com' );
		$names  = array_column( $export['data'][0]['data'], 'name' );

		$this->assertContains( 'CRM contact ID', $names );
		$this->assertContains( 'CRM tags', $names );
		$this->assertContains( 'switchyard_last_sync', $names );
		$this->assertContains( 'switchyard_wc_total_spent', $names );
	}

	public function test_the_eraser_clears_user_meta_and_log_rows(): void {
		$result = $this->privacy()->erase( 'alice@example.com' );

		$this->assertSame( array(), $this->meta[4] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertStringContainsString( "DELETE FROM", $GLOBALS['wpdb']->queries[0] );
	}
}
