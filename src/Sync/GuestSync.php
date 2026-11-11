<?php

declare( strict_types=1 );

namespace Switchyard\Sync;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;

/**
 * Syncs a contact that has no WordPress user behind it.
 *
 * Guest checkout and anonymous form submissions are the two big cases, and
 * they are the ones WP Fusion Lite cannot do at all. Because there is no user
 * to hang meta off, nothing is cached: every call resolves and writes.
 */
final class GuestSync {

	private ProviderRegistry $registry;
	private ContactResolver $resolver;
	private FieldMap $field_map;
	private Settings $settings;
	private Logger $logger;
	public const HOOK = 'switchyard_push_guest';

	public function __construct( ProviderRegistry $registry, ContactResolver $resolver, FieldMap $field_map, Settings $settings, Logger $logger ) {
		$this->registry  = $registry;
		$this->resolver  = $resolver;
		$this->field_map = $field_map;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	public function register_hooks(): void {
		add_action(
			self::HOOK,
			function ( string $email, array $fields = array(), array $tags = array(), bool $update_existing = true ): void {
				$this->push( $email, $fields, $tags, $update_existing );
			},
			10,
			4
		);
	}

	/**
	 * @param array<string, mixed> $fields Keyed by CRM field identifier.
	 * @param list<string>         $tags
	 * @param bool                 $update_existing Whether fields may overwrite a contact that already exists.
	 *
	 * @return string|null The contact ID, or null when nothing was written.
	 */
	public function push( string $email, array $fields = array(), array $tags = array(), bool $update_existing = true ): ?string {
		if ( ! is_email( $email ) ) {
			return null;
		}

		$provider = $this->registry->active();

		if ( null === $provider ) {
			return null;
		}

		if ( $this->settings->is_staging() ) {
			$this->logger->info( 'Staging mode is on, so this guest contact was not synced.' );

			return null;
		}

		// If the email belongs to a real user, treat it as that user so the
		// contact mapping and tag cache stay correct.
		$user_id = $this->resolver->user_for_email( $email );

		try {
			$contact_id = $this->resolver->resolve_by_email( $email, $user_id );

			if ( null === $contact_id ) {
				$email_field = $this->email_field( $provider );

				if ( null === $email_field ) {
					$this->logger->warning( 'Could not create a guest contact because the CRM has no email field to fill.' );

					return null;
				}

				$fields[ $email_field ] = $email;
				$contact_id             = $provider->add_contact( $fields );

				if ( null !== $user_id ) {
					$this->resolver->store_id( $user_id, $contact_id );
				}
			} elseif ( $update_existing && ! empty( $fields ) ) {
				$provider->update_contact( $contact_id, $fields );
			}
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not sync a guest contact.',
				array( 'error' => $e->getMessage() )
			);

			return null;
		}

		if ( ! empty( $tags ) ) {
			$this->apply_guest_tags( $contact_id, $tags );
		}

		do_action( 'switchyard_guest_synced', $email, $contact_id );

		return $contact_id;
	}

	/**
	 * The CRM field that holds the email: the one the site mapped to the
	 * WordPress email, otherwise the provider's own email field.
	 */
	private function email_field( Provider $provider ): ?string {
		$mapped = $this->field_map->all()['user_email'] ?? null;

		if ( null !== $mapped && ! empty( $mapped['active'] ) && '' !== $mapped['crm_field'] ) {
			return $mapped['crm_field'];
		}

		foreach ( $provider->get_available_fields() as $key => $field ) {
			if ( 'email' === ( $field['type'] ?? '' ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $tags
	 */
	private function apply_guest_tags( string $contact_id, array $tags ): void {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			return;
		}

		$adapter = new SegmentAdapter( $provider );

		if ( ! $adapter->supported() ) {
			return;
		}

		$resolved = $adapter->resolve( $tags, true );

		if ( empty( $resolved ) ) {
			return;
		}

		try {
			$adapter->apply( $contact_id, $resolved );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not tag a guest contact.',
				array(
					'error' => $e->getMessage(),
					'tags'  => $resolved,
				)
			);
		}
	}
}
