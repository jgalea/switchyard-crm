<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsEvents;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Contracts\SupportsTracking;
use Conduit\Crm\Contracts\SupportsWebhooks;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Webhook\Payload;
use WP_REST_Request;

/**
 * ActiveCampaign API v3.
 *
 * Base URL is account-derived: https://{account}.api-us1.com/api/3
 * Authentication is the Api-Token header.
 */
final class ActiveCampaign extends AbstractProvider implements
	SupportsTags,
	SupportsLists,
	SupportsTracking,
	SupportsEvents,
	SupportsWebhooks,
	SupportsContactImport {

	private const PAGE_SIZE = 100;

	public function get_slug(): string {
		return 'activecampaign';
	}

	public function get_name(): string {
		return 'ActiveCampaign';
	}

	public function get_credential_fields(): array {
		return array(
			'api_url'     => array(
				'label'    => __( 'API URL', 'conduit-crm' ),
				'type'     => 'url',
				'help'     => __( 'Settings → Developer in ActiveCampaign. Looks like https://youraccount.api-us1.com', 'conduit-crm' ),
				'required' => true,
			),
			'api_key'     => array(
				'label'    => __( 'API Key', 'conduit-crm' ),
				'type'     => 'password',
				'required' => true,
			),
			'tracking_id' => array(
				'label'    => __( 'Tracking Account ID', 'conduit-crm' ),
				'type'     => 'text',
				'help'     => __( 'Optional. Needed for site tracking and event tracking.', 'conduit-crm' ),
				'required' => false,
			),
			'event_key'   => array(
				'label'    => __( 'Event Tracking Key', 'conduit-crm' ),
				'type'     => 'password',
				'required' => false,
			),
		);
	}

	protected function base_url(): string {
		return rtrim( $this->credential( 'api_url' ), '/' ) . '/api/3';
	}

	protected function auth_headers(): array {
		return array( 'Api-Token' => $this->credential( 'api_key' ) );
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/users/me' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'ActiveCampaign rejected these credentials. Check the API URL and key.', 'conduit-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		$response = $this->http()->get( '/contacts', array( 'email' => $email ) );

		$id = $response->path( 'contacts.0.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post( '/contact/sync', array( 'contact' => $this->to_payload( $fields ) ) );

		$id = $response->path( 'contact.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'ActiveCampaign did not return a contact ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$payload = $this->to_payload( $fields );

		// The sync endpoint upserts on email, which avoids a race where two
		// requests both create the same contact.
		if ( isset( $payload['email'] ) ) {
			$this->http()->post( '/contact/sync', array( 'contact' => $payload ) );

			return;
		}

		$this->http()->put( '/contacts/' . rawurlencode( $contact_id ), array( 'contact' => $payload ) );
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) );

		$contact = $response->json()['contact'] ?? array();
		$loaded  = array();

		foreach ( array( 'email', 'firstName', 'lastName', 'phone' ) as $native ) {
			if ( isset( $contact[ $native ] ) ) {
				$loaded[ $native ] = $contact[ $native ];
			}
		}

		$values = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) . '/fieldValues' );

		foreach ( $values->json()['fieldValues'] ?? array() as $value ) {
			if ( isset( $value['field'] ) ) {
				$loaded[ 'field_' . $value['field'] ] = $value['value'] ?? '';
			}
		}

		return $loaded;
	}

	public function get_available_fields(): array {
		return $this->cached(
			'fields',
			function (): array {
				$fields = array(
					'email'     => array(
						'label' => __( 'Email', 'conduit-crm' ),
						'type'  => 'email',
					),
					'firstName' => array(
						'label' => __( 'First Name', 'conduit-crm' ),
						'type'  => 'text',
					),
					'lastName'  => array(
						'label' => __( 'Last Name', 'conduit-crm' ),
						'type'  => 'text',
					),
					'phone'     => array(
						'label' => __( 'Phone', 'conduit-crm' ),
						'type'  => 'text',
					),
				);

				$response = $this->http()->get( '/fields', array( 'limit' => self::PAGE_SIZE ) );

				foreach ( $response->json()['fields'] ?? array() as $field ) {
					if ( ! isset( $field['id'] ) ) {
						continue;
					}

					$fields[ 'field_' . $field['id'] ] = array(
						'label' => (string) ( $field['title'] ?? $field['id'] ),
						'type'  => $this->map_field_type( (string) ( $field['type'] ?? 'text' ) ),
					);
				}

				return $fields;
			}
		);
	}

	private function map_field_type( string $type ): string {
		switch ( $type ) {
			case 'date':
			case 'datetime':
				return 'date';
			case 'dropdown':
			case 'listbox':
			case 'radio':
				return 'select';
			case 'checkbox':
				return 'multiselect';
			case 'textarea':
				return 'textarea';
			default:
				return 'text';
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function to_payload( array $fields ): array {
		$payload      = array();
		$field_values = array();

		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( $key, 'field_' ) ) {
				$field_values[] = array(
					'field' => substr( $key, 6 ),
					'value' => is_array( $value ) ? implode( '||', $value ) : (string) $value,
				);

				continue;
			}

			$payload[ $key ] = $value;
		}

		if ( ! empty( $field_values ) ) {
			$payload['fieldValues'] = $field_values;
		}

		return $payload;
	}

	public function get_available_tags(): array {
		return $this->cached(
			'tags',
			function (): array {
				$tags   = array();
				$offset = 0;

				do {
					$response = $this->http()->get(
						'/tags',
						array(
							'limit'  => self::PAGE_SIZE,
							'offset' => $offset,
						)
					);

					$batch = $response->json()['tags'] ?? array();

					foreach ( $batch as $tag ) {
						if ( isset( $tag['id'] ) ) {
							$tags[ (string) $tag['id'] ] = (string) ( $tag['tag'] ?? $tag['id'] );
						}
					}

					$offset += self::PAGE_SIZE;
					$fetched = count( $batch );
				} while ( self::PAGE_SIZE === $fetched );

				return $tags;
			}
		);
	}

	public function get_tags( string $contact_id ): array {
		$response = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) . '/contactTags' );

		$tags = array();

		foreach ( $response->json()['contactTags'] ?? array() as $link ) {
			if ( isset( $link['tag'] ) ) {
				$tags[] = (string) $link['tag'];
			}
		}

		return $tags;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		foreach ( $tag_ids as $tag_id ) {
			$this->http()->post(
				'/contactTags',
				array(
					'contactTag' => array(
						'contact' => $contact_id,
						'tag'     => $tag_id,
					),
				)
			);
		}
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		// Removal needs the join-record ID, not the tag ID, so the current
		// links have to be read first.
		$response = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) . '/contactTags' );

		$links = array();

		foreach ( $response->json()['contactTags'] ?? array() as $link ) {
			if ( isset( $link['tag'], $link['id'] ) ) {
				$links[ (string) $link['tag'] ] = (string) $link['id'];
			}
		}

		foreach ( $tag_ids as $tag_id ) {
			if ( isset( $links[ (string) $tag_id ] ) ) {
				$this->http()->delete( '/contactTags/' . rawurlencode( $links[ (string) $tag_id ] ) );
			}
		}
	}

	public function create_tag( string $label ): string {
		$response = $this->http()->post(
			'/tags',
			array(
				'tag' => array(
					'tag'         => $label,
					'tagType'     => 'contact',
					'description' => '',
				),
			)
		);

		$id = $response->path( 'tag.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'ActiveCampaign did not return a tag ID.', 'conduit-crm' ) );
		}

		$this->flush_cache();

		return (string) $id;
	}

	public function get_available_lists(): array {
		return $this->cached(
			'lists',
			function (): array {
				$response = $this->http()->get( '/lists', array( 'limit' => self::PAGE_SIZE ) );

				$lists = array();

				foreach ( $response->json()['lists'] ?? array() as $list ) {
					if ( isset( $list['id'] ) ) {
						$lists[ (string) $list['id'] ] = (string) ( $list['name'] ?? $list['id'] );
					}
				}

				return $lists;
			}
		);
	}

	public function add_to_lists( string $contact_id, array $list_ids ): void {
		$this->set_list_status( $contact_id, $list_ids, 1 );
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		$this->set_list_status( $contact_id, $list_ids, 2 );
	}

	/** @param list<string> $list_ids */
	private function set_list_status( string $contact_id, array $list_ids, int $status ): void {
		foreach ( $list_ids as $list_id ) {
			$this->http()->post(
				'/contactLists',
				array(
					'contactList' => array(
						'list'    => $list_id,
						'contact' => $contact_id,
						'status'  => $status,
					),
				)
			);
		}
	}

	public function get_tracking_src(): string {
		if ( '' === $this->credential( 'tracking_id' ) ) {
			return '';
		}

		return 'https://diffuser-cdn.app-us1.com/diffuser/diffuser.js';
	}

	public function get_tracking_inline(): string {
		$account = $this->credential( 'tracking_id' );

		if ( '' === $account ) {
			return '';
		}

		// The vendor snippet creates the vgo queue before diffuser.js loads and
		// reads it back on load, so this has to print ahead of the file.
		return sprintf(
			'window.visitorGlobalObjectAlias="vgo";window.vgo=window.vgo||function(){(window.vgo.q=window.vgo.q||[]).push(arguments)};window.vgo.l=(new Date).getTime();vgo("setAccount",%s);vgo("setTrackByDefault",true);vgo("process");',
			(string) wp_json_encode( $account )
		);
	}

	public function get_identify_inline( string $email, ?string $contact_id ): string {
		if ( '' === $this->credential( 'tracking_id' ) || '' === $email ) {
			return '';
		}

		return sprintf( 'vgo("setEmail",%s);vgo("process");', (string) wp_json_encode( $email ) );
	}

	public function track_event( string $contact_id, string $event, array $data = array() ): void {
		$actid = $this->credential( 'tracking_id' );
		$key   = $this->credential( 'event_key' );

		if ( '' === $actid || '' === $key ) {
			throw new CrmException( esc_html__( 'Event tracking needs both a tracking account ID and an event key.', 'conduit-crm' ) );
		}

		$email = isset( $data['email'] ) && is_string( $data['email'] ) ? $data['email'] : '';
		unset( $data['email'] );

		// Event tracking lives on a separate host with a form-encoded body.
		$this->client_factory
			->for_provider( $this->get_slug() )
			->base_url( 'https://trackcmp.net' )
			->headers( array( 'Content-Type' => 'application/x-www-form-urlencoded' ) )
			->post(
				'/event',
				array(
					'actid'     => $actid,
					'key'       => $key,
					'event'     => $event,
					'eventdata' => (string) wp_json_encode( $data ),
					'visit'     => (string) wp_json_encode( array( 'email' => $email ) ),
				)
			);
	}

	public function verify_webhook( WP_REST_Request $request ): bool {
		// ActiveCampaign does not sign webhooks. The shared secret in the URL
		// is the only authenticity signal, and the listener checks that.
		return true;
	}

	public function parse_webhook( WP_REST_Request $request ): ?Payload {
		$body  = $request->get_params();
		$email = '';

		if ( isset( $body['contact']['email'] ) && is_string( $body['contact']['email'] ) ) {
			$email = $body['contact']['email'];
		}

		if ( '' === $email ) {
			return null;
		}

		$contact_id = isset( $body['contact']['id'] ) ? (string) $body['contact']['id'] : null;
		$type       = isset( $body['type'] ) && is_string( $body['type'] ) ? $body['type'] : 'update';

		return new Payload( $email, $contact_id, $type );
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$offset = null === $cursor ? 0 : (int) $cursor;

		$query = array(
			'limit'  => self::PAGE_SIZE,
			'offset' => $offset,
		);

		if ( null !== $tag_id ) {
			$query['tagid'] = $tag_id;
		}

		$response = $this->http()->get( '/contacts', $query );

		$ids = array();

		foreach ( $response->json()['contacts'] ?? array() as $contact ) {
			if ( isset( $contact['id'] ) ) {
				$ids[] = (string) $contact['id'];
			}
		}

		return array(
			'ids'    => $ids,
			'cursor' => count( $ids ) === self::PAGE_SIZE ? (string) ( $offset + self::PAGE_SIZE ) : null,
		);
	}
}
