<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Crm\Http\Client;
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
}
