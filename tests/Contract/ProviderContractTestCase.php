<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Contract;

use Brain\Monkey\Functions;
use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Contracts\SupportsContactImport;
use Switchyard\Crm\Contracts\SupportsLists;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Contracts\SupportsTracking;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Http\Client;
use Switchyard\Logger\Logger;
use Switchyard\Tests\TestCase;

/**
 * The contract every CRM provider passes.
 *
 * This is the mechanism that keeps the roadmap to sixty CRMs tractable:
 * adding a provider means writing an adapter and a fixture map, then this
 * suite proves it behaves like every other one. A provider that only works
 * by accident fails here rather than in a customer's support ticket.
 */
abstract class ProviderContractTestCase extends TestCase {

	/** @var list<array{method: string, url: string, body: mixed, headers: array<string, string>}> */
	protected array $requests = array();

	/**
	 * The provider under test, already connected.
	 */
	abstract protected function provider(): Provider;

	/**
	 * Credentials that should be accepted.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function credentials(): array;

	/**
	 * Fixture responses, keyed by a substring matched against "METHOD url".
	 *
	 * The first matching key wins, so put specific patterns before general
	 * ones.
	 *
	 * @return array<string, array{status?: int, body: string, headers?: array<string, string>}>
	 */
	abstract protected function fixtures(): array;

	/**
	 * The contact ID the fixtures return when a contact is created.
	 */
	abstract protected function expected_contact_id(): string;

	/**
	 * A field payload valid for this CRM.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function sample_fields(): array;

	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();

		$this->install_transport( $this->fixtures() );
	}

	/**
	 * @param array<string, array{status?: int, body: string, headers?: array<string, string>}> $fixtures
	 */
	protected function install_transport( array $fixtures ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) use ( $fixtures ) {
				$method = (string) ( $args['method'] ?? 'GET' );
				$body   = isset( $args['body'] ) && is_string( $args['body'] )
					? json_decode( $args['body'], true )
					: ( $args['body'] ?? null );

				$this->requests[] = array(
					'method'  => $method,
					'url'     => $url,
					'body'    => $body,
					'headers' => $args['headers'] ?? array(),
				);

				$signature = $method . ' ' . $url;

				foreach ( $fixtures as $pattern => $response ) {
					if ( str_contains( $signature, $pattern ) ) {
						return array(
							'response' => array( 'code' => $response['status'] ?? 200 ),
							'body'     => $response['body'],
							'headers'  => $response['headers'] ?? array(),
						);
					}
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => $r['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( $r ) => $r['headers'] ?? array() );
	}

	protected function client(): Client {
		return new Client( $this->createMock( Logger::class ) );
	}

	/**
	 * Whether a request matching this signature substring was made.
	 */
	protected function assertRequested( string $needle, string $message = '' ): void {
		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['method'] . ' ' . $request['url'], $needle ) ) {
				$this->addToAssertionCount( 1 );

				return;
			}
		}

