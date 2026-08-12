<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Access\ContentGate;
use Switchyard\Access\MenuFilter;
use Switchyard\Access\QueryFilter;
use Switchyard\Access\Rules;
use Switchyard\Access\Shortcodes;
use Switchyard\Admin\AdminPages;
use Switchyard\Admin\PostMetaBox;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Features;
use Switchyard\Integration\Registry as IntegrationRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Plugin;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\FieldMap;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;
use Switchyard\Tests\TestCase;
use Switchyard\Tracking\SiteTracking;
use Switchyard\Webhook\Listener;

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
