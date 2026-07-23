<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\Klaviyo;

final class KlaviyoTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new Klaviyo( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'api_key'    => 'pk_test',
			'public_key' => 'ABC123',
		);
	}

	protected function expected_contact_id(): string {
		return '01HPROFILE';
	}

	protected function sample_fields(): array {
		return array(
			'email'      => 'person@example.com',
			'first_name' => 'Ada',
			'prop_plan'  => 'gold',
		);
	}

	protected function fixtures(): array {
		return array(
			'GET https://a.klaviyo.com/api/accounts/'      => array( 'body' => '{"data":[{"id":"acct"}]}' ),
			'https://a.klaviyo.com/api/profiles/01HPROFILE' => array( 'body' => '{"data":{"id":"01HPROFILE","attributes":{"email":"person@example.com","first_name":"Ada","properties":{"plan":"gold"}}}}' ),
			'POST https://a.klaviyo.com/api/profiles/'     => array( 'body' => '{"data":{"id":"01HPROFILE"}}' ),
			'GET https://a.klaviyo.com/api/profiles/'      => array( 'body' => '{"data":[{"id":"01HPROFILE"}]}' ),
			'https://a.klaviyo.com/api/lists/'             => array( 'body' => '{"data":[{"id":"L1","attributes":{"name":"Newsletter"}}],"links":{"next":null}}' ),
			'POST https://a.klaviyo.com/api/events/'       => array( 'body' => '{}' ),
		);
	}

	/**
	 * Klaviyo rejects any request without a dated revision header.
	 */
	public function test_it_sends_the_revision_header_on_every_request(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( 'person@example.com' );
		$provider->add_contact( $this->sample_fields() );

		$this->assertNotEmpty( $this->requests );

		foreach ( $this->requests as $request ) {
			$this->assertArrayHasKey(
				'revision',
				$request['headers'],
				sprintf( 'Klaviyo rejects %s %s without a revision header.', $request['method'], $request['url'] )
			);

			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2}$/',
				(string) $request['headers']['revision'],
				'The revision must be a dated string.'
			);
		}
	}

	public function test_it_authenticates_with_the_klaviyo_api_key_scheme(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( 'person@example.com' );

		$this->assertStringStartsWith(
			'Klaviyo-API-Key ',
			(string) $this->requests[0]['headers']['Authorization'],
			'Klaviyo uses its own scheme, not Bearer.'
		);
	}

	public function test_it_wraps_writes_in_the_json_api_envelope(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertSame( 'profile', $body['data']['type'] );
		$this->assertSame( 'person@example.com', $body['data']['attributes']['email'] );
		$this->assertSame( array( 'plan' => 'gold' ), $body['data']['attributes']['properties'] );
	}

	public function test_adding_to_a_list_uses_the_relationships_endpoint(): void {
		$this->provider()->add_to_lists( '01HPROFILE', array( 'L1' ) );

		$this->assertRequested( 'POST https://a.klaviyo.com/api/lists/L1/relationships/profiles/' );
	}

	public function test_it_tracks_an_event_against_a_metric(): void {
		$this->provider()->track_event( '01HPROFILE', 'Course Completed', array( 'course' => 'Intro' ) );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertSame( 'Course Completed', $body['data']['attributes']['metric']['data']['attributes']['name'] );
		$this->assertSame( array( 'course' => 'Intro' ), $body['data']['attributes']['properties'] );
	}
}
