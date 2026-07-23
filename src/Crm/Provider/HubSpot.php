<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Contracts\SupportsContactImport;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Contracts\SupportsTracking;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Exception\NotFoundException;

/**
 * HubSpot CRM API v3, authenticated with a private app token.
 *
 * HubSpot has no tag primitive. Static lists are the closest equivalent and
 * are what this provider exposes as tags, which matches how HubSpot users
 * actually segment. Behavioural events are Enterprise-only, so SupportsEvents
 * is deliberately not implemented.
 */
final class HubSpot extends AbstractProvider implements SupportsTags, SupportsTracking, SupportsContactImport {

	private const PAGE_SIZE = 100;

	public function get_slug(): string {
		return 'hubspot';
	}

	public function get_name(): string {
		return 'HubSpot';
	}

	public function get_credential_fields(): array {
		return array(
			'access_token' => array(
				'label'    => __( 'Private App Token', 'conduit-crm' ),
				'type'     => 'password',
				'help'     => __( 'Settings → Integrations → Private Apps. Needs CRM contacts and lists scopes.', 'conduit-crm' ),
				'required' => true,
			),
			'portal_id'    => array(
				'label'    => __( 'Portal ID', 'conduit-crm' ),
				'type'     => 'text',
				'help'     => __( 'Optional. Needed for the tracking code.', 'conduit-crm' ),
				'required' => false,
			),
		);
	}

	protected function base_url(): string {
		return 'https://api.hubapi.com';
	}

