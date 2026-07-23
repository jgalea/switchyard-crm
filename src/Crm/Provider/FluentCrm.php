<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;

/**
 * FluentCRM, which runs inside WordPress rather than over HTTP.
 *
 * Every call goes straight to FluentCRM's models, so there is no network hop,
 * no rate limit and no circuit breaker. The trade-off is that this provider
 * depends on another plugin's internals, so every entry point checks that
 * FluentCRM is actually loaded before touching a class.
 */
final class FluentCrm extends AbstractProvider implements SupportsTags, SupportsLists, SupportsContactImport {

	private const PAGE_SIZE = 500;

	public function get_slug(): string {
		return 'fluentcrm';
	}

	public function get_name(): string {
		return 'FluentCRM';
	}

	public function get_credential_fields(): array {
		// Nothing to authenticate: the CRM is the site.
		return array();
	}

	protected function base_url(): string {
		return '';
	}

	protected function auth_headers(): array {
		return array();
	}

	protected function verify_credentials(): void {
		if ( ! $this->is_available() ) {
			throw new ConnectionException(
				esc_html__( 'FluentCRM is not active on this site. Install and activate it first.', 'conduit-crm' )
			);
		}
	}

	private function is_available(): bool {
		return defined( 'FLUENTCRM' ) && class_exists( Subscriber::class );
	}

	/** @throws CrmException */
	private function guard(): void {
		if ( ! $this->is_available() ) {
			throw new CrmException( esc_html__( 'FluentCRM is no longer active on this site.', 'conduit-crm' ) );
		}
	}

	public function get_contact_id( string $email ): ?string {
		$this->guard();

		$subscriber = Subscriber::where( 'email', $email )->first();

		return $subscriber ? (string) $subscriber->id : null;
	}

	public function add_contact( array $fields ): string {
		$this->guard();

		$data = $this->to_payload( $fields );

		if ( empty( $data['email'] ) ) {
			throw new CrmException( esc_html__( 'FluentCRM needs an email address to create a contact.', 'conduit-crm' ) );
		}

		$contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );

		if ( ! $contact || ! isset( $contact->id ) ) {
			throw new CrmException( esc_html__( 'FluentCRM did not return a contact.', 'conduit-crm' ) );
		}

		return (string) $contact->id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( ! $subscriber ) {
			throw new CrmException( esc_html__( 'That FluentCRM contact no longer exists.', 'conduit-crm' ) );
		}

		$data = $this->to_payload( $fields );

		$custom = $data['custom_values'] ?? array();
		unset( $data['custom_values'] );

		if ( ! empty( $data ) ) {
			$subscriber->fill( $data )->save();
		}

