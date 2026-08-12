<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Provider;

use Switchyard\Crm\Contracts\SupportsEvents;
use Switchyard\Crm\Contracts\SupportsLists;
use Switchyard\Crm\Contracts\SupportsTracking;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Exception\CrmException;

/**
 * Klaviyo API.
 *
 * Klaviyo requires a dated revision header on every request, and models
 * membership as lists and segments rather than tags on a profile. Tag-based
 * access control therefore runs off list membership here, which is why this
 * provider implements SupportsLists and not SupportsTags.
 */
final class Klaviyo extends AbstractProvider implements SupportsLists, SupportsEvents, SupportsTracking {

	/**
	 * Klaviyo pins behaviour to a dated revision. Bumping this is a
	 * deliberate act, not something to drift with the calendar.
	 */
	private const REVISION = '2026-07-15';

	public function get_slug(): string {
		return 'klaviyo';
	}

	public function get_name(): string {
		return 'Klaviyo';
	}

	public function get_credential_fields(): array {
		return array(
			'api_key'    => array(
				'label'    => __( 'Private API Key', 'switchyard-crm' ),
				'type'     => 'password',
				'help'     => __( 'Settings → API keys. Needs read and write scopes for profiles and lists.', 'switchyard-crm' ),
				'required' => true,
			),
			'public_key' => array(
				'label'    => __( 'Public API Key (Site ID)', 'switchyard-crm' ),
				'type'     => 'text',
				'help'     => __( 'Optional. Needed for on-site tracking.', 'switchyard-crm' ),
				'required' => false,
			),
		);
	}

	protected function base_url(): string {
		return 'https://a.klaviyo.com/api';
	}

	protected function auth_headers(): array {
		return array(
			'Authorization' => 'Klaviyo-API-Key ' . $this->credential( 'api_key' ),
			'revision'      => (string) apply_filters( 'switchyard_klaviyo_revision', self::REVISION ),
			'Content-Type'  => 'application/json',
		);
	}

