<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Exception\NotFoundException;

/**
 * Mailchimp Marketing API v3.
 *
 * Mailchimp scopes members to an audience, so an audience ID is part of the
 * credentials rather than an optional setting. Tags are addressed by name,
 * not ID, which is why the tag identifiers here are names.
 */
final class Mailchimp extends AbstractProvider implements SupportsTags, SupportsLists, SupportsContactImport {

	private const PAGE_SIZE = 1000;

	public function get_slug(): string {
		return 'mailchimp';
	}

	public function get_name(): string {
		return 'Mailchimp';
	}

	public function get_credential_fields(): array {
		return array(
			'api_key'      => array(
				'label'    => __( 'API Key', 'conduit-crm' ),
				'type'     => 'password',
				'help'     => __( 'Account → Extras → API keys. The data centre is read from the key suffix.', 'conduit-crm' ),
				'required' => true,
			),
			'audience_id'  => array(
				'label'    => __( 'Audience ID', 'conduit-crm' ),
				'type'     => 'text',
				'help'     => __( 'Audience → Settings → Audience name and defaults.', 'conduit-crm' ),
				'required' => true,
			),
			'double_optin' => array(
				'label'    => __( 'Require double opt-in', 'conduit-crm' ),
				'type'     => 'checkbox',
				'required' => false,
			),
		);
	}

	protected function base_url(): string {
		return sprintf( 'https://%s.api.mailchimp.com/3.0', $this->data_centre() );
	}

	/**
	 * Mailchimp keys carry their data centre after the final hyphen.
	 *
	 * The suffix is interpolated into the API hostname, so it is validated
	 * strictly rather than trusted. A key ending in something like
	 * "evil.example/x" would otherwise produce a base URL whose host is
	 * evil.example, sending the Basic auth header, and therefore the key
	 * itself, to an attacker.
	 */
	private function data_centre(): string {
		$key   = $this->credential( 'api_key' );
		$parts = explode( '-', $key );
		$dc    = end( $parts );

		if ( ! is_string( $dc ) || $dc === $key ) {
			return 'us1';
		}

		return 1 === preg_match( '/^[a-z]{2,4}[0-9]{1,3}$/', $dc ) ? $dc : 'us1';
	}

	protected function auth_headers(): array {
		return array(
			// Mailchimp uses HTTP Basic auth, so this encoding is the wire
			// format the API requires, not obfuscation.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Authorization' => 'Basic ' . base64_encode( 'conduit:' . $this->credential( 'api_key' ) ),
		);
	}

	private function audience(): string {
		return $this->credential( 'audience_id' );
	}

	private function member_path( string $email ): string {
		return sprintf(
			'/lists/%s/members/%s',
			rawurlencode( $this->audience() ),
			md5( strtolower( trim( $email ) ) )
		);
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/lists/' . rawurlencode( $this->audience() ) );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'Mailchimp rejected these credentials, or the audience ID is wrong.', 'conduit-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	/**
	 * Mailchimp addresses members by a hash of their email, so the contact ID
	 * is derivable without a lookup. The request still runs, because callers
	 * rely on null meaning "not in the CRM".
	 */
	public function get_contact_id( string $email ): ?string {
		try {
			$response = $this->http()->get( $this->member_path( $email ) );
		} catch ( NotFoundException ) {
			return null;
		}

		$id = $response->path( 'id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$email = $this->extract_email( $fields );

		$payload                  = $this->to_payload( $fields );
		$payload['status_if_new'] = $this->credential( 'double_optin' ) ? 'pending' : 'subscribed';
		$payload['email_address'] = $email;

		$response = $this->http()->put( $this->member_path( $email ), $payload );

		$id = $response->path( 'id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'Mailchimp did not return a member ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	/**
	 * The member hash is derived from the email, so an email change means the
	 * old record has to be addressed by its existing hash.
	 */
	public function update_contact( string $contact_id, array $fields ): void {
		$path = sprintf( '/lists/%s/members/%s', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) );

		$this->http()->patch( $path, $this->to_payload( $fields ) );
	}

	public function load_contact( string $contact_id ): array {
		$path     = sprintf( '/lists/%s/members/%s', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) );
		$response = $this->http()->get( $path );

		$member = $response->json();
		$loaded = array();

		if ( isset( $member['email_address'] ) ) {
			$loaded['email_address'] = $member['email_address'];
		}

		foreach ( $member['merge_fields'] ?? array() as $tag => $value ) {
			$loaded[ 'merge_' . $tag ] = $value;
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
				);

				$response = $this->http()->get(
					sprintf( '/lists/%s/merge-fields', rawurlencode( $this->audience() ) ),
					array( 'count' => 100 )
				);

				foreach ( $response->json()['merge_fields'] ?? array() as $field ) {
					if ( ! isset( $field['tag'] ) ) {
						continue;
					}

					$fields[ 'merge_' . $field['tag'] ] = array(
						'label' => (string) ( $field['name'] ?? $field['tag'] ),
						'type'  => match ( (string) ( $field['type'] ?? 'text' ) ) {
							'date', 'birthday' => 'date',
							'number' => 'number',
							'dropdown', 'radio' => 'select',
							default => 'text',
						},
					);
				}

				return $fields;
			}
		);
	}