		if ( ! empty( $custom ) && method_exists( $subscriber, 'syncCustomFieldValues' ) ) {
			$subscriber->syncCustomFieldValues( $custom, false );
		}
	}

	public function load_contact( string $contact_id ): array {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( ! $subscriber ) {
			return array();
		}

		$loaded = array();

		foreach ( array( 'email', 'first_name', 'last_name', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ) as $native ) {
			if ( isset( $subscriber->{$native} ) ) {
				$loaded[ $native ] = $subscriber->{$native};
			}
		}

		if ( method_exists( $subscriber, 'custom_fields' ) ) {
			foreach ( (array) $subscriber->custom_fields() as $key => $value ) {
				$loaded[ 'custom_' . $key ] = $value;
			}
		}

		return $loaded;
	}

	public function get_available_fields(): array {
		$this->guard();

		$fields = array(
			'email'       => array(
				'label' => __( 'Email', 'conduit-crm' ),
				'type'  => 'email',
			),
			'first_name'  => array(
				'label' => __( 'First Name', 'conduit-crm' ),
				'type'  => 'text',
			),
			'last_name'   => array(
				'label' => __( 'Last Name', 'conduit-crm' ),
				'type'  => 'text',
			),
			'phone'       => array(
				'label' => __( 'Phone', 'conduit-crm' ),
				'type'  => 'text',
			),
			'city'        => array(
				'label' => __( 'City', 'conduit-crm' ),
				'type'  => 'text',
			),
			'state'       => array(
				'label' => __( 'State', 'conduit-crm' ),
				'type'  => 'text',
			),
			'postal_code' => array(
				'label' => __( 'Postal Code', 'conduit-crm' ),
				'type'  => 'text',
			),
			'country'     => array(
				'label' => __( 'Country', 'conduit-crm' ),
				'type'  => 'text',
			),
		);

		$custom_field_class = '\FluentCrm\App\Services\CustomContactField';

		if ( class_exists( $custom_field_class ) ) {
			$service = new $custom_field_class();

			if ( method_exists( $service, 'getGlobalFields' ) ) {
				$global = $service->getGlobalFields();

				foreach ( $global['fields'] ?? array() as $field ) {
					if ( ! isset( $field['slug'] ) ) {
						continue;
					}

					$fields[ 'custom_' . $field['slug'] ] = array(
						'label' => (string) ( $field['label'] ?? $field['slug'] ),
						'type'  => match ( (string) ( $field['type'] ?? 'text' ) ) {
							'date', 'date_time' => 'date',
							'number' => 'number',
							'select-one', 'radio' => 'select',
							'select-multi', 'checkbox' => 'multiselect',
							'textarea' => 'textarea',
							default => 'text',
						},
					);
				}
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function to_payload( array $fields ): array {
		$payload = array();
		$custom  = array();

		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( $key, 'custom_' ) ) {
				$custom[ substr( $key, 7 ) ] = $value;

				continue;
			}

			$payload[ $key ] = $value;
		}

		if ( ! empty( $custom ) ) {
			$payload['custom_values'] = $custom;
		}

		return $payload;
	}

	public function get_available_tags(): array {
		$this->guard();

		$tags = array();

		foreach ( Tag::get() as $tag ) {
			$tags[ (string) $tag->id ] = (string) $tag->title;
		}

		return $tags;
	}

	public function get_tags( string $contact_id ): array {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( ! $subscriber ) {
			return array();
		}

		$tags = array();

		foreach ( $subscriber->tags as $tag ) {
			$tags[] = (string) $tag->id;
		}

		return $tags;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( $subscriber ) {
			$subscriber->attachTags( array_map( 'intval', $tag_ids ) );
		}
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( $subscriber ) {
			$subscriber->detachTags( array_map( 'intval', $tag_ids ) );
		}
	}

	public function create_tag( string $label ): string {
		$this->guard();

		$existing = Tag::where( 'title', $label )->first();

		if ( $existing ) {
			return (string) $existing->id;
		}

		$tag = Tag::create(
			array(
				'title' => $label,
				'slug'  => sanitize_title( $label ),
			)
		);

		return (string) $tag->id;
	}

	public function get_available_lists(): array {
		$this->guard();

		$lists = array();

		foreach ( Lists::get() as $list ) {
			$lists[ (string) $list->id ] = (string) $list->title;
		}

		return $lists;
	}

	public function add_to_lists( string $contact_id, array $list_ids ): void {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( $subscriber ) {
			$subscriber->attachLists( array_map( 'intval', $list_ids ) );
		}
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		$this->guard();

		$subscriber = Subscriber::find( (int) $contact_id );

		if ( $subscriber ) {
			$subscriber->detachLists( array_map( 'intval', $list_ids ) );
		}
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$this->guard();

		$offset = null === $cursor ? 0 : (int) $cursor;

		$query = Subscriber::query();

		if ( null !== $tag_id ) {
			$query = $query->whereHas(
				'tags',
				static function ( $relation ) use ( $tag_id ): void {
					$relation->where( 'id', (int) $tag_id );
				}
			);
		}

		$ids = array();

		foreach ( $query->offset( $offset )->limit( self::PAGE_SIZE )->get() as $subscriber ) {
			$ids[] = (string) $subscriber->id;
		}

		return array(
			'ids'    => $ids,
			'cursor' => count( $ids ) === self::PAGE_SIZE ? (string) ( $offset + self::PAGE_SIZE ) : null,
		);
	}
}
