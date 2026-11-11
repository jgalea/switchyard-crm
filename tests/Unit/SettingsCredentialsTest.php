<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Settings;
use Switchyard\Tests\TestCase;

final class SettingsCredentialsTest extends TestCase {

	/** @var array<string, array{0: mixed, 1: mixed}> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array();

		Functions\when( 'get_option' )->alias( fn ( string $name, $fallback = false ) => $this->options[ $name ][0] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->options[ $name ] = array( $value, $autoload );

				return true;
			}
		);
	}

	public function test_credentials_are_stored_outside_the_autoloaded_settings_option(): void {
		$settings = new Settings();

		$settings->merge(
			array(
				'provider'    => 'kit',
				'credentials' => array( 'api_key' => 'abc' ),
			)
		);

		$this->assertArrayNotHasKey( 'credentials', $this->options[ Settings::OPTION ][0] );
		$this->assertSame( array( 'api_key' => 'abc' ), $this->options[ Settings::CREDENTIALS_OPTION ][0] );
		$this->assertFalse( $this->options[ Settings::CREDENTIALS_OPTION ][1] );
	}

	public function test_credentials_saved_by_an_older_version_are_moved_on_first_read(): void {
		$this->options[ Settings::OPTION ] = array(
			array(
				'provider'    => 'kit',
				'credentials' => array( 'api_key' => 'abc' ),
			),
			null,
		);

		$settings = new Settings();

		$this->assertSame( array( 'api_key' => 'abc' ), $settings->credentials() );
		$this->assertArrayNotHasKey( 'credentials', $this->options[ Settings::OPTION ][0] );
		$this->assertSame( array( 'api_key' => 'abc' ), $this->options[ Settings::CREDENTIALS_OPTION ][0] );
	}
}
