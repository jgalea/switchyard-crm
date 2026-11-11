<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Support;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Contracts\SupportsWebhooks;
use Switchyard\Webhook\Payload;
use WP_REST_Request;

final class FakeCrm implements Provider, SupportsTags, SupportsWebhooks {

	/** @var array<string, string> email => contact id */
	public array $contacts = array();

	/** @var array<string, list<string>> contact id => tag ids */
	public array $tags = array();

	/** @var array<string, array<string, mixed>> contact id => fields */
	public array $records = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	public array $added = array();

	public ?Payload $payload = null;

	public function get_slug(): string {
		return 'fakecrm';
	}

	public function get_name(): string {
		return 'Fake CRM';
	}

	public function get_credential_fields(): array {
		return array();
	}

	public function connect( array $credentials ): void {}

	public function get_contact_id( string $email ): ?string {
		return $this->contacts[ $email ] ?? null;
	}

	public function add_contact( array $fields ): string {
		$id            = 'N' . ( count( $this->added ) + 1 );
		$this->added[] = array( $id, $fields );

		return $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->records[ $contact_id ] = $fields;
	}

	public function load_contact( string $contact_id ): array {
		return $this->records[ $contact_id ] ?? array();
	}

	public function get_available_fields(): array {
		return array(
			'email' => array(
				'label' => 'Email',
				'type'  => 'email',
			),
		);
	}

	public function get_available_tags(): array {
		return array();
	}

	public function get_tags( string $contact_id ): array {
		return $this->tags[ $contact_id ] ?? array();
	}

	/** @var array<string, list<string>> */
	public array $applied = array();

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		$this->applied[ $contact_id ] = $tag_ids;
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {}

	public function create_tag( string $label ): string {
		return '1';
	}

	public function verify_webhook( WP_REST_Request $request ): bool {
		return true;
	}

	public function parse_webhook( WP_REST_Request $request ): ?Payload {
		return $this->payload;
	}
}
