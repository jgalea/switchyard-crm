<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\Provider\Mailchimp;
use Switchyard\Logger\Logger;
use Switchyard\Tests\TestCase;
use ReflectionClass;

/**
 * Credentials must only ever leave for a public HTTPS host.
 *
 * Base URLs are built from stored credentials, and some providers interpolate
 * part of a credential into the hostname. Mailchimp reads its data centre from
 * the API key suffix, so a key ending in "evil.example/x" produced a base URL
 * whose host was evil.example, sending the Basic auth header, and therefore
 * the key itself, to an attacker.
 */
final class DestinationGuardTest extends TestCase {

	private function guard( string $url ): void {
		$client = new Client( $this->createMock( Logger::class ) );

		$method = ( new ReflectionClass( Client::class ) )->getMethod( 'guard_destination' );
		$method->setAccessible( true );
		$method->invoke( $client, $url );
	}

	private function dataCentreFor( string $key ): string {
		$provider = new Mailchimp( new Client( $this->createMock( Logger::class ) ) );

		$reflection = new ReflectionClass( Mailchimp::class );

		$credentials = $reflection->getProperty( 'credentials' );
		$credentials->setAccessible( true );
		$credentials->setValue( $provider, array( 'api_key' => $key ) );

		$method = $reflection->getMethod( 'data_centre' );
		$method->setAccessible( true );

		return (string) $method->invoke( $provider );
	}

	// -- Mailchimp host injection ---------------------------------------

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function apiKeys(): array {
		return array(
			'normal key'          => array( 'abc123def456-us7', 'us7' ),
			'two digit centre'    => array( 'abc123-us21', 'us21' ),
			'no suffix'           => array( 'keywithoutsuffix', 'us1' ),
			'host injection'      => array( 'abc-evil.example/x', 'us1' ),
			'metadata address'    => array( 'abc-169.254.169.254/x', 'us1' ),
			'path traversal'      => array( 'abc-..', 'us1' ),
			'uppercase'           => array( 'abc-EVIL', 'us1' ),
			'empty suffix'        => array( 'abc-', 'us1' ),
		);
	}

	/**
	 * @dataProvider apiKeys
	 */
	public function test_the_data_centre_cannot_carry_a_hostname( string $key, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->dataCentreFor( $key ),
			'A crafted API key must not be able to steer the request host.'
		);
	}

	// -- Destination guard ----------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function publicHosts(): array {
		return array(
			'mailchimp'      => array( 'https://us7.api.mailchimp.com/3.0/lists' ),
			'activecampaign' => array( 'https://acme.api-us1.com/api/3/contacts' ),
			'salesforce'     => array( 'https://acme.my.salesforce.com/services/data' ),
		);
	}

	/**
	 * @dataProvider publicHosts
	 */
	public function test_it_allows_public_https_destinations( string $url ): void {
		$this->guard( $url );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blockedHosts(): array {
		return array(
			'loopback name'    => array( 'https://localhost/api' ),
			'loopback v4'      => array( 'https://127.0.0.1/api' ),
			'loopback v6'      => array( 'https://[::1]/api' ),
			'link local'       => array( 'https://169.254.169.254/latest/meta-data' ),
			'private 10'       => array( 'https://10.0.0.5/api' ),
			'private 192'      => array( 'https://192.168.1.10/api' ),
			'private 172'      => array( 'https://172.16.0.9/api' ),
			'mdns'             => array( 'https://printer.local/api' ),
		);
	}

	/**
	 * @dataProvider blockedHosts
	 */
	public function test_it_refuses_private_and_loopback_destinations( string $url ): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->expectException( ConnectionException::class );

		$this->guard( $url );
	}

	public function test_it_refuses_plaintext_transport(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->expectException( ConnectionException::class );

		$this->guard( 'http://api.example.com/v1' );
	}

	public function test_it_refuses_a_url_with_no_host(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->expectException( ConnectionException::class );

		$this->guard( '/relative/path' );
	}

	/**
	 * Self-hosted CRMs on an internal network are a real deployment, so the
	 * block has an explicit escape hatch rather than being absolute.
	 */
	public function test_an_internal_host_can_be_allowed_by_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'switchyard_allow_internal_host' === $hook ? true : $value
		);

		$this->guard( 'https://10.0.0.5/api' );

		$this->addToAssertionCount( 1 );
	}
}