	protected function auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->credential( 'access_token' ),
			'Content-Type'  => 'application/json',
		);
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/crm/v3/objects/contacts', array( 'limit' => 1 ) );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				__( 'HubSpot rejected this token. Check that the private app has CRM contact scopes.', 'conduit-crm' ),
				array( 'error' => $e->getMessage() )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		$response = $this->http()->post(
			'/crm/v3/objects/contacts/search',
			array(
				'filterGroups' => array(
					array(
						'filters' => array(
							array(
								'propertyName' => 'email',
								'operator'     => 'EQ',
								'value'        => $email,
							),
						),
					),
				),
				'limit'        => 1,
				'properties'   => array( 'email' ),
			)
		);

		$id = $response->path( 'results.0.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post( '/crm/v3/objects/contacts', array( 'properties' => $fields ) );

		$id = $response->path( 'id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( __( 'HubSpot did not return a contact ID.', 'conduit-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->http()->patch(
			'/crm/v3/objects/contacts/' . rawurlencode( $contact_id ),
			array( 'properties' => $fields )
		);
	}

	public function load_contact( string $contact_id ): array {
		$properties = array_keys( $this->get_available_fields() );

		$response = $this->http()->get(
			'/crm/v3/objects/contacts/' . rawurlencode( $contact_id ),
			array( 'properties' => implode( ',', $properties ) )
		);

		$loaded = $response->path( 'properties', array() );

		return is_array( $loaded ) ? array_filter( $loaded, static fn( $v ) => null !== $v ) : array();
	}

	public function get_available_fields(): array {
		return $this->cached(
			'fields',
			function (): array {
				$response = $this->http()->get( '/crm/v3/properties/contacts' );

				$fields = array();

				foreach ( $response->json()['results'] ?? array() as $property ) {
					if ( ! isset( $property['name'] ) || ( $property['calculated'] ?? false ) ) {
						continue;
					}

					$fields[ (string) $property['name'] ] = array(
						'label' => (string) ( $property['label'] ?? $property['name'] ),
						'type'  => match ( (string) ( $property['fieldType'] ?? 'text' ) ) {
							'date' => 'date',
							'number' => 'number',
							'select', 'radio' => 'select',
							'checkbox', 'booleancheckbox' => 'checkbox',
							'textarea' => 'textarea',
							default => 'text',
						},
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
				$response = $this->http()->post(
					'/crm/v3/lists/search',
					array(
						'processingTypes' => array( 'MANUAL', 'SNAPSHOT' ),
						'count'           => self::PAGE_SIZE,
					)
				);

				$tags = array();

				foreach ( $response->json()['lists'] ?? array() as $list ) {
					if ( isset( $list['listId'] ) ) {
						$tags[ (string) $list['listId'] ] = (string) ( $list['name'] ?? $list['listId'] );
					}
				}

				return $tags;
			}
		);
	}

	/**
	 * HubSpot has no endpoint returning a record's list memberships, so this
	 * asks each manual list whether it contains the contact. Kept bounded by
	 * only checking lists the site has actually mapped.
	 */
	public function get_tags( string $contact_id ): array {
		$tags = array();

		// Cast because PHP silently turns numeric string array keys into
		// integers, and HubSpot list IDs are always numeric.
		foreach ( array_map( 'strval', array_keys( $this->get_available_tags() ) ) as $list_id ) {
			try {
				$response = $this->http()->get(
					sprintf( '/crm/v3/lists/%s/memberships', rawurlencode( $list_id ) ),
					array( 'limit' => self::PAGE_SIZE )
				);
			} catch ( CrmException ) {
				continue;
			}

			foreach ( $response->json()['results'] ?? array() as $member ) {
				if ( isset( $member['recordId'] ) && (string) $member['recordId'] === $contact_id ) {
					$tags[] = (string) $list_id;

					break;
				}
			}
		}

		return $tags;
	}

	public function apply_tags( string $contact_id, array $tag_ids ): void {
		foreach ( $tag_ids as $tag_id ) {
			$this->http()->put(
				sprintf( '/crm/v3/lists/%s/memberships/add', rawurlencode( $tag_id ) ),
				array( $contact_id )
			);
		}
	}

	public function remove_tags( string $contact_id, array $tag_ids ): void {
		foreach ( $tag_ids as $tag_id ) {
			$this->http()->put(
				sprintf( '/crm/v3/lists/%s/memberships/remove', rawurlencode( $tag_id ) ),
				array( $contact_id )
			);
		}
	}

	public function create_tag( string $label ): string {
		$response = $this->http()->post(
			'/crm/v3/lists',
			array(
				'name'           => $label,
				'objectTypeId'   => '0-1',
				'processingType' => 'MANUAL',
			)
		);

		$id = $response->path( 'list.listId' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( __( 'HubSpot did not return a list ID.', 'conduit-crm' ) );
		}

		$this->flush_cache();

		return (string) $id;
	}

	/**
	 * Returns markup for the caller to print. It is not enqueued because the
	 * vendor snippet must load inline in the head, before any other script.
	 *
	 * phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	 */
	public function get_tracking_script(): string {
		$portal = $this->credential( 'portal_id' );

		if ( '' === $portal ) {
			return '';
		}

		return sprintf(
			'<script type="text/javascript" id="hs-script-loader" async defer src="https://js.hs-scripts.com/%s.js"></script>',
			rawurlencode( $portal )
		);
	}

	public function get_identify_script( string $email, ?string $contact_id ): string {
		if ( '' === $this->credential( 'portal_id' ) || '' === $email ) {
			return '';
		}

		return sprintf(
			'<script>var _hsq=window._hsq=window._hsq||[];_hsq.push(["identify",{email:"%s"}]);_hsq.push(["trackPageView"]);</script>',
			esc_js( $email )
		);
	}

	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array {
		if ( null !== $tag_id ) {
			$response = $this->http()->get(
				sprintf( '/crm/v3/lists/%s/memberships', rawurlencode( $tag_id ) ),
				array(
					'limit' => self::PAGE_SIZE,
					'after' => $cursor,
				)
			);

			$ids = array();

			foreach ( $response->json()['results'] ?? array() as $member ) {
				if ( isset( $member['recordId'] ) ) {
					$ids[] = (string) $member['recordId'];
				}
			}
		} else {
			$response = $this->http()->get(
				'/crm/v3/objects/contacts',
				array(
					'limit' => self::PAGE_SIZE,
					'after' => $cursor,
				)
			);

			$ids = array();

			foreach ( $response->json()['results'] ?? array() as $contact ) {
				if ( isset( $contact['id'] ) ) {
					$ids[] = (string) $contact['id'];
				}
			}
		}

		$next = $response->path( 'paging.next.after' );

		return array(
			'ids'    => $ids,
			'cursor' => is_scalar( $next ) ? (string) $next : null,
		);
	}

	// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
}
