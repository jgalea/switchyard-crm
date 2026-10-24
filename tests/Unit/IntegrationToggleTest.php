<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Switchyard\Container;
use Switchyard\Integration\Integration;
use Switchyard\Integration\Registry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Tests\TestCase;

final class IntegrationToggleTest extends TestCase {

	/** @var list<string> */
	private array $disabled = array();

	private int $registered = 0;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			fn (): array => array( 'disabled_integrations' => $this->disabled )
		);
		Functions\when( 'do_action' )->justReturn();
	}

	private function integration(): Integration {
		$integration = $this->createMock( Integration::class );
		$integration->method( 'is_available' )->willReturn( true );
		$integration->method( 'get_name' )->willReturn( 'Example' );
		$integration->method( 'register_hooks' )->willReturnCallback(
			function (): void {
				++$this->registered;
			}
		);

		return $integration;
	}

	private function registry(): Registry {
		$registry = new Registry( new Container(), $this->createMock( Logger::class ), new Settings() );
		$registry->add( 'example', fn () => $this->integration() );

		return $registry;
	}

	public function test_integration_loads_by_default(): void {
		$registry = $this->registry();

		$registry->boot();

		$this->assertSame( 1, $this->registered );
		$this->assertTrue( $registry->is_loaded( 'example' ) );
	}

	public function test_integration_switched_off_in_settings_does_not_load(): void {
		$this->disabled = array( 'example' );
		$registry       = $this->registry();

		$registry->boot();

		$this->assertSame( 0, $this->registered );
		$this->assertFalse( $registry->is_loaded( 'example' ) );
		$this->assertFalse( $registry->status()['example']['enabled'] );
		$this->assertFalse( $registry->status()['example']['locked'] );
	}

	public function test_filter_overrides_the_setting(): void {
		$this->disabled = array( 'example' );
		Filters\expectApplied( 'switchyard_enable_integration' )->andReturn( true );

		$registry = $this->registry();

		$registry->boot();

		$this->assertSame( 1, $this->registered );
		$status = $registry->status()['example'];
		$this->assertTrue( $status['enabled'] );
		$this->assertTrue( $status['locked'] );
	}

	public function test_filter_can_switch_off_an_enabled_integration(): void {
		Filters\expectApplied( 'switchyard_enable_integration' )->andReturn( false );

		$registry = $this->registry();

		$registry->boot();

		$this->assertSame( 0, $this->registered );
		$this->assertTrue( $registry->status()['example']['locked'] );
	}
}
