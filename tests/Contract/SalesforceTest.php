<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Provider\Salesforce;

final class SalesforceTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new Salesforce( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'client_id'     => 'sf-key',
			'client_secret' => 'sf-secret',
			'refresh_token' => 'sf-refresh',
			'instance_url'  => 'https://acme.my.salesforce.com',
			'tag_field'     => 'Conduit_Tags__c',
		);
	}

	protected function expected_contact_id(): string {
		return '003xx000004TmiQ';
	}

	protected function sample_fields(): array {
		return array(
			'Email'     => 'person@example.com',
			'FirstName' => 'Ada',
			'LastName'  => 'Lovelace',
		);
	}

	protected function fixtures(): array {
		return array(
			'login.salesforce.com/services/oauth2/token' => array( 'body' => '{"access_token":"sf-token","expires_in":7200}' ),
			'/sobjects/Contact/describe'                 => array( 'body' => '{"fields":[{"name":"Email","label":"Email","type":"email","updateable":true},{"name":"LastName","label":"Last Name","type":"string","updateable":true},{"name":"Conduit_Tags__c","label":"Tags","type":"multipicklist","updateable":true,"picklistValues":[{"value":"VIP","label":"VIP","active":true},{"value":"Customer","label":"Customer","active":true}]}]}' ),
			'/query'                                     => array( 'body' => '{"records":[{"Id":"003xx000004TmiQ","Conduit_Tags__c":"VIP;Customer"}],"nextRecordsUrl":null}' ),
			'GET https://acme.my.salesforce.com/services/data/' => array( 'body' => '[{"version":"62.0","label":"Winter 26"}]' ),
			'/sobjects/Contact/003xx000004TmiQ'          => array( 'body' => '{"Id":"003xx000004TmiQ","Email":"person@example.com","attributes":{"type":"Contact"}}' ),
			'POST https://acme.my.salesforce.com/services/data/v62.0/sobjects/Contact' => array( 'body' => '{"id":"003xx000004TmiQ","success":true}' ),
		);
	}

	public function test_it_discovers_the_api_version_from_the_org(): void {
		$this->provider()->get_available_fields();

		$this->assertRequested( 'GET https://acme.my.salesforce.com/services/data/' );
		$this->assertRequested( '/services/data/v62.0/sobjects/Contact/describe' );
	}

	/**
	 * SOQL has no parameter binding over REST, so a quote in an email must
	 * not be able to terminate the literal.
	 */
	public function test_it_escapes_soql_literals(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( "o'brien@example.com" );

		$query = null;

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], '/query' ) ) {
				$query = urldecode( $request['url'] );
			}
		}

		$this->assertNotNull( $query );
		$this->assertStringContainsString( "\\'brien", $query, 'The quote must be escaped.' );
	}

	public function test_it_escapes_backslashes_before_quotes(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->get_contact_id( 'a\\\'b@example.com' );

		$query = null;

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], '/query' ) ) {
				$query = urldecode( $request['url'] );
			}
		}

		$this->assertNotNull( $query );
		$this->assertStringNotContainsString( "a\\'b", $query, 'A backslash must not escape our own escape.' );
	}

	/**
	 * Tags live in a multi-select picklist stored as a delimited string.
	 */
	public function test_it_reads_tags_from_the_picklist_field(): void {
		$this->assertSame( array( 'VIP', 'Customer' ), $this->provider()->get_tags( '003xx000004TmiQ' ) );
	}

	public function test_available_tags_come_from_the_picklist_values(): void {
		$this->assertSame(
			array(
				'VIP'      => 'VIP',
				'Customer' => 'Customer',
			),
			$this->provider()->get_available_tags()
		);
	}

	/**
	 * A multi-select write replaces the whole set, so applying one tag must
	 * not drop the others.
	 */
	public function test_applying_a_tag_preserves_existing_ones(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->apply_tags( '003xx000004TmiQ', array( 'Partner' ) );

		$patch = null;

		foreach ( $this->requests as $request ) {
			if ( 'PATCH' === $request['method'] ) {
				$patch = $request['body'];
			}
		}

		$this->assertNotNull( $patch );
		$this->assertSame( 'VIP;Customer;Partner', $patch['Conduit_Tags__c'] );
	}

	public function test_removing_a_tag_keeps_the_rest(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->remove_tags( '003xx000004TmiQ', array( 'VIP' ) );

		$patch = null;

		foreach ( $this->requests as $request ) {
			if ( 'PATCH' === $request['method'] ) {
				$patch = $request['body'];
			}
		}

		$this->assertNotNull( $patch );
		$this->assertSame( 'Customer', $patch['Conduit_Tags__c'] );
	}

	/**
	 * Picklist values are org metadata and cannot be created through the data
	 * API, so this must fail loudly rather than silently no-op.
	 */
	public function test_creating_a_tag_explains_why_it_cannot(): void {
		$this->expectException( CrmException::class );

		$this->provider()->create_tag( 'Brand New' );
	}

	public function test_it_strips_the_id_from_an_update_payload(): void {
		$provider = $this->provider();

		$this->requests = array();

		$provider->update_contact( '003xx000004TmiQ', array( 'Id' => 'nope', 'FirstName' => 'Ada' ) );

		$patch = $this->requests[ count( $this->requests ) - 1 ]['body'];

		$this->assertArrayNotHasKey( 'Id', $patch, 'Salesforce rejects an update containing the record Id.' );
		$this->assertSame( 'Ada', $patch['FirstName'] );
	}
}
