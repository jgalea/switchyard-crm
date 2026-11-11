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
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\TestCase;
use Switchyard\Webhook\Listener;
use Switchyard\Webhook\Payload;
use WP_REST_Request;
use WP_User;

final class WebhookLinkingTest extends TestCase {

	/** @var array<string, mixed> */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_user_meta' )->alias( fn ( int $id, string $key ) => $this->user_meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->user_meta[ $id ][ $key ] = $value;

				return true;
			}
		);

		$alice             = new WP_User();
		$alice->ID         = 4;
		$alice->user_email = 'alice@example.com';

		Functions\when( 'get_user_by' )->alias( fn ( string $field, string $email ) => 'alice@example.com' === $email ? $alice : false );
		Functions\when( 'get_userdata' )->justReturn( $alice );
		Functions\when( 'get_users' )->justReturn( array() );
	}

	private function handle( FakeCrm $crm, Payload $payload ): \WP_REST_Response {
		$crm->payload = $payload;

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$active   = new ReflectionProperty( $registry, 'active' );
		$active->setAccessible( true );
		$active->setValue( $registry, $crm );

		$resolver = new ContactResolver( $registry, $logger );
		$tags     = new TagEngine( $registry, $resolver, $settings, $logger );
		$sync     = new UserSync( $registry, $resolver, new FieldMap(), $tags, new Queue( $settings, $logger ), $settings, $logger );
		$request  = new WP_REST_Request();

		$request->params['provider'] = 'fakecrm';

		return $this->listener( $registry, $resolver, $tags, $sync, $settings, $logger )->handle( $request );
	}

	public function test_a_body_contact_id_cannot_relink_a_user_to_another_contact(): void {
		$crm                                = new FakeCrm();
		$crm->contacts['alice@example.com'] = 'C1';
		$crm->tags                          = array(
			'C1' => array( 'basic' ),
			'C2' => array( 'vip' ),
		);

		$this->handle( $crm, new Payload( 'alice@example.com', 'C2' ) );

		$this->assertSame( 'C1', $this->user_meta[4]['switchyard_contact_id'] );
		$this->assertSame( array( 'basic' ), $this->user_meta[4]['switchyard_tags'] );
	}

	public function test_the_response_does_not_reveal_which_user_matched(): void {
		$crm                                = new FakeCrm();
		$crm->contacts['alice@example.com'] = 'C1';

		$response = $this->handle( $crm, new Payload( 'alice@example.com', 'C1' ) );

		$this->assertSame( array( 'message' => 'ok' ), $response->get_data() );
	}

	public function test_the_route_only_accepts_post(): void {
		$registered = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( string $ns, string $route, array $args ) use ( &$registered ): void {
				$registered = $args;
			}
		);

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$resolver = new ContactResolver( $registry, $logger );
		$tags     = new TagEngine( $registry, $resolver, $settings, $logger );
		$sync     = new UserSync( $registry, $resolver, new FieldMap(), $tags, new Queue( $settings, $logger ), $settings, $logger );

		$this->listener( $registry, $resolver, $tags, $sync, $settings, $logger )->register_routes();

		$this->assertSame( 'POST', $registered['methods'] );
	}

	private function listener( ProviderRegistry $registry, ContactResolver $resolver, TagEngine $tags, UserSync $sync, Settings $settings, Logger $logger ): Listener {
		return new Listener( $registry, $resolver, $tags, $sync, $settings, $logger );
	}
}
