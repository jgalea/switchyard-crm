<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Provider;

use Switchyard\Crm\Contracts\SupportsContactImport;
use Switchyard\Crm\Contracts\SupportsTags;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Exception\CrmException;

/**
 * Salesforce, via the REST API on a refresh-token OAuth connection.
 *
 * Salesforce has no tag primitive on Contact. The convention here matches
 * what the category already does: a multi-select picklist field holds the
 * tags, and its API name is configurable because orgs name it differently.
 */
final class Salesforce extends AbstractProvider implements SupportsTags, SupportsContactImport {

	private const PAGE_SIZE        = 200;
	private const OBJECT           = 'Contact';
	private const FALLBACK_VERSION = 'v62.0';

	use RefreshesOAuthToken;

	public function get_slug(): string {
		return 'salesforce';
	}

	public function get_name(): string {
		return 'Salesforce';
	}

	public function get_credential_fields(): array {
		return array(
			'client_id'     => array(
				'label'    => __( 'Consumer Key', 'switchyard-crm' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Consumer Secret', 'switchyard-crm' ),
				'type'     => 'password',
				'required' => true,
			),
			'refresh_token' => array(
				'label'    => __( 'Refresh Token', 'switchyard-crm' ),
				'type'     => 'password',
				'required' => true,
			),
			'instance_url'  => array(
				'label'    => __( 'Instance URL', 'switchyard-crm' ),
				'type'     => 'url',
				'help'     => __( 'For example https://yourcompany.my.salesforce.com', 'switchyard-crm' ),
				'required' => true,
			),
			'sandbox'       => array(
				'label'    => __( 'Sandbox org', 'switchyard-crm' ),
				'type'     => 'checkbox',
				'required' => false,
			),
			'tag_field'     => array(
				'label'    => __( 'Tag Field API Name', 'switchyard-crm' ),
				'type'     => 'text',
				'help'     => __( 'A multi-select picklist on Contact that holds tags. Defaults to Switchyard_Tags__c.', 'switchyard-crm' ),
				'required' => false,
			),
		);
	}

	protected function token_url(): string {
		$host = $this->credential( 'sandbox' ) ? 'test' : 'login';

		return sprintf( 'https://%s.salesforce.com/services/oauth2/token', $host );
	}

	/**
	 * Salesforce versions its REST API three times a year. Asking the org
	 * which versions it serves avoids pinning to one that ages out.
	 */
	private function api_version(): string {
		$cached = get_transient( 'switchyard_sf_version' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->client_factory
				->for_provider( $this->get_slug() )
				->base_url( rtrim( $this->credential( 'instance_url' ), '/' ) )
				->headers( array( 'Authorization' => 'Bearer ' . $this->access_token() ) )
				->get( '/services/data/' );

			$versions = $response->json();
			$latest   = end( $versions );

			$version = is_array( $latest ) && isset( $latest['version'] )
				? 'v' . $latest['version']
				: self::FALLBACK_VERSION;
		} catch ( CrmException $exception ) {
			$version = self::FALLBACK_VERSION;
		}

		set_transient( 'switchyard_sf_version', $version, WEEK_IN_SECONDS );

		return $version;
	}

	protected function base_url(): string {
		return sprintf(
			'%s/services/data/%s',
			rtrim( $this->credential( 'instance_url' ), '/' ),
			$this->api_version()
		);
	}

	protected function auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->access_token(),
			'Content-Type'  => 'application/json',
		);
	}

	protected function http(): \Switchyard\Crm\Http\Client {
		$this->http = null;

		return parent::http();
	}

	protected function verify_credentials(): void {
		try {
			$this->refresh_access_token();
			$this->http()->get( '/sobjects/' . self::OBJECT . '/describe' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'Salesforce rejected these credentials. Check the connected app details and instance URL.', 'switchyard-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	private function tag_field(): string {
		$field = $this->credential( 'tag_field' );

		return '' !== $field ? $field : 'Switchyard_Tags__c';
	}

	/**
	 * SOQL has no parameter binding over REST, so literals are escaped by
	 * hand. Backslash first, then the quote, or the escape gets escaped.
	 */
	private function escape_soql( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}

	public function get_contact_id( string $email ): ?string {
		$soql = sprintf(
			"SELECT Id FROM %s WHERE Email = '%s' LIMIT 1",
			self::OBJECT,
			$this->escape_soql( $email )
		);

		$response = $this->http()->get( '/query', array( 'q' => $soql ) );

		$id = $response->path( 'records.0.Id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post( '/sobjects/' . self::OBJECT, $this->with_required_name( $fields ) );

		$id = $response->path( 'id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'Salesforce did not return a record ID.', 'switchyard-crm' ) );
		}

		return (string) $id;
	}

	/**
	 * LastName is required on Contact.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function with_required_name( array $fields ): array {
		if ( ! empty( $fields['LastName'] ) ) {
			return $fields;
		}

		$email = isset( $fields['Email'] ) && is_string( $fields['Email'] ) ? $fields['Email'] : '';

		$fields['LastName'] = '' !== $email ? (string) strstr( $email, '@', true ) : __( 'Unknown', 'switchyard-crm' );

		return $fields;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		// Salesforce rejects an update that includes the record's own Id.
		unset( $fields['Id'] );

		$this->http()->patch( '/sobjects/' . self::OBJECT . '/' . rawurlencode( $contact_id ), $fields );
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/sobjects/' . self::OBJECT . '/' . rawurlencode( $contact_id ) );

		$record = $response->json();

		unset( $record['attributes'] );

		return array_filter( $record, static fn( $v ) => null !== $v && ! is_array( $v ) );
	}

	public function get_available_fields(): array {
		return $this->cached(
			'fields',
			function (): array {
				$response = $this->http()->get( '/sobjects/' . self::OBJECT . '/describe' );

				$fields = array();

				foreach ( $response->json()['fields'] ?? array() as $field ) {
					if ( ! isset( $field['name'] ) || ! ( $field['updateable'] ?? false ) ) {
						continue;
					}

					$fields[ (string) $field['name'] ] = array(
						'label' => (string) ( $field['label'] ?? $field['name'] ),
						'type'  => ( function () use ( $field ) {
							switch ( (string) ( $field['type'] ?? 'string' ) ) {
								case 'date':
								case 'datetime':
									return 'date';
								case 'int':
								case 'double':
								case 'currency':
								case 'percent':
									return 'number';
								case 'picklist':
									return 'select';
								case 'multipicklist':
									return 'multiselect';
								case 'boolean':
									return 'checkbox';
								case 'textarea':
									return 'textarea';
								case 'email':
									return 'email';
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

	/**
	 * Tags are the picklist values defined on the configured field.
	 */
	public function get_available_tags(): array {
		return $this->cached(
			'tags',
			function (): array {
				$response = $this->http()->get( '/sobjects/' . self::OBJECT . '/describe' );

				foreach ( $response->json()['fields'] ?? array() as $field ) {
					if ( ( $field['name'] ?? '' ) !== $this->tag_field() ) {
						continue;
					}

					$tags = array();

					foreach ( $field['picklistValues'] ?? array() as $value ) {
						if ( isset( $value['value'] ) && ( $value['active'] ?? true ) ) {
							$tags[ (string) $value['value'] ] = (string) ( $value['label'] ?? $value['value'] );
						}
					}

					return $tags;
				}

				return array();
			}
		);
	}

	public function get_tags( string $contact_id ): array {
		$soql = sprintf(
			'SELECT %s FROM %s WHERE Id = %s LIMIT 1',
			$this->tag_field(),
			self::OBJECT,
			"'" . $this->escape_soql( $contact_id ) . "'"
		);

		try {
			$response = $this->http()->get( '/query', array( 'q' => $soql ) );
		} catch ( CrmException $exception ) {
			// The org has not created the tag field yet.
			return array();
		}

		$raw = $response->path( 'records.0.' . $this->tag_field() );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ';', $raw ) ) ) );
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		$this->write_tags( $contact_id, array_unique( array_merge( $this->get_tags( $contact_id ), $tag_ids ) ) );
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		$this->write_tags( $contact_id, array_diff( $this->get_tags( $contact_id ), $tag_ids ) );
	}

	/**
	 * Multi-select picklists are stored as a semicolon-delimited string, so
	 * every write is a full replacement of the set.
	 *
	 * @param list<string> $tags
	 */
	private function write_tags( string $contact_id, array $tags ): void {
		$this->update_contact( $contact_id, array( $this->tag_field() => implode( ';', array_values( $tags ) ) ) );
	}

	/**
	 * Picklist values are org metadata, not data, so they cannot be created
	 * through the REST data API.
	 */
	public function create_tag( string $label ): string {
		throw new CrmException(
			esc_html__( 'Salesforce tags are picklist values. Add the value to the tag field in Setup, then refresh the tag list.', 'switchyard-crm' )
		);
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		if ( null !== $cursor ) {
			// Salesforce hands back a full path to the next batch.
			$response = $this->http()->get( $cursor );
		} else {
			$soql = sprintf( 'SELECT Id FROM %s', self::OBJECT );

			if ( null !== $tag_id ) {
				$soql .= sprintf(
					" WHERE %s INCLUDES ('%s')",
					$this->tag_field(),
					$this->escape_soql( $tag_id )
				);
			}

			$soql .= sprintf( ' LIMIT %d', self::PAGE_SIZE );

			$response = $this->http()->get( '/query', array( 'q' => $soql ) );
		}

		$ids = array();

		foreach ( $response->json()['records'] ?? array() as $record ) {
			if ( isset( $record['Id'] ) ) {
				$ids[] = (string) $record['Id'];
			}
		}

		$next = $response->path( 'nextRecordsUrl' );

		return array(
			'ids'    => $ids,
			'cursor' => is_string( $next ) ? $this->credential( 'instance_url' ) . $next : null,
		);
	}
}
