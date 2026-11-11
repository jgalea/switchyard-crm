<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Crm\Http\Client;
use Switchyard\Installer;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Tests\TestCase;

final class MultisiteScopeTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	private int $blog = 1;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->alias( fn (): int => $this->blog );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_user_meta' )->alias( fn ( int $id, string $key ) => $this->user_meta[ $id ][ $key ] ?? '' );
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'add_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->user_meta[ $id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->user_meta[ $id ][ $key ] = $value;

				return true;
			}
		);
	}

	public function test_contact_ids_and_tags_cached_on_one_site_are_invisible_on_another(): void {
		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$resolver = new ContactResolver( $registry, $logger );
		$tags     = new TagEngine( $registry, $resolver, $settings, $logger );

		$this->blog = 1;
		$resolver->store_id( 4, 'ACCOUNT-A-CONTACT' );
		$tags->set_cache( 4, array( '12' ) );

		$this->blog = 2;

		$this->assertNull( $resolver->cached_id( 4 ) );
		$this->assertFalse( $tags->has_any_tag( 4, array( '12' ) ) );

		$this->blog = 1;

		$this->assertSame( 'ACCOUNT-A-CONTACT', $resolver->cached_id( 4 ) );
		$this->assertTrue( $tags->has_any_tag( 4, array( '12' ) ) );
	}

	public function test_the_upgrade_copies_unscoped_meta_to_the_current_sites_keys_once(): void {
		$this->user_meta = array(
			4 => array(
				'switchyard_contact_id' => 'C9',
				'switchyard_tags'       => array( '12' ),
			),
			5 => array(
				'switchyard_contact_id'   => 'C5',
				'switchyard_contact_id_2' => 'KEEP',
			),
		);
		$this->blog      = 2;

		$meta = &$this->user_meta;

		$GLOBALS['wpdb'] = new class( $meta ) {
			public string $usermeta = 'wp_usermeta';

			/** @var array<int, array<string, mixed>> */
			private array $meta;

			/** @param array<int, array<string, mixed>> $meta */
			public function __construct( array &$meta ) {
				$this->meta = &$meta;
			}

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $args[0] . '|' . $args[1];
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				[ $base, $scoped ] = explode( '|', $query );
				$rows              = array();

				foreach ( $this->meta as $id => $row ) {
					if ( isset( $row[ $base ] ) && ! isset( $row[ $scoped ] ) ) {
						$rows[] = (object) array(
							'user_id'    => $id,
							'meta_value' => $row[ $base ],
						);
					}
				}

				return $rows;
			}
		};

		$method = new \ReflectionMethod( Installer::class, 'scope_user_meta' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertSame( 'C9', $this->user_meta[4]['switchyard_contact_id_2'] );
		$this->assertSame( array( '12' ), $this->user_meta[4]['switchyard_tags_2'] );
		$this->assertSame( 'KEEP', $this->user_meta[5]['switchyard_contact_id_2'] );

		unset( $GLOBALS['wpdb'] );
	}
}
