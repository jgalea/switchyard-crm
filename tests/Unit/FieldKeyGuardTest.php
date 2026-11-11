<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Access\Shortcodes;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\FieldMap;
use Switchyard\Sync\TagEngine;
use Switchyard\Tests\TestCase;
use WP_User;

final class FieldKeyGuardTest extends TestCase {

	/** @var array<string, mixed> */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback = false ) => FieldMap::OPTION === $name
				? array(
					'wp_capabilities' => array( 'crm_field' => 'Roles', 'type' => 'text', 'active' => true ),
					'first_name'      => array( 'crm_field' => 'FNAME', 'type' => 'text', 'active' => true ),
				)
				: $fallback
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->written[ $key ] = $value;

				return true;
			}
		);
	}

	public function test_a_mapped_capabilities_key_is_never_written_back_to_the_user(): void {
		( new FieldMap() )->to_wordpress( 4, array( 'Roles' => 'a:1:{s:13:"administrator";b:1;}', 'FNAME' => 'Ann' ) );

		$this->assertSame( array( 'first_name' => 'Ann' ), $this->written );
	}

	public function test_the_field_shortcode_only_reads_offered_fields(): void {
		$user             = new WP_User();
		$user->ID         = 4;
		$user->user_email = 'a@example.com';

		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'shortcode_atts' )->alias( static fn ( array $defaults, array $atts ): array => array_merge( $defaults, $atts ) );
		Functions\when( 'get_user_meta' )->alias( static fn ( int $id, string $key ) => 'meta:' . $key );

		$logger    = $this->createMock( Logger::class );
		$settings  = new Settings();
		$registry  = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$tags      = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );
		$shortcode = new Shortcodes( $tags, new FieldMap() );

		$this->assertSame( 'meta:first_name', $shortcode->render_field( array( 'key' => 'first_name' ) ) );
		$this->assertSame( 'fallback', $shortcode->render_field( array( 'key' => 'wp_user_level', 'default' => 'fallback' ) ) );
	}
}
