<?php

declare( strict_types=1 );

namespace Conduit\Tests\Contract;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Provider\HubSpot;

final class HubSpotTest extends ProviderContractTestCase {

	protected function fresh_provider(): Provider {
		return new HubSpot( $this->client() );
	}

	protected function provider(): Provider {
		$provider = $this->fresh_provider();
		$provider->connect( $this->credentials() );

		return $provider;
	}

	protected function credentials(): array {
		return array(
			'access_token' => 'pat-na1-test',
			'portal_id'    => '1234567',
		);
	}

	protected function expected_contact_id(): string {
		return '701';
	}

	protected function sample_fields(): array {
		return array(
			'email'     => 'person@example.com',
			'firstname' => 'Ada',
		);
	}

	protected function fixtures(): array {
		return array(
			'POST https://api.hubapi.com/crm/v3/objects/contacts/search' => array( 'body' => '{"results":[{"id":"701"}]}' ),
			'GET https://api.hubapi.com/crm/v3/objects/contacts/701'     => array( 'body' => '{"id":"701","properties":{"email":"person@example.com","firstname":"Ada"}}' ),
			'POST https://api.hubapi.com/crm/v3/objects/contacts'        => array( 'body' => '{"id":"701"}' ),
			'GET https://api.hubapi.com/crm/v3/objects/contacts'         => array( 'body' => '{"results":[{"id":"701"}],"paging":{}}' ),
			'GET https://api.hubapi.com/crm/v3/properties/contacts'      => array( 'body' => '{"results":[{"name":"email","label":"Email","fieldType":"text"},{"name":"firstname","label":"First Name","fieldType":"text"}]}' ),
			'POST https://api.hubapi.com/crm/v3/lists/search'            => array( 'body' => '{"lists":[{"listId":"5","name":"Customers"}]}' ),
			'/crm/v3/lists/5/memberships/add'                            => array( 'body' => '{}' ),
			'/crm/v3/lists/5/memberships/remove'                         => array( 'body' => '{}' ),
			'GET https://api.hubapi.com/crm/v3/lists/5/memberships'      => array( 'body' => '{"results":[{"recordId":"701"}],"paging":{}}' ),
			'POST https://api.hubapi.com/crm/v3/lists'                   => array( 'body' => '{"list":{"listId":"6"}}' ),
		);
	}

	/**
	 * HubSpot has no tag object, so static lists stand in for tags. If that
	 * mapping breaks, access control silently stops working.
	 */
	public function test_static_lists_stand_in_for_tags(): void {
		$tags = $this->provider()->get_available_tags();

		$this->assertSame( array( '5' => 'Customers' ), $tags );
	}

	public function test_applying_a_tag_adds_a_list_membership(): void {
		$this->provider()->apply_tags( '701', array( '5' ) );

		$this->assertRequested( 'PUT https://api.hubapi.com/crm/v3/lists/5/memberships/add' );
	}

	public function test_removing_a_tag_removes_the_membership(): void {
		$this->provider()->remove_tags( '701', array( '5' ) );

		$this->assertRequested( 'PUT https://api.hubapi.com/crm/v3/lists/5/memberships/remove' );
	}

	public function test_it_looks_up_contacts_through_the_search_endpoint(): void {
		$id = $this->provider()->get_contact_id( 'person@example.com' );

		$this->assertSame( '701', $id );

		$search = null;

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], '/contacts/search' ) ) {
				$search = $request['body'];
			}
		}

		$this->assertNotNull( $search );
		$this->assertSame( 'email', $search['filterGroups'][0]['filters'][0]['propertyName'] );
		$this->assertSame( 'EQ', $search['filterGroups'][0]['filters'][0]['operator'] );
	}

	public function test_it_wraps_writes_in_a_properties_object(): void {
		$this->provider()->add_contact( $this->sample_fields() );

		$create = null;

		foreach ( $this->requests as $request ) {
			if ( 'POST' === $request['method'] && str_ends_with( $request['url'], '/objects/contacts' ) ) {
				$create = $request['body'];
			}
		}

		$this->assertNotNull( $create );
		$this->assertSame( 'person@example.com', $create['properties']['email'] );
	}
}
