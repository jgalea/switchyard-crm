<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;
use WP_User;

final class UserImportTest extends TestCase {

	public function test_import_matches_the_contacts_own_email_and_not_a_custom_field(): void {
		$alice             = new WP_User();
		$alice->ID         = 4;
		$alice->user_email = 'alice@example.com';

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $alice );
		Functions\when( 'get_user_by' )->alias( static fn ( string $field, string $email ) => 'alice@example.com' === $email ? $alice : false );

		$crm                     = new FakeCrm();
		$crm->records['C9']      = array(
			'field_referrer' => 'someone-else@example.com',
			'email'          => 'alice@example.com',
		);
		$world                   = new World( $crm, $this->createMock( Logger::class ) );

		$this->assertSame( 4, $world->users->import_contact( 'C9' ) );
	}
}
