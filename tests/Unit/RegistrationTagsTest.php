<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;
use WP_User;

final class RegistrationTagsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();

		$user             = new WP_User();
		$user->ID         = 4;
		$user->user_email = 'new@example.com';

		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback = false ) => 'switchyard_settings' === $name
				? array( 'register_tags' => array( '10' ), 'async_sync' => false )
				: $fallback
		);
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->alias( fn ( int $id, string $key ) => $this->user_meta[ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->user_meta[ $key ] = $value;

				return true;
			}
		);
	}

	public function test_a_new_users_first_push_fills_the_email_even_with_no_field_map(): void {
		$crm   = new FakeCrm();
		$world = new World( $crm, $this->createMock( Logger::class ) );

		$world->users->push( 4 );

		$this->assertSame( 'new@example.com', $crm->added[0][1]['email'] );
	}

	public function test_tags_for_a_user_without_a_contact_create_the_contact_first(): void {
		$crm   = new FakeCrm();
		$world = new World( $crm, $this->createMock( Logger::class ) );

		$world->users->apply_tags_creating_contact( 4, array( '10' ) );

		$this->assertCount( 1, $crm->added );
		$this->assertSame( array( 'N1' => array( '10' ) ), $crm->applied );
	}
}
