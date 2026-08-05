<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use WP_User;

/**
 * Translates WordPress user data into the CRM's field shape.
 *
 * The map is stored as WordPress key => CRM field, because a WordPress key
 * can only sync to one CRM field but several WordPress keys legitimately map
 * onto the same CRM field on different integrations.
 */
final class FieldMap {

	public const OPTION = 'conduit_field_map';

	/** @var array<string, array{crm_field: string, type: string, active: bool}>|null */
	private ?array $map = null;

	/** @return array<string, array{crm_field: string, type: string, active: bool}> */
	public function all(): array {
		if ( null === $this->map ) {
			$stored = get_option( self::OPTION, array() );

			$this->map = is_array( $stored ) ? $stored : array();
		}

		return $this->map;
	}

	/** @param array<string, array{crm_field: string, type: string, active: bool}> $map */
	public function save( array $map ): void {
		$this->map = $map;

		update_option( self::OPTION, $map );
	}

	public function flush(): void {
		$this->map = null;
	}

	public function is_mapped( string $wp_key ): bool {
		$entry = $this->all()[ $wp_key ] ?? null;

		return null !== $entry && ! empty( $entry['active'] ) && '' !== $entry['crm_field'];
	}

	/**
	 * Every WordPress field available to map, grouped for the settings screen.
	 *
	 * Integrations add their own through the filter, which is how WooCommerce
	 * billing fields and LMS progress fields appear here.
	 *
	 * @return array<string, array<string, string>> Group label => key => label.
	 */
	public function available_wp_fields(): array {
		$fields = array(
			__( 'User', 'conduit-crm' ) => array(
				'user_email'      => __( 'Email', 'conduit-crm' ),
				'first_name'      => __( 'First Name', 'conduit-crm' ),
				'last_name'       => __( 'Last Name', 'conduit-crm' ),
				'display_name'    => __( 'Display Name', 'conduit-crm' ),
				'user_login'      => __( 'Username', 'conduit-crm' ),
				'user_url'        => __( 'Website', 'conduit-crm' ),
				'description'     => __( 'Biography', 'conduit-crm' ),
				'user_registered' => __( 'Registered Date', 'conduit-crm' ),
				'user_role'       => __( 'Role', 'conduit-crm' ),
				'user_id'         => __( 'User ID', 'conduit-crm' ),
			),
		);

		/** @var array<string, array<string, string>> $fields */
		$fields = apply_filters( 'conduit_wp_fields', $fields );

		return $fields;
	}

	/**
	 * Builds the CRM payload for a user.
	 *
	 * @param array<string, mixed> $overrides Values that take precedence over
	 *                                        what is stored on the user, used
	 *                                        by integrations syncing an event.
	 *
	 * @return array<string, mixed> Keyed by CRM field identifier.
	 */
	public function to_crm( int $user_id, array $overrides = array() ): array {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return array();
		}

		$payload = array();

		foreach ( $this->all() as $wp_key => $entry ) {
			if ( empty( $entry['active'] ) || '' === ( $entry['crm_field'] ?? '' ) ) {
				continue;
			}

			$value = array_key_exists( $wp_key, $overrides )
				? $overrides[ $wp_key ]
				: $this->read( $user, $wp_key );

			if ( null === $value || '' === $value ) {
				continue;
			}

			$payload[ $entry['crm_field'] ] = $this->coerce( $value, (string) ( $entry['type'] ?? 'text' ) );
		}

		/** @var array<string, mixed> $payload */
		$payload = apply_filters( 'conduit_sync_fields', $payload, $user_id, $overrides );

		return $payload;
	}

	/**
 * @return mixed
 */
	private function read( WP_User $user, string $key ) {
		switch ( $key ) {
			case 'user_email':
				return $user->user_email;
			case 'user_login':
				return $user->user_login;
			case 'user_url':
				return $user->user_url;
			case 'display_name':
				return $user->display_name;
			case 'user_registered':
				return $user->user_registered;
			case 'user_role':
				return $user->roles[0] ?? '';
			case 'user_id':
				return $user->ID;
			default:
				return get_user_meta( $user->ID, $key, true );
		}
	}

	/**
 * CRMs are strict about types in ways WordPress is not: a checkbox stored
 * as "1" has to become a boolean, and a date has to be ISO-8601 or the
 * write is rejected outright.
 * @param mixed $value
 * @return mixed
 */
	private function coerce( $value, string $type ) {
		if ( is_array( $value ) && 'multiselect' !== $type ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}

		switch ( $type ) {
			case 'date':
				return $this->to_date( $value );
			case 'number':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'checkbox':
				return (bool) $value;
			case 'multiselect':
				return is_array( $value ) ? array_values( $value ) : array( (string) $value );
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
 * @param mixed $value
 */
	private function to_date( $value ): string {
		if ( is_numeric( $value ) ) {
			return gmdate( 'c', (int) $value );
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	/**
	 * Applies CRM values back onto a WordPress user, used by webhooks and by
	 * contact import.
	 *
	 * @param array<string, mixed> $crm_data Keyed by CRM field identifier.
	 */
	public function to_wordpress( int $user_id, array $crm_data ): void {
		$user_fields = array();

		foreach ( $this->all() as $wp_key => $entry ) {
			if ( empty( $entry['active'] ) ) {
				continue;
			}

			$crm_field = (string) ( $entry['crm_field'] ?? '' );

			if ( '' === $crm_field || ! array_key_exists( $crm_field, $crm_data ) ) {
				continue;
			}

			$value = $crm_data[ $crm_field ];

			// The email and login are identity, not profile data. Changing
			// them from a webhook would let a CRM edit take over an account.
			if ( in_array( $wp_key, array( 'user_email', 'user_login', 'user_id', 'user_role' ), true ) ) {
				continue;
			}

			if ( in_array( $wp_key, array( 'display_name', 'user_url', 'description' ), true ) ) {
				$user_fields[ $wp_key ] = $value;

				continue;
			}

			update_user_meta( $user_id, $wp_key, $value );
		}

		if ( ! empty( $user_fields ) ) {
			$user_fields['ID'] = $user_id;

			wp_update_user( $user_fields );
		}
	}
}
