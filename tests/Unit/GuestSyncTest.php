<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionProperty;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\FieldMap;
use Switchyard\Sync\GuestSync;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\TestCase;

final class GuestSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_user_by' )->justReturn( false );
	}

	private function sync( FakeCrm $crm ): GuestSync {
		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$active   = new ReflectionProperty( $registry, 'active' );
		$active->setAccessible( true );
		$active->setValue( $registry, $crm );

		return new GuestSync( $registry, new ContactResolver( $registry, $logger ), new FieldMap(), $settings, $logger );
	}

	public function test_a_new_guest_contact_is_created_with_the_email_filled_in(): void {
		$crm = new FakeCrm();

		$this->sync( $crm )->push( 'guest@example.com', array( 'first_name' => 'Gus' ) );

		$this->assertCount( 1, $crm->added );
		$this->assertSame( 'guest@example.com', $crm->added[0][1]['email'] );
		$this->assertSame( 'Gus', $crm->added[0][1]['first_name'] );
	}

	public function test_a_guest_with_no_fields_at_all_still_gets_a_contact(): void {
		$crm = new FakeCrm();

		$this->sync( $crm )->push( 'guest@example.com', array(), array() );

		$this->assertSame( array( 'email' => 'guest@example.com' ), $crm->added[0][1] );
	}

	public function test_an_existing_contact_keeps_its_fields_when_updates_are_not_allowed(): void {
		$crm                                = new FakeCrm();
		$crm->contacts['alice@example.com'] = 'C1';

		$this->sync( $crm )->push( 'alice@example.com', array( 'first_name' => 'Mallory' ), array(), false );

		$this->assertSame( array(), $crm->records );
	}

	public function test_an_existing_contact_is_updated_when_updates_are_allowed(): void {
		$crm                                = new FakeCrm();
		$crm->contacts['alice@example.com'] = 'C1';

		$this->sync( $crm )->push( 'alice@example.com', array( 'first_name' => 'Alice' ) );

		$this->assertSame( array( 'first_name' => 'Alice' ), $crm->records['C1'] );
	}

	public function test_a_form_cannot_seed_a_registered_users_contact_or_tags(): void {
		$crm = new FakeCrm();
		$user     = new \WP_User();
		$user->ID = 7;
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'update_user_meta' )->alias(
			static function (): void {
				throw new \RuntimeException( 'linked a user' );
			}
		);

		$this->sync( $crm )->push( 'admin@example.com', array( 'first_name' => 'Pwned' ), array( 'vip' ), false );

		$this->assertSame( array( 'email' => 'admin@example.com' ), $crm->added[0][1] );
		$this->assertSame( array(), $crm->tags );
	}
}
