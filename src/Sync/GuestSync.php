<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\ProviderRegistry;
use Conduit\Logger\Logger;
use Conduit\Settings;

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
	private Settings $settings;
	private Logger $logger;
	public const HOOK = 'conduit_push_guest';

	public function __construct( ProviderRegistry $registry, ContactResolver $resolver, Settings $settings, Logger $logger ) {
		$this->registry = $registry;
		$this->resolver = $resolver;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks(): void {
		add_action(
			self::HOOK,
			function ( string $email, array $fields = array(), array $tags = array() ): void {
				$this->push( $email, $fields, $tags );
			},
			10,
			3
		);
	}

	/**
	 * @param array<string, mixed> $fields Keyed by CRM field identifier.
	 * @param list<string>         $tags
	 *
	 * @return string|null The contact ID, or null when nothing was written.
	 */
	public function push( string $email, array $fields = array(), array $tags = array() ): ?string {
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
				$contact_id = $provider->add_contact( $fields );

				if ( null !== $user_id ) {
					$this->resolver->store_id( $user_id, $contact_id );
				}
			} elseif ( ! empty( $fields ) ) {
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

		do_action( 'conduit_guest_synced', $email, $contact_id );

		return $contact_id;
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
