<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Contract;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Provider\ActiveCampaign;

final class ActiveCampaignTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new ActiveCampaign( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'api_url'     => 'https://acme.api-us1.com',
			'api_key'     => 'test-key',
			'tracking_id' => '12345',
			'event_key'   => 'event-key',
		);
	}

	protected function expected_contact_id(): string {
		return '42';
	}

	protected function sample_fields(): array {
		return array(
			'email'     => 'person@example.com',
			'firstName' => 'Ada',
			'field_3'   => 'Custom value',
		);
	}

	protected function fixtures(): array {
		// Ordered most specific first: the matcher takes the first hit.
		return array(
			'GET https://acme.api-us1.com/api/3/users/me'          => array( 'body' => '{"user":{"id":"1"}}' ),
			'/contacts/42/contactTags'                             => array( 'body' => '{"contactTags":[{"id":"900","tag":"7"}]}' ),
			'/contacts/42/fieldValues'                             => array( 'body' => '{"fieldValues":[{"field":"3","value":"Custom value"}]}' ),
			'GET https://acme.api-us1.com/api/3/contacts/42'        => array( 'body' => '{"contact":{"id":"42","email":"person@example.com","firstName":"Ada"}}' ),
			'POST https://acme.api-us1.com/api/3/contact/sync'      => array( 'body' => '{"contact":{"id":"42"}}' ),
			'GET https://acme.api-us1.com/api/3/contacts'           => array( 'body' => '{"contacts":[{"id":"42"}]}' ),
			'GET https://acme.api-us1.com/api/3/fields'             => array( 'body' => '{"fields":[{"id":"3","title":"Company","type":"text"}]}' ),
			'GET https://acme.api-us1.com/api/3/tags'               => array( 'body' => '{"tags":[{"id":"7","tag":"Customer"},{"id":"8","tag":"VIP"}]}' ),
			'GET https://acme.api-us1.com/api/3/lists'              => array( 'body' => '{"lists":[{"id":"1","name":"Main"}]}' ),
			'POST https://acme.api-us1.com/api/3/contactTags'       => array( 'body' => '{"contactTag":{"id":"901"}}' ),
			'POST https://acme.api-us1.com/api/3/tags'              => array( 'body' => '{"tag":{"id":"9"}}' ),
		);
	}

	// -- Provider-specific behaviour ------------------------------------

	public function test_it_sends_custom_fields_as_field_values(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$sync = null;

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], '/contact/sync' ) ) {
				$sync = $request['body'];
			}
		}

		$this->assertNotNull( $sync );
		$this->assertSame( 'person@example.com', $sync['contact']['email'] );
		$this->assertSame(
			array(
				array(
					'field' => '3',
					'value' => 'Custom value',
				),
			),
			$sync['contact']['fieldValues'],
			'Custom fields must move into fieldValues, not stay at the top level.'
		);
	}

	public function test_it_reads_tag_ids_rather_than_join_ids(): void {
		$provider = $this->provider();

		$this->assertSame( array( '7' ), $provider->get_tags( '42' ) );
	}

	/**
	 * Removal needs the join-record ID, which is a different number from the
	 * tag ID and is the easiest thing to get wrong here.
	 */
	public function test_removing_a_tag_deletes_the_join_record(): void {
		$this->provider()->remove_tags( '42', array( '7' ) );

		$this->assertRequested(
			'DELETE https://acme.api-us1.com/api/3/contactTags/900',
			'Removal should delete contactTag 900, not tag 7.'
		);
	}

	public function test_removing_a_tag_the_contact_lacks_makes_no_request(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->remove_tags( '42', array( '999' ) );

		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'DELETE', $request['method'] );
		}
	}
}
