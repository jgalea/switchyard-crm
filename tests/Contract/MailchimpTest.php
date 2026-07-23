<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\Mailchimp;

final class MailchimpTest extends ProviderContractTestCase {

	private const HASH = '4a3cd0b0b0dcb2e8b7d5b7ba0f0d0f0e';

	protected function fresh_provider(): Provider {
		return new Mailchimp( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'api_key'     => 'abc123-us7',
			'audience_id' => 'aud1',
		);
	}

	protected function expected_contact_id(): string {
		return 'member-id-1';
	}

	protected function sample_fields(): array {
		return array(
			'email_address' => 'person@example.com',
			'merge_FNAME'   => 'Ada',
		);
	}

	protected function fixtures(): array {
		return array(
			'/lists/aud1/members/' . self::HASH . '/tags' => array( 'body' => '{"tags":[{"id":1,"name":"Customer"}]}' ),
			'/members/member-id-1/tags'                    => array( 'body' => '{"tags":[{"id":1,"name":"Customer"}]}' ),
			'/lists/aud1/merge-fields'                     => array( 'body' => '{"merge_fields":[{"tag":"FNAME","name":"First Name","type":"text"}]}' ),
			'/lists/aud1/segments'                         => array( 'body' => '{"segments":[{"id":1,"name":"Customer"}]}' ),
			'/lists/aud1/members'                          => array( 'body' => '{"id":"member-id-1","email_address":"person@example.com","merge_fields":{"FNAME":"Ada"},"members":[{"id":"member-id-1"}]}' ),
			'GET https://us7.api.mailchimp.com/3.0/lists/aud1' => array( 'body' => '{"id":"aud1","name":"Main"}' ),
			'GET https://us7.api.mailchimp.com/3.0/lists'  => array( 'body' => '{"lists":[{"id":"aud1","name":"Main"}]}' ),
		);
	}

	/**
	 * The data centre is encoded in the key suffix, and getting it wrong
	 * sends every request to the wrong account.
	 */
	public function test_it_derives_the_data_centre_from_the_api_key(): void {
		$this->provider()->get_available_fields();

		$this->assertRequested( 'https://us7.api.mailchimp.com' );
	}

	public function test_it_falls_back_when_the_key_has_no_data_centre_suffix(): void {
		$provider = $this->fresh_provider();

		$provider->connect(
			array(
				'api_key'     => 'keywithoutsuffix',
				'audience_id' => 'aud1',
			)
		);

		$this->assertRequested( 'https://us1.api.mailchimp.com' );
	}

	public function test_it_addresses_members_by_an_md5_of_the_lowercased_email(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( 'Person@Example.com' );

		$expected = md5( 'person@example.com' );

		$this->assertRequested( '/lists/aud1/members/' . $expected );
	}

	/**
	 * Mailchimp addresses member tags by name, so a tag write must send the
	 * name and a status rather than an ID.
	 */
	public function test_it_writes_tags_by_name_with_a_status(): void {
		$this->provider()->apply_tags( 'member-id-1', array( 'Customer' ) );

		$last = $this->requests[ count( $this->requests ) - 1 ];

		$this->assertSame(
			array(
				array(
					'name'   => 'Customer',
					'status' => 'active',
				),
			),
			$last['body']['tags']
		);
	}

	public function test_removing_a_tag_sets_it_inactive_rather_than_deleting(): void {
		$this->provider()->remove_tags( 'member-id-1', array( 'Customer' ) );

		$last = $this->requests[ count( $this->requests ) - 1 ];

		$this->assertSame( 'inactive', $last['body']['tags'][0]['status'] );
	}
}