	/** @param array<string, mixed> $fields */
	private function extract_email( array $fields ): string {
		$email = $fields['email_address'] ?? '';

		if ( ! is_string( $email ) || '' === $email ) {
			throw new CrmException( esc_html__( 'Mailchimp needs an email address to identify a member.', 'conduit-crm' ) );
		}

		return $email;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function to_payload( array $fields ): array {
		$payload = array();
		$merge   = array();

		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( $key, 'merge_' ) ) {
				$merge[ substr( $key, 6 ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;

				continue;
			}

			$payload[ $key ] = $value;
		}

		if ( ! empty( $merge ) ) {
			$payload['merge_fields'] = $merge;
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
						sprintf( '/lists/%s/segments', rawurlencode( $this->audience() ) ),
						array(
							'type'   => 'static',
							'count'  => 100,
							'offset' => $offset,
						)
					);

					$batch = $response->json()['segments'] ?? array();

					foreach ( $batch as $segment ) {
						if ( isset( $segment['name'] ) ) {
							// Keyed by name because the member tag endpoints
							// address tags by name, not by segment ID.
							$tags[ (string) $segment['name'] ] = (string) $segment['name'];
						}
					}

					$offset += 100;
					$fetched = count( $batch );
				} while ( 100 === $fetched );

				return $tags;
			}
		);
	}

	public function get_tags( string $contact_id ): array {
		$path     = sprintf( '/lists/%s/members/%s/tags', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) );
		$response = $this->http()->get( $path, array( 'count' => 100 ) );

		$tags = array();

		foreach ( $response->json()['tags'] ?? array() as $tag ) {
			if ( isset( $tag['name'] ) ) {
				$tags[] = (string) $tag['name'];
			}
		}

		return $tags;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		$this->write_tags( $contact_id, $tag_ids, 'active' );
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		$this->write_tags( $contact_id, $tag_ids, 'inactive' );
	}

	/** @param list<string> $tag_ids */
	private function write_tags( string $contact_id, array $tag_ids, string $status ): void {
		if ( empty( $tag_ids ) ) {
			return;
		}

		$path = sprintf( '/lists/%s/members/%s/tags', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) );

		$this->http()->post(
			$path,
			array(
				'tags' => array_map(
					static fn( string $name ): array => array(
						'name'   => $name,
						'status' => $status,
					),
					array_values( $tag_ids )
				),
			)
		);
	}

	/**
	 * Mailchimp creates a tag implicitly the first time it is applied, so
	 * there is nothing to create and the name is the identifier.
	 */
	public function create_tag( string $label ): string {
		$this->flush_cache();

		return $label;
	}

	public function get_available_lists(): array {
		return $this->cached(
			'lists',
			function (): array {
				$response = $this->http()->get( '/lists', array( 'count' => 100 ) );

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

	/**
	 * Audiences are separate billing entities in Mailchimp, so moving a
	 * member between them means subscribing them to the target audience.
	 */
	public function add_to_lists( string $contact_id, array $list_ids ): void {
		$member = $this->http()->get(
			sprintf( '/lists/%s/members/%s', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) )
		);

		$email = $member->path( 'email_address' );

		if ( ! is_string( $email ) ) {
			return;
		}

		foreach ( $list_ids as $list_id ) {
			$this->http()->put(
				sprintf( '/lists/%s/members/%s', rawurlencode( $list_id ), md5( strtolower( $email ) ) ),
				array(
					'email_address' => $email,
					'status_if_new' => 'subscribed',
				)
			);
		}
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		$member = $this->http()->get(
			sprintf( '/lists/%s/members/%s', rawurlencode( $this->audience() ), rawurlencode( $contact_id ) )
		);

		$email = $member->path( 'email_address' );

		if ( ! is_string( $email ) ) {
			return;
		}

		foreach ( $list_ids as $list_id ) {
			$this->http()->patch(
				sprintf( '/lists/%s/members/%s', rawurlencode( $list_id ), md5( strtolower( $email ) ) ),
				array( 'status' => 'unsubscribed' )
			);
		}
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$offset = null === $cursor ? 0 : (int) $cursor;

		$query = array(
			'count'  => self::PAGE_SIZE,
			'offset' => $offset,
			'fields' => 'members.id',
		);

		$response = $this->http()->get(
			sprintf( '/lists/%s/members', rawurlencode( $this->audience() ) ),
			$query
		);

		$ids = array();

		foreach ( $response->json()['members'] ?? array() as $member ) {
			if ( isset( $member['id'] ) ) {
				$ids[] = (string) $member['id'];
			}
		}

		return array(
			'ids'    => $ids,
			'cursor' => count( $ids ) === self::PAGE_SIZE ? (string) ( $offset + self::PAGE_SIZE ) : null,
		);
	}
}
