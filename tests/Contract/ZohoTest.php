<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\Zoho;

final class ZohoTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new Zoho( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'client_id'     => 'zoho-client',
			'client_secret' => 'zoho-secret',
			'refresh_token' => 'zoho-refresh',
			'data_centre'   => 'eu',
		);
	}

	protected function expected_contact_id(): string {
		return 'z100';
	}

	protected function sample_fields(): array {
		return array(
			'Email'      => 'person@example.com',
			'First_Name' => 'Ada',
			'Last_Name'  => 'Lovelace',
		);
	}

	protected function fixtures(): array {
		return array(
			// Matches any data centre, so the fallback test can reach it too.
			'oauth/v2/token'                          => array( 'body' => '{"access_token":"tok-123","expires_in":3600}' ),
			'/crm/v8/settings/modules'                 => array( 'body' => '{"modules":[{"api_name":"Contacts"}]}' ),
			'/crm/v8/settings/fields'                  => array( 'body' => '{"fields":[{"api_name":"Email","field_label":"Email","data_type":"email","read_only":false},{"api_name":"Last_Name","field_label":"Last Name","data_type":"text","read_only":false}]}' ),
			'/crm/v8/settings/tags'                    => array( 'body' => '{"tags":[{"id":"t1","name":"Customer"}]}' ),
			'/crm/v8/Contacts/search'                  => array( 'body' => '{"data":[{"id":"z100"}]}' ),
			'/crm/v8/Contacts/z100/actions/add_tags'   => array( 'body' => '{"data":[{"code":"SUCCESS"}]}' ),
			'/crm/v8/Contacts/z100/actions/remove_tags' => array( 'body' => '{"data":[{"code":"SUCCESS"}]}' ),
			'GET https://www.zohoapis.eu/crm/v8/Contacts/z100' => array( 'body' => '{"data":[{"id":"z100","Email":"person@example.com","Last_Name":"Lovelace","Tag":[{"id":"t1","name":"Customer"}]}]}' ),
			'POST https://www.zohoapis.eu/crm/v8/Contacts' => array( 'body' => '{"data":[{"details":{"id":"z100"},"code":"SUCCESS"}]}' ),
			'GET https://www.zohoapis.eu/crm/v8/Contacts' => array( 'body' => '{"data":[{"id":"z100"}],"info":{"more_records":false}}' ),
		);
	}

	/**
	 * Zoho is region-partitioned; sending EU traffic to the .com host fails
	 * with an error that reads like a permissions problem.
	 */
	public function test_it_honours_the_configured_data_centre(): void {
		$this->provider()->get_contact_id( 'person@example.com' );

		$this->assertRequested( 'https://www.zohoapis.eu/crm/v8' );
		$this->assertRequested( 'https://accounts.zoho.eu/oauth/v2/token' );
	}

	public function test_it_falls_back_to_com_for_an_unknown_data_centre(): void {
		$provider = $this->fresh_provider();

		$provider->connect( array_merge( $this->credentials(), array( 'data_centre' => 'nonsense' ) ) );

		$this->assertRequested( 'https://accounts.zoho.com/oauth/v2/token' );
	}

	public function test_it_supplies_a_last_name_because_zoho_requires_one(): void {
		$this->provider()->add_contact( array( 'Email' => 'person@example.com' ) );

		$create = null;

		foreach ( $this->requests as $request ) {
			if ( 'POST' === $request['method'] && str_ends_with( $request['url'], '/crm/v8/Contacts' ) ) {
				$create = $request['body'];
			}
		}

		$this->assertNotNull( $create );
		$this->assertSame( 'person', $create['data'][0]['Last_Name'] );
	}

	public function test_it_reads_tags_off_the_record(): void {
		$this->assertSame( array( 't1' ), $this->provider()->get_tags( 'z100' ) );
	}

	/**
	 * Zoho's tag actions take names, so IDs have to be resolved first.
	 */
	public function test_it_applies_tags_by_name_not_id(): void {
		$this->provider()->apply_tags( 'z100', array( 't1' ) );

		$last = $this->requests[ count( $this->requests ) - 1 ];

		$this->assertStringContainsString( 'actions/add_tags', $last['url'] );
		$this->assertSame( array( array( 'name' => 'Customer' ) ), $last['body']['tags'] );
	}

	public function test_it_reuses_a_cached_access_token(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( 'a@example.com' );
		$provider->get_contact_id( 'b@example.com' );

		$token_calls = 0;

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], 'oauth/v2/token' ) ) {
				++$token_calls;
			}
		}

		$this->assertSame( 0, $token_calls, 'A cached token should not be refreshed on every call.' );
	}
}
