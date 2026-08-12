<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Contract;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Provider\Kit;

final class KitTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new Kit( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array( 'api_key' => 'kit-test-key' );
	}

	protected function expected_contact_id(): string {
		return '5501';
	}

	protected function sample_fields(): array {
		return array(
			'email_address' => 'person@example.com',
			'first_name'    => 'Ada',
			'field_company' => 'Acme',
		);
	}

	protected function fixtures(): array {
		return array(
			'GET https://api.kit.com/v4/account'          => array( 'body' => '{"account":{"name":"Test"}}' ),
			'/subscribers/5501/tags'                      => array( 'body' => '{"tags":[{"id":"31","name":"Customer"}]}' ),
			'GET https://api.kit.com/v4/subscribers/5501' => array( 'body' => '{"subscriber":{"id":5501,"email_address":"person@example.com","first_name":"Ada","fields":{"company":"Acme"}}}' ),
			'POST https://api.kit.com/v4/subscribers'     => array( 'body' => '{"subscriber":{"id":5501}}' ),
			'GET https://api.kit.com/v4/subscribers'      => array( 'body' => '{"subscribers":[{"id":5501}],"pagination":{"has_next_page":false,"end_cursor":null}}' ),
			'GET https://api.kit.com/v4/custom_fields'    => array( 'body' => '{"custom_fields":[{"key":"company","label":"Company"}]}' ),
			'GET https://api.kit.com/v4/tags'             => array( 'body' => '{"tags":[{"id":31,"name":"Customer"}],"pagination":{"has_next_page":false,"end_cursor":null}}' ),
			'POST https://api.kit.com/v4/tags'            => array( 'body' => '{"tag":{"id":32}}' ),
		);
	}

	public function test_it_nests_custom_fields_under_fields(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$body = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertSame( 'person@example.com', $body['email_address'] );
		$this->assertSame( array( 'company' => 'Acme' ), $body['fields'] );
		$this->assertArrayNotHasKey( 'field_company', $body );
	}

	/**
	 * Kit tags a subscriber through the tag resource, not the subscriber one.
	 */
	public function test_it_applies_a_tag_through_the_tag_endpoint(): void {
		$this->provider()->apply_tags( '5501', array( '31' ) );

		$this->assertRequested( 'POST https://api.kit.com/v4/tags/31/subscribers/5501' );
	}

	public function test_it_removes_a_tag_through_the_tag_endpoint(): void {
		$this->provider()->remove_tags( '5501', array( '31' ) );

		$this->assertRequested( 'DELETE https://api.kit.com/v4/tags/31/subscribers/5501' );
	}
}
