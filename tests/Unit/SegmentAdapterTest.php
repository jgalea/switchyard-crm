<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Contracts\SupportsLists;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Sync\SegmentAdapter;
use Switchyard\Tests\TestCase;

/**
 * The adapter is what lets one access-control model work across CRMs that
 * have tags and CRMs that only have lists. If it picks the wrong mode, tag
 * gating silently stops working on half the supported providers.
 */
final class SegmentAdapterTest extends TestCase {

	/**
  * @return (\Switchyard\Crm\Contracts\Provider & \Switchyard\Crm\Contracts\SupportsTags)
  */
 private function tag_provider() {
		return new class() implements Provider, SupportsTags {

			/** @var array<string, list<string>> */
			public array $applied = array();

			/** @var array<string, list<string>> */
			public array $removed = array();

			public array $created = array();

			public function get_slug(): string {
				return 'tagcrm'; }
			public function get_name(): string {
				return 'Tag CRM'; }
			public function get_credential_fields(): array {
				return array(); }
			public function connect( array $credentials ): void {}
			public function get_contact_id( string $email ): ?string {
				return '1'; }
			public function add_contact( array $fields ): string {
				return '1'; }
			public function update_contact( string $contact_id, array $fields ): void {}
			public function load_contact( string $contact_id ): array {
				return array(); }
			public function get_available_fields(): array {
				return array(); }

			public function get_available_tags(): array {
				return array(
					'10' => 'Customer',
					'20' => 'VIP',
				);
			}

			public function get_tags( string $contact_id ): array {
				return array( '10' );
			}

			public function apply_tags( string $contact_id, array $tag_ids ): void {
				$this->applied[ $contact_id ] = $tag_ids;
			}

			public function remove_tags( string $contact_id, array $tag_ids ): void {
				$this->removed[ $contact_id ] = $tag_ids;
			}

			public function create_tag( string $label ): string {
				$this->created[] = $label;

				return '99';
			}
		};
	}

	/**
  * @return (\Switchyard\Crm\Contracts\Provider & \Switchyard\Crm\Contracts\SupportsLists)
  */
 private function list_provider() {
		return new class() implements Provider, SupportsLists {

			/** @var array<string, list<string>> */
			public array $added = array();

			/** @var array<string, list<string>> */
			public array $removed = array();

			public function get_slug(): string {
				return 'listcrm'; }
			public function get_name(): string {
				return 'List CRM'; }
			public function get_credential_fields(): array {
				return array(); }
			public function connect( array $credentials ): void {}
			public function get_contact_id( string $email ): ?string {
				return '1'; }
			public function add_contact( array $fields ): string {
				return '1'; }
			public function update_contact( string $contact_id, array $fields ): void {}
			public function load_contact( string $contact_id ): array {
				return array(); }
			public function get_available_fields(): array {
				return array(); }

			public function get_available_lists(): array {
				return array( 'L1' => 'Newsletter' );
			}

			public function add_to_lists( string $contact_id, array $list_ids ): void {
				$this->added[ $contact_id ] = $list_ids;
			}

			public function remove_from_lists( string $contact_id, array $list_ids ): void {
				$this->removed[ $contact_id ] = $list_ids;
			}
		};
	}

	private function bare_provider(): Provider {
		return new class() implements Provider {
			public function get_slug(): string {
				return 'bare'; }
			public function get_name(): string {
				return 'Bare CRM'; }
			public function get_credential_fields(): array {
				return array(); }
			public function connect( array $credentials ): void {}
			public function get_contact_id( string $email ): ?string {
				return null; }
			public function add_contact( array $fields ): string {
				return '1'; }
			public function update_contact( string $contact_id, array $fields ): void {}
			public function load_contact( string $contact_id ): array {
				return array(); }
			public function get_available_fields(): array {
				return array(); }
		};
	}

	public function test_it_uses_tags_when_the_crm_has_them(): void {
		$provider = $this->tag_provider();
		$adapter  = new SegmentAdapter( $provider );

		$this->assertTrue( $adapter->supported() );
		$this->assertTrue( $adapter->can_read_membership() );
		$this->assertTrue( $adapter->can_create() );

		$adapter->apply( '1', array( '20' ) );

		$this->assertSame( array( '20' ), $provider->applied['1'] );
	}

	public function test_it_falls_back_to_lists_when_the_crm_has_no_tags(): void {
		$provider = $this->list_provider();
		$adapter  = new SegmentAdapter( $provider );

		$this->assertTrue( $adapter->supported() );

		$adapter->apply( '1', array( 'L1' ) );

		$this->assertSame( array( 'L1' ), $provider->added['1'] );
	}

	/**
	 * List CRMs have no cheap per-contact membership read, so the engine must
	 * be told not to treat an empty result as "this user has no tags".
	 */
	public function test_list_crms_report_that_membership_is_not_readable(): void {
		$adapter = new SegmentAdapter( $this->list_provider() );

		$this->assertFalse( $adapter->can_read_membership() );
		$this->assertSame( array(), $adapter->get( '1' ) );
	}

	public function test_a_crm_with_neither_is_reported_unsupported(): void {
		$adapter = new SegmentAdapter( $this->bare_provider() );

		$this->assertFalse( $adapter->supported() );
		$this->assertSame( array(), $adapter->available() );
	}

	public function test_it_resolves_labels_to_identifiers(): void {
		$adapter = new SegmentAdapter( $this->tag_provider() );

		$this->assertSame( array( '20' ), $adapter->resolve( array( 'VIP' ) ) );
	}

	public function test_label_resolution_ignores_case(): void {
		$adapter = new SegmentAdapter( $this->tag_provider() );

		$this->assertSame( array( '10' ), $adapter->resolve( array( 'customer' ) ) );
	}

	public function test_it_passes_through_values_that_are_already_identifiers(): void {
		$adapter = new SegmentAdapter( $this->tag_provider() );

		$this->assertSame( array( '10' ), $adapter->resolve( array( '10' ) ) );
	}

	/**
	 * Sending an identifier the CRM does not know is a silent no-op in some
	 * CRMs and a hard error in others, so unknown values are dropped.
	 */
	public function test_it_drops_unresolvable_values_by_default(): void {
		$adapter = new SegmentAdapter( $this->tag_provider() );

		$this->assertSame( array(), $adapter->resolve( array( 'Does Not Exist' ) ) );
	}

	public function test_it_can_create_missing_tags_on_demand(): void {
		$provider = $this->tag_provider();
		$adapter  = new SegmentAdapter( $provider );

		$this->assertSame( array( '99' ), $adapter->resolve( array( 'Brand New' ), true ) );
		$this->assertSame( array( 'Brand New' ), $provider->created );
	}

	public function test_it_does_not_invent_lists_on_a_list_crm(): void {
		$adapter = new SegmentAdapter( $this->list_provider() );

		$this->assertFalse( $adapter->can_create() );
		$this->assertSame( array(), $adapter->resolve( array( 'Brand New' ), true ) );

		$this->expectException( CrmException::class );

		$adapter->create( 'Brand New' );
	}

	public function test_it_deduplicates_resolved_values(): void {
		$adapter = new SegmentAdapter( $this->tag_provider() );

		$this->assertSame( array( '10' ), $adapter->resolve( array( '10', 'Customer', 'customer' ) ) );
	}

	public function test_writing_an_empty_set_is_a_no_op(): void {
		$provider = $this->tag_provider();
		$adapter  = new SegmentAdapter( $provider );

		$adapter->apply( '1', array() );
		$adapter->remove( '1', array() );

		$this->assertSame( array(), $provider->applied );
		$this->assertSame( array(), $provider->removed );
	}
}
