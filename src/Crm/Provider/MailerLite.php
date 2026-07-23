<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Exception\NotFoundException;

/**
 * MailerLite API.
 *
 * Base URL https://connect.mailerlite.com/api with bearer authentication.
 * Groups play the role tags play elsewhere.
 */
final class MailerLite extends AbstractProvider implements SupportsLists, SupportsContactImport {

	private const PAGE_SIZE = 100;

	public function get_slug(): string {
		return 'mailerlite';
	}

	public function get_name(): string {
		return 'MailerLite';
	}

	public function get_credential_fields(): array {
		return array(
			'api_key' => array(
				'label'    => __( 'API Token', 'conduit-crm' ),
				'type'     => 'password',
				'help'     => __( 'MailerLite → Integrations → API.', 'conduit-crm' ),
				'required' => true,
			),
		);
	}

	protected function base_url(): string {
		return 'https://connect.mailerlite.com/api';
	}

	protected function auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->credential( 'api_key' ),
			'Content-Type'  => 'application/json',
		);
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/groups', array( 'limit' => 1 ) );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'MailerLite rejected this API token.', 'conduit-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		try {
			$response = $this->http()->get( '/subscribers/' . rawurlencode( $email ) );
		} catch ( NotFoundException ) {
			return null;
		}

		$id = $response->path( 'data.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	/**
	 * The subscribers endpoint upserts on email, so create and update share a
	 * path and a concurrent double-submit cannot produce duplicates.
	 */
	public function add_contact( array $fields ): string {
		$response = $this->http()->post( '/subscribers', $this->to_payload( $fields ) );

		$id = $response->path( 'data.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'MailerLite did not return a subscriber ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->http()->put( '/subscribers/' . rawurlencode( $contact_id ), $this->to_payload( $fields ) );
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/subscribers/' . rawurlencode( $contact_id ) );

		$subscriber = $response->path( 'data', array() );

		if ( ! is_array( $subscriber ) ) {
			return array();
		}

		$loaded = array();

		if ( isset( $subscriber['email'] ) ) {
			$loaded['email'] = $subscriber['email'];
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
					'email' => array(
						'label' => __( 'Email', 'conduit-crm' ),
						'type'  => 'email',
					),
				);

				$response = $this->http()->get( '/fields', array( 'limit' => self::PAGE_SIZE ) );

				foreach ( $response->json()['data'] ?? array() as $field ) {
					if ( ! isset( $field['key'] ) ) {
						continue;
					}

					$fields[ 'field_' . $field['key'] ] = array(
						'label' => (string) ( $field['name'] ?? $field['key'] ),
						'type'  => match ( (string) ( $field['type'] ?? 'text' ) ) {
							'date' => 'date',
							'number' => 'number',
							default => 'text',
						},
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

	public function get_available_lists(): array {
		return $this->cached(
			'lists',
			function (): array {
				$lists = array();
				$page  = 1;

				do {
					$response = $this->http()->get(
						'/groups',
						array(
							'limit' => self::PAGE_SIZE,
							'page'  => $page,
						)
					);

					$batch = $response->json()['data'] ?? array();

					foreach ( $batch as $group ) {
						if ( isset( $group['id'] ) ) {
							$lists[ (string) $group['id'] ] = (string) ( $group['name'] ?? $group['id'] );
						}
					}

					++$page;
					$fetched = count( $batch );
				} while ( self::PAGE_SIZE === $fetched );

				return $lists;
			}
		);
	}

	public function add_to_lists( string $contact_id, array $list_ids ): void {
		foreach ( $list_ids as $list_id ) {
			$this->http()->post(
				sprintf( '/subscribers/%s/groups/%s', rawurlencode( $contact_id ), rawurlencode( $list_id ) )
			);
		}
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		foreach ( $list_ids as $list_id ) {
			$this->http()->delete(
				sprintf( '/subscribers/%s/groups/%s', rawurlencode( $contact_id ), rawurlencode( $list_id ) )
			);
		}
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$path = null === $tag_id
			? '/subscribers'
			: sprintf( '/groups/%s/subscribers', rawurlencode( $tag_id ) );

		$response = $this->http()->get(
			$path,
			array(
				'limit'  => self::PAGE_SIZE,
				'cursor' => $cursor,
			)
		);

		$ids = array();

		foreach ( $response->json()['data'] ?? array() as $subscriber ) {
			if ( isset( $subscriber['id'] ) ) {
				$ids[] = (string) $subscriber['id'];
			}
		}

		$next = $response->path( 'meta.next_cursor' );

		return array(
			'ids'    => $ids,
			'cursor' => is_string( $next ) && '' !== $next ? $next : null,
		);
	}
}
