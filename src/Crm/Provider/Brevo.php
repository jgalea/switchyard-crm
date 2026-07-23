<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Exception\NotFoundException;

/**
 * Brevo (formerly Sendinblue) API v3.
 *
 * Brevo models segmentation as lists plus contact attributes; there is no tag
 * primitive, so list membership drives access control.
 */
final class Brevo extends AbstractProvider implements SupportsLists, SupportsContactImport {

	private const PAGE_SIZE = 500;

	public function get_slug(): string {
		return 'brevo';
	}

	public function get_name(): string {
		return 'Brevo';
	}

	public function get_credential_fields(): array {
		return array(
			'api_key' => array(
				'label'    => __( 'API Key', 'conduit-crm' ),
				'type'     => 'password',
				'help'     => __( 'Brevo → SMTP & API → API keys (v3).', 'conduit-crm' ),
				'required' => true,
			),
		);
	}

	protected function base_url(): string {
		return 'https://api.brevo.com/v3';
	}

	protected function auth_headers(): array {
		return array(
			'api-key'      => $this->credential( 'api_key' ),
			'Content-Type' => 'application/json',
		);
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/account' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				__( 'Brevo rejected this API key.', 'conduit-crm' ),
				array( 'error' => $e->getMessage() )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		try {
			$response = $this->http()->get( '/contacts/' . rawurlencode( $email ) );
		} catch ( NotFoundException ) {
			return null;
		}

		$id = $response->path( 'id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$email = $fields['email'] ?? '';

		if ( ! is_string( $email ) || '' === $email ) {
			throw new CrmException( __( 'Brevo needs an email address to create a contact.', 'conduit-crm' ) );
		}

		unset( $fields['email'] );

		$response = $this->http()->post(
			'/contacts',
			array(
				'email'         => $email,
				'attributes'    => $this->to_attributes( $fields ),
				'updateEnabled' => true,
			)
		);

		$id = $response->path( 'id' );

		// updateEnabled returns 204 with no body when the contact already
		// existed, so fall back to a lookup.
		if ( ! is_scalar( $id ) ) {
			$id = $this->get_contact_id( $email );
		}

		if ( null === $id || '' === $id ) {
			throw new CrmException( __( 'Brevo did not return a contact ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$payload = array();

		if ( isset( $fields['email'] ) ) {
			$payload['email'] = $fields['email'];
			unset( $fields['email'] );
		}

		$attributes = $this->to_attributes( $fields );

		if ( ! empty( $attributes ) ) {
			$payload['attributes'] = $attributes;
		}

		if ( empty( $payload ) ) {
			return;
		}

		$this->http()->put( '/contacts/' . rawurlencode( $contact_id ), $payload );
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) );

		$contact = $response->json();
		$loaded  = array();

		if ( isset( $contact['email'] ) ) {
			$loaded['email'] = $contact['email'];
		}

		foreach ( $contact['attributes'] ?? array() as $key => $value ) {
			$loaded[ 'attr_' . $key ] = $value;
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

				$response = $this->http()->get( '/contacts/attributes' );

				foreach ( $response->json()['attributes'] ?? array() as $attribute ) {
					if ( ! isset( $attribute['name'] ) ) {
						continue;
					}

					$fields[ 'attr_' . $attribute['name'] ] = array(
						'label' => (string) $attribute['name'],
						'type'  => match ( strtolower( (string) ( $attribute['type'] ?? 'text' ) ) ) {
							'date' => 'date',
							'float', 'number' => 'number',
							'boolean' => 'checkbox',
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
	private function to_attributes( array $fields ): array {
		$attributes = array();

		foreach ( $fields as $key => $value ) {
			$name = str_starts_with( $key, 'attr_' ) ? substr( $key, 5 ) : $key;

			$attributes[ $name ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		return $attributes;
	}

	public function get_available_lists(): array {
		return $this->cached(
			'lists',
			function (): array {
				$lists  = array();
				$offset = 0;

				do {
					$response = $this->http()->get(
						'/contacts/lists',
						array(
							'limit'  => 50,
							'offset' => $offset,
						)
					);

					$batch = $response->json()['lists'] ?? array();

					foreach ( $batch as $list ) {
						if ( isset( $list['id'] ) ) {
							$lists[ (string) $list['id'] ] = (string) ( $list['name'] ?? $list['id'] );
						}
					}

					$offset += 50;
					$fetched = count( $batch );
				} while ( 50 === $fetched );

				return $lists;
			}
		);
	}

	public function add_to_lists( string $contact_id, array $list_ids ): void {
		$email = $this->email_for( $contact_id );

		foreach ( $list_ids as $list_id ) {
			$this->http()->post(
				sprintf( '/contacts/lists/%s/contacts/add', rawurlencode( $list_id ) ),
				array( 'emails' => array( $email ) )
			);
		}
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		$email = $this->email_for( $contact_id );

		foreach ( $list_ids as $list_id ) {
			$this->http()->post(
				sprintf( '/contacts/lists/%s/contacts/remove', rawurlencode( $list_id ) ),
				array( 'emails' => array( $email ) )
			);
		}
	}

	/**
	 * List membership endpoints take emails, not IDs.
	 */
	private function email_for( string $contact_id ): string {
		if ( is_email( $contact_id ) ) {
			return $contact_id;
		}

		$response = $this->http()->get( '/contacts/' . rawurlencode( $contact_id ) );

		$email = $response->path( 'email' );

		if ( ! is_string( $email ) ) {
			throw new CrmException( __( 'Could not resolve an email address for this Brevo contact.', 'conduit-crm' ) );
		}

		return $email;
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$offset = null === $cursor ? 0 : (int) $cursor;

		$path  = null === $tag_id ? '/contacts' : sprintf( '/contacts/lists/%s/contacts', rawurlencode( $tag_id ) );
		$query = array(
			'limit'  => self::PAGE_SIZE,
			'offset' => $offset,
		);

		$response = $this->http()->get( $path, $query );

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