		$this->fail(
			'' !== $message
				? $message
				: sprintf(
					'No request matched "%s". Requests made: %s',
					$needle,
					implode( ', ', array_map( static fn( $r ) => $r['method'] . ' ' . $r['url'], $this->requests ) )
				)
		);
	}

	// -- Identity -------------------------------------------------------

	public function test_it_declares_a_slug_and_a_name(): void {
		$provider = $this->provider();

		$this->assertNotSame( '', $provider->get_slug() );
		$this->assertMatchesRegularExpression(
			'/^[a-z0-9\-]+$/',
			$provider->get_slug(),
			'Slugs appear in URLs and option keys, so they must be URL-safe.'
		);
		$this->assertNotSame( '', $provider->get_name() );
	}

	public function test_its_credential_fields_are_well_formed(): void {
		foreach ( $this->provider()->get_credential_fields() as $key => $field ) {
			$this->assertIsString( $key );
			$this->assertArrayHasKey( 'label', $field, sprintf( 'Credential "%s" needs a label.', $key ) );
			$this->assertArrayHasKey( 'type', $field, sprintf( 'Credential "%s" needs a type.', $key ) );
			$this->assertNotSame( '', $field['label'] );
			$this->assertContains(
				$field['type'],
				array( 'text', 'password', 'url', 'checkbox', 'select' ),
				sprintf( 'Credential "%s" has an unrenderable type.', $key )
			);
		}
	}

	// -- Contacts -------------------------------------------------------

	public function test_it_returns_a_contact_id_when_creating_a_contact(): void {
		$id = $this->provider()->add_contact( $this->sample_fields() );

		$this->assertSame( $this->expected_contact_id(), $id );
	}

	public function test_it_exposes_mappable_fields(): void {
		$fields = $this->provider()->get_available_fields();

		$this->assertNotEmpty( $fields, 'A provider with no mappable fields cannot sync anything.' );

		foreach ( $fields as $key => $field ) {
			$this->assertIsString( $key );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'type', $field );
		}
	}

	public function test_updating_a_contact_does_not_throw(): void {
		$this->provider()->update_contact( $this->expected_contact_id(), $this->sample_fields() );

		$this->assertNotEmpty( $this->requests, 'An update should reach the API.' );
	}

	public function test_loading_a_contact_returns_an_array(): void {
		$this->assertIsArray( $this->provider()->load_contact( $this->expected_contact_id() ) );
	}

	// -- Capabilities ---------------------------------------------------

	/**
	 * A provider must be usable for access control one way or the other,
	 * otherwise the site can connect it and then silently gate nothing.
	 */
	public function test_it_supports_either_tags_or_lists(): void {
		$provider = $this->provider();

		$this->assertTrue(
			$provider instanceof SupportsTags || $provider instanceof SupportsLists,
			'A provider must implement SupportsTags or SupportsLists so access control has something to read.'
		);
	}

	public function test_tag_capability_is_coherent(): void {
		$provider = $this->provider();

		if ( ! $provider instanceof SupportsTags ) {
			$this->markTestSkipped( 'This CRM has no tag primitive.' );
		}

		$tags = $provider->get_available_tags();

		$this->assertIsArray( $tags );

		foreach ( $tags as $id => $label ) {
			$this->assertIsString( (string) $id );
			$this->assertIsString( $label );
		}

		$this->assertIsArray( $provider->get_tags( $this->expected_contact_id() ) );
	}

	public function test_list_capability_is_coherent(): void {
		$provider = $this->provider();

		if ( ! $provider instanceof SupportsLists ) {
			$this->markTestSkipped( 'This CRM has no list primitive.' );
		}

		$this->assertIsArray( $provider->get_available_lists() );
	}

	public function test_tracking_capability_returns_strings(): void {
		$provider = $this->provider();

		if ( ! $provider instanceof SupportsTracking ) {
			$this->markTestSkipped( 'This CRM has no tracking script.' );
		}

		$this->assertIsString( $provider->get_tracking_src() );
		$this->assertIsString( $provider->get_tracking_inline() );
		$this->assertIsString( $provider->get_identify_inline( 'person@example.com', '1' ) );
		$this->assertStringNotContainsString( '<script', $provider->get_tracking_inline() );
		$this->assertStringNotContainsString( '<script', $provider->get_identify_inline( 'person@example.com', '1' ) );
	}

	public function test_contact_import_returns_the_documented_shape(): void {
		$provider = $this->provider();

		if ( ! $provider instanceof SupportsContactImport ) {
			$this->markTestSkipped( 'This CRM cannot enumerate contacts.' );
		}

		$page = $provider->load_contact_ids();

		$this->assertArrayHasKey( 'ids', $page );
		$this->assertArrayHasKey( 'cursor', $page );
		$this->assertIsArray( $page['ids'] );
	}

	// -- Failure behaviour ----------------------------------------------

	/**
	 * Rejected credentials must surface as ConnectionException, because that
	 * is the only exception the settings screen and the registry catch.
	 */
	public function test_rejected_credentials_raise_a_connection_exception(): void {
		$this->install_transport(
			array(
				'' => array(
					'status' => 401,
					'body'   => '{"message":"unauthorized"}',
				),
			)
		);

		$provider = $this->fresh_provider();

		$this->expectException( ConnectionException::class );

		$provider->connect( $this->credentials() );
	}

	/**
	 * A provider instance that has not yet been connected.
	 */
	abstract protected function fresh_provider(): Provider;
}
