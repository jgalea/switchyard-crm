<?php

declare( strict_types=1 );

namespace Conduit\Tests\Unit;

use Brain\Monkey\Functions;
use Conduit\Access\ContentGate;
use Conduit\Access\MenuFilter;
use Conduit\Access\QueryFilter;
use Conduit\Access\Rules;
use Conduit\Access\Shortcodes;
use Conduit\Admin\AdminPages;
use Conduit\Admin\PostMetaBox;
use Conduit\Crm\Http\Client;
use Conduit\Crm\ProviderRegistry;
use Conduit\Features;
use Conduit\Integration\Registry as IntegrationRegistry;
use Conduit\Logger\Logger;
use Conduit\Plugin;
use Conduit\Settings;
use Conduit\Sync\ContactResolver;
use Conduit\Sync\FieldMap;
use Conduit\Sync\GuestSync;
use Conduit\Sync\Queue;
use Conduit\Sync\TagEngine;
use Conduit\Sync\UserSync;
use Conduit\Tests\TestCase;
use Conduit\Tracking\SiteTracking;
use Conduit\Webhook\Listener;

/**
 * Every service must actually construct.
 *
 * A container is only as good as the wiring, and a factory with the wrong
 * argument list stays invisible until the moment a real request needs that
 * service. Resolving all of them here turns that into a test failure.
 */
final class ContainerWiringTest extends TestCase {

	private const SERVICES = array(
		Settings::class,
		Logger::class,
		Client::class,
		Features::class,
		ProviderRegistry::class,
		ContactResolver::class,
		FieldMap::class,
		TagEngine::class,
		Queue::class,
		UserSync::class,
		GuestSync::class,
		Rules::class,
		ContentGate::class,
		QueryFilter::class,
		MenuFilter::class,
		Shortcodes::class,
		Listener::class,
		SiteTracking::class,
		IntegrationRegistry::class,
		AdminPages::class,
		PostMetaBox::class,
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
	}

	public function test_every_registered_service_resolves(): void {
		$container = Plugin::instance()->container();

		foreach ( self::SERVICES as $service ) {
			$this->assertTrue(
				$container->has( $service ),
				sprintf( '%s is not registered in the container.', $service )
			);

			$resolved = $container->get( $service );

			$this->assertInstanceOf(
				$service,
				$resolved,
				sprintf( '%s resolved to the wrong type.', $service )
			);
		}
	}

	public function test_services_are_shared_not_rebuilt(): void {
		$container = Plugin::instance()->container();

		$this->assertSame(
			$container->get( Settings::class ),
			$container->get( Settings::class ),
			'Resolving twice must return the same instance, or caches diverge.'
		);
	}

	public function test_an_unregistered_service_fails_loudly(): void {
		$this->expectException( \RuntimeException::class );

		Plugin::instance()->container()->get( 'NotAService' );
	}
}
