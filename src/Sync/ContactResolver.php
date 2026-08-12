<?php

declare( strict_types=1 );

namespace Switchyard\Sync;

use Switchyard\Crm\Exception\CrmException;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use WP_User;

/**
 * Maps a WordPress user to a CRM contact and remembers the answer.
 *
 * The cached ID is the difference between one API call per pageview and none,
 * so it is written on every successful lookup and cleared whenever the user's
 * email changes.
 */
final class ContactResolver {

	private ProviderRegistry $registry;
	private Logger $logger;
	public const META_CONTACT_ID = 'switchyard_contact_id';

	public function __construct( ProviderRegistry $registry, Logger $logger ) {
		$this->registry = $registry;
		$this->logger   = $logger;
	}

	public function cached_id( int $user_id ): ?string {
		$id = get_user_meta( $user_id, self::META_CONTACT_ID, true );

		return is_string( $id ) && '' !== $id ? $id : null;
	}

	public function store_id( int $user_id, string $contact_id ): void {
		update_user_meta( $user_id, self::META_CONTACT_ID, $contact_id );
	}

	public function forget( int $user_id ): void {
		delete_user_meta( $user_id, self::META_CONTACT_ID );
	}

	/**
	 * Returns the CRM contact ID for a user, looking it up if necessary.
	 */
	public function resolve( int $user_id, bool $refresh = false ): ?string {
		if ( ! $refresh ) {
			$cached = $this->cached_id( $user_id );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return null;
		}

		return $this->resolve_by_email( $user->user_email, $user_id );
	}

	/**
	 * @param int|null $user_id When given, a successful lookup is cached.
	 */
	public function resolve_by_email( string $email, ?int $user_id = null ): ?string {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			return null;
		}

		try {
			$contact_id = $provider->get_contact_id( $email );
		} catch ( CrmException $e ) {
			$this->logger->warning(
				'Could not look up a contact by email.',
				array(
					'error'   => $e->getMessage(),
					'user_id' => $user_id ?? 0,
				),
				$provider->get_slug()
			);

			return null;
		}

		if ( null !== $contact_id && null !== $user_id ) {
			$this->store_id( $user_id, $contact_id );
		}

		return $contact_id;
	}

	/**
	 * Finds the WordPress user behind a CRM contact, by email.
	 */
	public function user_for_email( string $email ): ?int {
		$user = get_user_by( 'email', $email );

		return $user instanceof WP_User ? $user->ID : null;
	}

	/**
	 * Finds a user by their stored contact ID, for webhook payloads that
	 * identify the contact but carry a stale or missing email.
	 */
	public function user_for_contact( string $contact_id ): ?int {
		// A meta lookup, and deliberately so: this is the webhook fallback for
		// a contact whose email changed in the CRM, not a per-request path.
		// phpcs:disable WordPress.DB.SlowDBQuery
		$users = get_users(
			array(
				'meta_key'   => self::META_CONTACT_ID,
				'meta_value' => $contact_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		// phpcs:enable WordPress.DB.SlowDBQuery

		return empty( $users ) ? null : (int) $users[0];
	}
}
