<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Provider;

use Switchyard\Crm\Contracts\SupportsContactImport;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Exception\CrmException;

/**
 * Zoho CRM v8.
 *
 * Zoho is region-partitioned: the API and accounts hosts both depend on the
 * data centre the account was created in, so that is a credential rather than
 * a constant.
 */
final class Zoho extends AbstractProvider implements SupportsTags, SupportsContactImport {

	use RefreshesOAuthToken;

	private const PAGE_SIZE = 200;
	private const MODULE    = 'Contacts';

	public function get_slug(): string {
		return 'zoho';
	}

	public function get_name(): string {
		return 'Zoho CRM';
	}

	public function get_credential_fields(): array {
		return array(
			'client_id'     => array(
				'label'    => __( 'Client ID', 'switchyard-crm' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Client Secret', 'switchyard-crm' ),
				'type'     => 'password',
				'required' => true,
			),
			'refresh_token' => array(
				'label'    => __( 'Refresh Token', 'switchyard-crm' ),
				'type'     => 'password',
				'required' => true,
			),
			'data_centre'   => array(
				'label'    => __( 'Data Centre', 'switchyard-crm' ),
				'type'     => 'select',
				'help'     => __( 'The domain suffix your Zoho account uses: com, eu, in, com.au, jp.', 'switchyard-crm' ),
				'required' => true,
			),
		);
	}

	private function data_centre(): string {
		$dc = $this->credential( 'data_centre', 'com' );

		return in_array( $dc, array( 'com', 'eu', 'in', 'com.au', 'jp', 'com.cn' ), true ) ? $dc : 'com';
	}

	protected function base_url(): string {
		return sprintf( 'https://www.zohoapis.%s/crm/v8', $this->data_centre() );
	}

	protected function token_url(): string {
		return sprintf( 'https://accounts.zoho.%s/oauth/v2/token', $this->data_centre() );
	}

	protected function auth_headers(): array {
		return array(
			'Authorization' => 'Zoho-oauthtoken ' . $this->access_token(),
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Headers carry a token that expires, so the cached client has to be
	 * rebuilt rather than reused across a refresh.
	 */
	protected function http(): \Switchyard\Crm\Http\Client {
		$this->http = null;

		return parent::http();
	}

	protected function verify_credentials(): void {
		try {
			$this->refresh_access_token();
			$this->http()->get( '/settings/modules' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'Zoho rejected these credentials. Check the client details, refresh token and data centre.', 'switchyard-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		$response = $this->http()->get( '/' . self::MODULE . '/search', array( 'email' => $email ) );

		$id = $response->path( 'data.0.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post(
			'/' . self::MODULE,
			array( 'data' => array( $this->with_required_name( $fields ) ) )
		);

		$id = $response->path( 'data.0.details.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'Zoho did not return a contact ID.', 'switchyard-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->http()->put(
			'/' . self::MODULE . '/' . rawurlencode( $contact_id ),
			array( 'data' => array( $fields ) )
		);
	}

	/**
	 * Last_Name is mandatory on the Contacts module, and a create without it
	 * fails with an error that reads as a permissions problem.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function with_required_name( array $fields ): array {
		if ( ! empty( $fields['Last_Name'] ) ) {
			return $fields;
		}

		$email = isset( $fields['Email'] ) && is_string( $fields['Email'] ) ? $fields['Email'] : '';

		$fields['Last_Name'] = '' !== $email ? (string) strstr( $email, '@', true ) : __( 'Unknown', 'switchyard-crm' );

		return $fields;
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/' . self::MODULE . '/' . rawurlencode( $contact_id ) );

		$record = $response->path( 'data.0', array() );

		if ( ! is_array( $record ) ) {
			return array();
		}

		unset( $record['Tag'] );

		return array_filter( $record, static fn( $v ) => null !== $v && ! is_array( $v ) );
	}

	public function get_available_fields(): array {
		return $this->cached(
			'fields',
			function (): array {
				$response = $this->http()->get( '/settings/fields', array( 'module' => self::MODULE ) );

				$fields = array();

				foreach ( $response->json()['fields'] ?? array() as $field ) {
					if ( ! isset( $field['api_name'] ) || ( $field['read_only'] ?? false ) ) {
						continue;
					}

					$fields[ (string) $field['api_name'] ] = array(
						'label' => (string) ( $field['field_label'] ?? $field['api_name'] ),
						'type'  => ( function () use ( $field ) {
							switch ( (string) ( $field['data_type'] ?? 'text' ) ) {
								case 'date':
								case 'datetime':
									return 'date';
								case 'integer':
								case 'double':
								case 'currency':
									return 'number';
								case 'picklist':
									return 'select';
								case 'multiselectpicklist':
									return 'multiselect';
								case 'boolean':
									return 'checkbox';
								case 'textarea':
									return 'textarea';
								default:
									return 'text';
							}
						} )(),
					);
				}

				return $fields;
			}
		);
	}

	public function get_available_tags(): array {
		return $this->cached(
			'tags',
			function (): array {
				$response = $this->http()->get(
					'/settings/tags',
					array(
						'module' => self::MODULE,
						'limit'  => self::PAGE_SIZE,
					)
				);

				$tags = array();

				foreach ( $response->json()['tags'] ?? array() as $tag ) {
					if ( isset( $tag['id'] ) ) {
						$tags[ (string) $tag['id'] ] = (string) ( $tag['name'] ?? $tag['id'] );
					}
				}

				return $tags;
			}
		);
	}

	public function get_tags( string $contact_id ): array {
		$response = $this->http()->get( '/' . self::MODULE . '/' . rawurlencode( $contact_id ) );

		$tags = array();

		$record_tags = $response->path( 'data.0.Tag', array() );

		foreach ( is_array( $record_tags ) ? $record_tags : array() as $tag ) {
			if ( isset( $tag['id'] ) ) {
				$tags[] = (string) $tag['id'];
			}
		}

		return $tags;
	}

	/**
	 * Zoho's tag actions address tags by name, so IDs are resolved first.
	 *
	 * @param list<string> $tag_ids
	 * @return list<string>
	 */
	private function tag_names( array $tag_ids ): array {
		$available = $this->get_available_tags();
		$names     = array();

		foreach ( $tag_ids as $id ) {
			if ( isset( $available[ (string) $id ] ) ) {
				$names[] = $available[ (string) $id ];
			}
		}

		return $names;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		$names = $this->tag_names( $tag_ids );

		if ( empty( $names ) ) {
			return;
		}

		$this->http()->post(
			sprintf( '/%s/%s/actions/add_tags', self::MODULE, rawurlencode( $contact_id ) ),
			array( 'tags' => array_map( static fn( string $n ): array => array( 'name' => $n ), $names ) )
		);
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		$names = $this->tag_names( $tag_ids );

		if ( empty( $names ) ) {
			return;
		}

		$this->http()->post(
			sprintf( '/%s/%s/actions/remove_tags', self::MODULE, rawurlencode( $contact_id ) ),
			array( 'tags' => array_map( static fn( string $n ): array => array( 'name' => $n ), $names ) )
		);
	}

	public function create_tag( string $label ): string {
		$response = $this->http()->post(
			'/settings/tags',
			array( 'tags' => array( array( 'name' => $label ) ) ),
		);

		$id = $response->path( 'tags.0.details.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'Zoho did not return a tag ID.', 'switchyard-crm' ) );
		}

		$this->flush_cache();

		return (string) $id;
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		$page = null === $cursor ? 1 : (int) $cursor;

		$response = $this->http()->get(
			'/' . self::MODULE,
			array(
				'per_page' => self::PAGE_SIZE,
				'page'     => $page,
				'fields'   => 'id,Email',
			)
		);

		$ids = array();

		foreach ( $response->json()['data'] ?? array() as $record ) {
			if ( isset( $record['id'] ) ) {
				$ids[] = (string) $record['id'];
			}
		}

		$more = (bool) $response->path( 'info.more_records', false );

		return array(
			'ids'    => $ids,
			'cursor' => $more ? (string) ( $page + 1 ) : null,
		);
	}
}
