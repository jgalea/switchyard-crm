<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\Brevo;

final class BrevoTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new Brevo( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array( 'api_key' => 'xkeysib-test' );
	}

	protected function expected_contact_id(): string {
		return '77';
	}

	protected function sample_fields(): array {
		return array(
			'email'      => 'person@example.com',
			'attr_PRENOM' => 'Ada',
		);
	}

	protected function fixtures(): array {
		return array(
			'GET https://api.brevo.com/v3/account'           => array( 'body' => '{"email":"owner@example.com"}' ),
			'GET https://api.brevo.com/v3/contacts/attributes' => array( 'body' => '{"attributes":[{"name":"PRENOM","type":"text"}]}' ),
			'https://api.brevo.com/v3/contacts/lists'        => array( 'body' => '{"lists":[{"id":4,"name":"Main"}]}' ),
			'POST https://api.brevo.com/v3/contacts'         => array( 'body' => '{"id":77}' ),
			'https://api.brevo.com/v3/contacts/77'           => array( 'body' => '{"id":77,"email":"person@example.com","attributes":{"PRENOM":"Ada"}}' ),
			'GET https://api.brevo.com/v3/contacts'          => array( 'body' => '{"contacts":[{"id":77,"email":"person@example.com"}]}' ),
		);
	}

	public function test_it_moves_mapped_values_into_attributes(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertSame( 'person@example.com', $body['email'] );
		$this->assertSame( array( 'PRENOM' => 'Ada' ), $body['attributes'] );
	}

	/**
	 * updateEnabled makes the create idempotent, which is what stops a double
	 * form submission producing a duplicate contact.
	 */
	public function test_creating_a_contact_is_idempotent(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertTrue( $body['updateEnabled'] );
	}

	public function test_list_membership_is_addressed_by_email(): void {
		$this->provider()->add_to_lists( 'person@example.com', array( '4' ) );

		$last = $this->requests[ count( $this->requests ) - 1 ];

		$this->assertStringContainsString( '/contacts/lists/4/contacts/add', $last['url'] );
		$this->assertSame( array( 'person@example.com' ), $last['body']['emails'] );
	}
}
