<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Contracts\SupportsWebhooks;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Webhook\Payload;
use WP_REST_Request;

/**
 * Kit (formerly ConvertKit), API v4.
 *
 * Base URL https://api.kit.com/v4, authenticated with the X-Kit-Api-Key
 * header. Kit models everything as tags on subscribers; it has no separate
 * list concept, so SupportsLists is deliberately not implemented.
 */
final class Kit extends AbstractProvider implements SupportsTags, SupportsWebhooks, SupportsContactImport {

	private const PAGE_SIZE = 500;

	public function get_slug(): string {
		return 'kit';
	}

	public function get_name(): string {
		return 'Kit (ConvertKit)';
	}

	public function get_credential_fields(): array {
		return array(
			'api_key' => array(
				'label'    => __( 'API Key', 'conduit-crm' ),
				'type'     => 'password',
				'help'     => __( 'Kit → Settings → Developer → API Key (v4).', 'conduit-crm' ),
				'required' => true,
			),
		);
	}

	protected function base_url(): string {
		return 'https://api.kit.com/v4';
	}

	protected function auth_headers(): array {
		return array( 'X-Kit-Api-Key' => $this->credential( 'api_key' ) );
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/account' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				__( 'Kit rejected this API key.', 'conduit-crm' ),
				array( 'error' => $e->getMessage() )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		$response = $this->http()->get( '/subscribers', array( 'email_address' => $email ) );

		$id = $response->path( 'subscribers.0.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post( '/subscribers', $this->to_payload( $fields ) );

		$id = $response->path( 'subscriber.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( __( 'Kit did not return a subscriber ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->http()->put( '/subscribers/' . rawurlencode( $contact_id ), $this->to_payload( $fields ) );
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/subscribers/' . rawurlencode( $contact_id ) );

		$subscriber = $response->json()['subscriber'] ?? array();
		$loaded     = array();

		if ( isset( $subscriber['email_address'] ) ) {
			$loaded['email_address'] = $subscriber['email_address'];
		}

		if ( isset( $subscriber['first_name'] ) ) {
			$loaded['first_name'] = $subscriber['first_name'];
		}

		foreach ( $subscriber['fields'] ?? array() as $key => $value ) {
			$loaded[ 'field_' . $key ] = $value;
		}

		return $loaded;
	}

	public function get_available_fields(): array {
		return $this->cached(
			'fields',
			function (): array {
				$fields = array(
					'email_address' => array(
						'label' => __( 'Email', 'conduit-crm' ),
						'type'  => 'email',
					),
					'first_name'    => array(
						'label' => __( 'First Name', 'conduit-crm' ),
						'type'  => 'text',
					),
				);

				$response = $this->http()->get( '/custom_fields', array( 'per_page' => self::PAGE_SIZE ) );

				foreach ( $response->json()['custom_fields'] ?? array() as $field ) {
					if ( ! isset( $field['key'] ) ) {
						continue;
					}

					$fields[ 'field_' . $field['key'] ] = array(
						'label' => (string) ( $field['label'] ?? $field['key'] ),
						'type'  => 'text',
					);
				}

				return $fields;
			}
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function to_payload( array $fields ): array {
		$payload = array();
		$custom  = array();

		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( $key, 'field_' ) ) {
				$custom[ substr( $key, 6 ) ] = $value;

				continue;
			}

			$payload[ $key ] = $value;
		}

		if ( ! empty( $custom ) ) {
			$payload['fields'] = $custom;
		}

		return $payload;
	}

	public function get_available_tags(): array {
		return $this->cached(
			'tags',
			function (): array {
				$tags   = array();
				$cursor = null;

				do {
					$response = $this->http()->get(
						'/tags',
						array(
							'per_page' => self::PAGE_SIZE,
							'after'    => $cursor,
						)
					);

					foreach ( $response->json()['tags'] ?? array() as $tag ) {
						if ( isset( $tag['id'] ) ) {
							$tags[ (string) $tag['id'] ] = (string) ( $tag['name'] ?? $tag['id'] );
						}
					}

					$cursor = $response->path( 'pagination.end_cursor' );
					$more   = (bool) $response->path( 'pagination.has_next_page', false );
				} while ( $more && is_string( $cursor ) );

				return $tags;
			}
		);
	}

	public function get_tags( string $contact_id ): array {
		$response = $this->http()->get( '/subscribers/' . rawurlencode( $contact_id ) . '/tags' );

		$tags = array();

		foreach ( $response->json()['tags'] ?? array() as $tag ) {
			if ( isset( $tag['id'] ) ) {
				$tags[] = (string) $tag['id'];
			}
		}

		return $tags;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		foreach ( $tag_ids as $tag_id ) {
			$this->http()->post(
				sprintf( '/tags/%s/subscribers/%s', rawurlencode( $tag_id ), rawurlencode( $contact_id ) )
			);
		}
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		foreach ( $tag_ids as $tag_id ) {
			$this->http()->delete(
				sprintf( '/tags/%s/subscribers/%s', rawurlencode( $tag_id ), rawurlencode( $contact_id ) )
			);
		}
	}

	public function create_tag( string $label ): string {
		$response = $this->http()->post( '/tags', array( 'name' => $label ) );

		$id = $response->path( 'tag.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( __( 'Kit did not return a tag ID.', 'conduit-crm' ) );
		}

		$this->flush_cache();

		return (string) $id;
	}

	public function verify_webhook( WP_REST_Request $request ): bool {
		return true;
	}

	public function parse_webhook( WP_REST_Request $request ): ?Payload {
		$body = $request->get_params();

		$email = $body['subscriber']['email_address'] ?? null;

		if ( ! is_string( $email ) || '' === $email ) {
			return null;
		}

		$id = isset( $body['subscriber']['id'] ) ? (string) $body['subscriber']['id'] : null;

		return new Payload( $email, $id, is_string( $body['type'] ?? null ) ? $body['type'] : 'update' );
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$path  = null === $tag_id ? '/subscribers' : '/tags/' . rawurlencode( $tag_id ) . '/subscribers';
		$query = array(
			'per_page' => self::PAGE_SIZE,
			'after'    => $cursor,
		);

		$response = $this->http()->get( $path, $query );

		$ids = array();

		foreach ( $response->json()['subscribers'] ?? array() as $subscriber ) {
			$id = $subscriber['id'] ?? ( $subscriber['subscriber']['id'] ?? null );

			if ( is_scalar( $id ) ) {
				$ids[] = (string) $id;
			}
		}

		$next = $response->path( 'pagination.end_cursor' );
		$more = (bool) $response->path( 'pagination.has_next_page', false );

		return array(
			'ids'    => $ids,
			'cursor' => $more && is_string( $next ) ? $next : null,
		);
	}
}
