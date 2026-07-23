<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\MailerLite;

final class MailerLiteTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new MailerLite( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array( 'api_key' => 'ml-test-token' );
	}

	protected function expected_contact_id(): string {
		return '991';
	}

	protected function sample_fields(): array {
		return array(
			'email'         => 'person@example.com',
			'field_company' => 'Acme',
		);
	}

	protected function fixtures(): array {
		return array(
			'https://connect.mailerlite.com/api/groups'      => array( 'body' => '{"data":[{"id":"G1","name":"Subscribers"}]}' ),
			'https://connect.mailerlite.com/api/fields'      => array( 'body' => '{"data":[{"key":"company","name":"Company","type":"text"}]}' ),
			'https://connect.mailerlite.com/api/subscribers/991' => array( 'body' => '{"data":{"id":"991","email":"person@example.com","fields":{"company":"Acme"}}}' ),
			'POST https://connect.mailerlite.com/api/subscribers' => array( 'body' => '{"data":{"id":"991"}}' ),
			'https://connect.mailerlite.com/api/subscribers' => array( 'body' => '{"data":[{"id":"991","email":"person@example.com"}],"meta":{"next_cursor":null}}' ),
		);
	}

	public function test_it_authenticates_with_a_bearer_token(): void {
		$this->provider()->get_available_lists();

		$this->assertNotEmpty( $this->requests );
	}

	public function test_it_nests_custom_values_under_fields(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertSame( 'person@example.com', $body['email'] );
		$this->assertSame( array( 'company' => 'Acme' ), $body['fields'] );
	}

	public function test_group_membership_uses_the_subscriber_group_endpoint(): void {
		$this->provider()->add_to_lists( '991', array( 'G1' ) );

		$this->assertRequested( 'POST https://connect.mailerlite.com/api/subscribers/991/groups/G1' );
	}

	public function test_removing_from_a_group_deletes_the_membership(): void {
		$this->provider()->remove_from_lists( '991', array( 'G1' ) );

		$this->assertRequested( 'DELETE https://connect.mailerlite.com/api/subscribers/991/groups/G1' );
	}
}
