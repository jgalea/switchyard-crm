<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Tests\TestCase;

/**
 * Regression cover for the registry.
 *
 * The built-in provider list was once written with a relative class
 * reference that resolved under the wrong namespace, so every provider
 * silently failed to register while each one still passed its own tests.
 * These assertions run against the real list rather than a fixture.
 */
final class ProviderRegistryTest extends TestCase {

	private const EXPECTED = array(
		'activecampaign',
		'hubspot',
		'mailchimp',
		'klaviyo',
		'kit',
		'brevo',
		'fluentcrm',
		'mailerlite',
		'zoho',
		'salesforce',
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
	}

	private function registry(): ProviderRegistry {
		return new ProviderRegistry(
			new Client( $this->createMock( Logger::class ) ),
			new Settings(),
			$this->createMock( Logger::class )
		);
	}

	public function test_every_built_in_provider_registers(): void {
		$available = $this->registry()->available();

		foreach ( self::EXPECTED as $slug ) {
			$this->assertArrayHasKey(
				$slug,
				$available,
				sprintf( 'Provider "%s" did not register. Check the class reference in register_defaults().', $slug )
			);
		}
	}

	public function test_it_registers_the_documented_number_of_providers(): void {
		$this->assertCount(
			count( self::EXPECTED ),
			$this->registry()->available(),
			'The shipped provider count changed. Update the README and this list together.'
		);
	}

	public function test_every_registered_provider_can_be_instantiated(): void {
		$registry = $this->registry();

		foreach ( array_keys( $registry->available() ) as $slug ) {
			$provider = $registry->make( (string) $slug );

			$this->assertInstanceOf( Provider::class, $provider );
			$this->assertSame( $slug, $provider->get_slug() );
			$this->assertNotSame( '', $provider->get_name() );
		}
	}

	public function test_providers_are_listed_alphabetically_by_name(): void {
		$names  = array_values( $this->registry()->available() );
		$sorted = $names;
		sort( $sorted );

		$this->assertSame( $sorted, $names, 'The CRM dropdown should be alphabetical.' );
	}

	public function test_an_unknown_slug_is_not_reported_as_available(): void {
		$this->assertFalse( $this->registry()->has( 'not-a-crm' ) );
	}

	/**
	 * A misspelled or missing class must be skipped with a logged error
	 * rather than fatalling the whole plugin on load.
	 */
	public function test_registering_a_missing_class_is_survivable(): void {
		$registry = $this->registry();
		$before   = count( $registry->available() );

		/** @phpstan-ignore-next-line Deliberately invalid. */
		$registry->register( 'Switchyard\\Crm\\Provider\\DoesNotExist' );

		$this->assertCount( $before, $registry->available() );
	}

	public function test_no_provider_reports_a_duplicate_slug(): void {
		$slugs = array_keys( $this->registry()->available() );

		$this->assertSame( array_unique( $slugs ), $slugs );
	}
}