	protected function verify_credentials(): void {
		try {
			$this->http()->get( '/accounts/' );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'Klaviyo rejected this API key. Check that it has profile and list scopes.', 'switchyard-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}
	}

	public function get_contact_id( string $email ): ?string {
		$response = $this->http()->get(
			'/profiles/',
			array( 'filter' => sprintf( 'equals(email,"%s")', str_replace( '"', '', $email ) ) )
		);

		$id = $response->path( 'data.0.id' );

		return is_scalar( $id ) ? (string) $id : null;
	}

	public function add_contact( array $fields ): string {
		$response = $this->http()->post(
			'/profiles/',
			array(
				'data' => array(
					'type'       => 'profile',
					'attributes' => $this->to_attributes( $fields ),
				),
			)
		);

		$id = $response->path( 'data.id' );

		if ( ! is_scalar( $id ) ) {
			throw new CrmException( esc_html__( 'Klaviyo did not return a profile ID.', 'switchyard-crm' ) );
		}

		return (string) $id;
	}

	public function update_contact( string $contact_id, array $fields ): void {
		$this->http()->patch(
			'/profiles/' . rawurlencode( $contact_id ) . '/',
			array(
				'data' => array(
					'type'       => 'profile',
					'id'         => $contact_id,
					'attributes' => $this->to_attributes( $fields ),
				),
			)
		);
	}

	public function load_contact( string $contact_id ): array {
		$response = $this->http()->get( '/profiles/' . rawurlencode( $contact_id ) . '/' );

		$attributes = $response->path( 'data.attributes', array() );

		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$loaded = array();

		foreach ( array( 'email', 'phone_number', 'first_name', 'last_name', 'organization', 'title' ) as $native ) {
			if ( isset( $attributes[ $native ] ) ) {
				$loaded[ $native ] = $attributes[ $native ];
			}
		}

		foreach ( $attributes['properties'] ?? array() as $key => $value ) {
			$loaded[ 'prop_' . $key ] = $value;
		}

		return $loaded;
	}

	/**
	 * Klaviyo has no custom-field schema endpoint: custom properties are
	 * created on write. The native attributes are enumerated, and the admin
	 * screen allows adding arbitrary property keys.
	 */
	public function get_available_fields(): array {
		return array(
			'email'        => array(
				'label' => __( 'Email', 'switchyard-crm' ),
				'type'  => 'email',
			),
			'first_name'   => array(
				'label' => __( 'First Name', 'switchyard-crm' ),
				'type'  => 'text',
			),
			'last_name'    => array(
				'label' => __( 'Last Name', 'switchyard-crm' ),
				'type'  => 'text',
			),
			'phone_number' => array(
				'label' => __( 'Phone Number', 'switchyard-crm' ),
				'type'  => 'text',
			),
			'organization' => array(
				'label' => __( 'Organization', 'switchyard-crm' ),
				'type'  => 'text',
			),
			'title'        => array(
				'label' => __( 'Title', 'switchyard-crm' ),
				'type'  => 'text',
			),
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function to_attributes( array $fields ): array {
		$attributes = array();
		$properties = array();

		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( $key, 'prop_' ) ) {
				$properties[ substr( $key, 5 ) ] = $value;

				continue;
			}

			$attributes[ $key ] = $value;
		}

		if ( ! empty( $properties ) ) {
			$attributes['properties'] = $properties;
		}

		return $attributes;
	}

	public function get_available_lists(): array {
		return $this->cached(
			'lists',
			function (): array {
				$lists  = array();
				$cursor = null;

				do {
					$response = $this->http()->get( '/lists/', array( 'page[cursor]' => $cursor ) );

					foreach ( $response->json()['data'] ?? array() as $list ) {
						if ( isset( $list['id'] ) ) {
							$lists[ (string) $list['id'] ] = (string) ( $list['attributes']['name'] ?? $list['id'] );
						}
					}

					$next   = $response->path( 'links.next' );
					$cursor = is_string( $next ) ? $this->cursor_from_link( $next ) : null;
				} while ( null !== $cursor );

				return $lists;
			}
		);
	}

	private function cursor_from_link( string $link ): ?string {
		$query = wp_parse_url( $link, PHP_URL_QUERY );

		if ( ! is_string( $query ) ) {
			return null;
		}

		parse_str( $query, $parsed );

		$cursor = $parsed['page']['cursor'] ?? null;

		return is_string( $cursor ) ? $cursor : null;
	}

	public function add_to_lists( string $contact_id, array $list_ids ): void {
		foreach ( $list_ids as $list_id ) {
			$this->http()->post(
				sprintf( '/lists/%s/relationships/profiles/', rawurlencode( $list_id ) ),
				array(
					'data' => array(
						array(
							'type' => 'profile',
							'id'   => $contact_id,
						),
					),
				)
			);
		}
	}

	public function remove_from_lists( string $contact_id, array $list_ids ): void {
		foreach ( $list_ids as $list_id ) {
			$this->http()->delete(
				sprintf( '/lists/%s/relationships/profiles/', rawurlencode( $list_id ) ),
				array(
					'data' => array(
						array(
							'type' => 'profile',
							'id'   => $contact_id,
						),
					),
				)
			);
		}
	}

	public function track_event( string $contact_id, string $event, array $data = array() ): void {
		$email = isset( $data['email'] ) && is_string( $data['email'] ) ? $data['email'] : '';
		unset( $data['email'] );

		$profile = array( 'type' => 'profile' );

		if ( '' !== $email ) {
			$profile['attributes'] = array( 'email' => $email );
		} else {
			$profile['id'] = $contact_id;
		}

		$this->http()->post(
			'/events/',
			array(
				'data' => array(
					'type'       => 'event',
					'attributes' => array(
						'properties' => $data,
						'metric'     => array(
							'data' => array(
								'type'       => 'metric',
								'attributes' => array( 'name' => $event ),
							),
						),
						'profile'    => array( 'data' => $profile ),
					),
				),
			)
		);
	}

	public function get_tracking_src(): string {
		$public = $this->credential( 'public_key' );

		if ( '' === $public ) {
			return '';
		}

		return 'https://static.klaviyo.com/onsite/js/klaviyo.js?company_id=' . rawurlencode( $public );
	}

	public function get_tracking_inline(): string {
		return '';
	}

	public function get_identify_inline( string $email, ?string $contact_id ): string {
		if ( '' === $this->credential( 'public_key' ) || '' === $email ) {
			return '';
		}

		return sprintf(
			'var _learnq=window._learnq=window._learnq||[];_learnq.push(["identify",{"$email":%s}]);',
			(string) wp_json_encode( $email )
		);
	}
}
