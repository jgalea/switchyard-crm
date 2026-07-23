<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use Conduit\Crm\Exception\CircuitOpenException;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Exception\RateLimitException;
use Conduit\Crm\ProviderRegistry;
use Conduit\Logger\Logger;
use Conduit\Settings;
use WP_User;

/**
 * Keeps a WordPress user and their CRM contact in step.
 *
 * Every public entry point here is safe to call from a hook on a customer-
 * facing request: failures are logged and swallowed, never thrown upward,
 * because the caller is usually checkout or registration.
 */
final class UserSync {

	public const HOOK_PUSH = 'conduit_push_user';
	public const HOOK_PULL = 'conduit_pull_user';

	public const META_LAST_SYNC = 'conduit_last_sync';

	/** @var array<int, bool> */
	private array $in_flight = array();

	public function __construct(
		private ProviderRegistry $registry,
		private ContactResolver $resolver,
		private FieldMap $fields,
		private TagEngine $tags,
		private Queue $queue,
		private Settings $settings,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'user_register', array( $this, 'on_register' ), 20 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20, 2 );
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'delete_user', array( $this, 'on_delete' ) );

		add_action(
			self::HOOK_PUSH,
			function ( int $user_id, array $overrides = array() ): void {
				$this->push( $user_id, $overrides );
			},
			10,
			2
		);
		add_action( self::HOOK_PULL, array( $this, 'pull' ) );
	}

	public function on_register( int $user_id ): void {
		if ( ! $this->settings->bool( 'sync_on_register' ) ) {
			return;
		}

		$this->queue->dispatch( self::HOOK_PUSH, array( $user_id, array() ) );

		$register_tags = $this->settings->array( 'register_tags' );

		if ( ! empty( $register_tags ) ) {
			/** @var list<string> $register_tags */
			$this->queue->dispatch( 'conduit_apply_tags_async', array( $user_id, $register_tags ) );
		}
	}

	/**
	 * @param array<string, mixed>|WP_User $old_data
	 */
	public function on_profile_update( int $user_id, mixed $old_data = null ): void {
		if ( ! $this->settings->bool( 'sync_on_profile_update' ) ) {
			return;
		}

		// An email change invalidates the cached contact mapping, because the
		// CRM identifies contacts by email and the old ID may now belong to a
		// record this user no longer owns.
		if ( $old_data instanceof WP_User ) {
			$user = get_userdata( $user_id );

			if ( $user instanceof WP_User && $user->user_email !== $old_data->user_email ) {
				$this->logger->info(
					'Email changed, so the cached contact mapping was cleared.',
					array( 'user_id' => $user_id )
				);
			}
		}

		$this->queue->dispatch( self::HOOK_PUSH, array( $user_id, array() ) );
	}

	public function on_login( string $user_login, mixed $user = null ): void {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user instanceof WP_User ) {
			return;
		}

		// Tags drive access, so a login is the moment they most need to be
		// current: an entitlement revoked in the CRM should bite immediately.
		$this->queue->dispatch( 'conduit_refresh_user_tags', array( $user->ID ) );
	}

	public function on_delete( int $user_id ): void {
		do_action( 'conduit_user_deleted', $user_id, $this->resolver->cached_id( $user_id ) );
	}

	/**
	 * Pushes a user to the CRM, creating the contact when it does not exist.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public function push( int $user_id, array $overrides = array() ): ?string {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			return null;
		}

		if ( $this->settings->is_staging() ) {
			$this->logger->info( 'Staging mode is on, so this user was not synced.', array( 'user_id' => $user_id ) );

			return null;
		}

		// Guards against an integration hook firing during the write this
		// method performs, which would otherwise recurse.
		if ( isset( $this->in_flight[ $user_id ] ) ) {
			return null;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return null;
		}

		if ( ! apply_filters( 'conduit_should_sync_user', true, $user_id ) ) {
			return null;
		}

		$this->in_flight[ $user_id ] = true;

		try {
			$payload = $this->fields->to_crm( $user_id, $overrides );

			if ( empty( $payload ) ) {
				return null;
			}

			$contact_id = $this->resolver->resolve( $user_id );

			if ( null === $contact_id ) {
				$contact_id = $provider->add_contact( $payload );

				$this->resolver->store_id( $user_id, $contact_id );

				do_action( 'conduit_contact_created', $user_id, $contact_id );
			} else {
				$provider->update_contact( $contact_id, $payload );

				do_action( 'conduit_contact_updated', $user_id, $contact_id );
			}

			update_user_meta( $user_id, self::META_LAST_SYNC, time() );

			return $contact_id;
		} catch ( RateLimitException $e ) {
			// Retrying now would only deepen the limit, so hand it back to
			// the scheduler with the delay the CRM asked for.
			$delay = $e->retry_after() > 0 ? $e->retry_after() : MINUTE_IN_SECONDS;

			$this->queue->schedule( time() + $delay, self::HOOK_PUSH, array( $user_id, $overrides ) );

			$this->logger->notice(
				'Rate limited, so this sync was rescheduled.',
				array(
					'user_id' => $user_id,
					'delay'   => $delay,
				)
			);

			return null;
		} catch ( CircuitOpenException ) {
			// Already logged when the breaker tripped.
			return null;
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not sync this user to the CRM.',
				array(
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				)
			);

			return null;
		} finally {
			unset( $this->in_flight[ $user_id ] );
		}
	}

	/**
	 * Pulls CRM values back onto the WordPress user.
	 */
	public function pull( int $user_id ): void {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			return;
		}

		$contact_id = $this->resolver->resolve( $user_id );

		if ( null === $contact_id ) {
			return;
		}

		try {
			$data = $provider->load_contact( $contact_id );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not load this contact from the CRM.',
				array(
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				)
			);

			return;
		}

		$this->fields->to_wordpress( $user_id, $data );
		$this->tags->load_tags( $user_id );

		do_action( 'conduit_user_pulled', $user_id, $contact_id );
	}

	/**
	 * Creates a WordPress user from a CRM contact.
	 */
	public function import_contact( string $contact_id, string $role = '' ): ?int {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			return null;
		}

		try {
			$data = $provider->load_contact( $contact_id );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not import this contact.',
				array(
					'contact_id' => $contact_id,
					'error'      => $e->getMessage(),
				)
			);

			return null;
		}

		$email = $this->find_email( $data );

		if ( null === $email ) {
			return null;
		}

		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof WP_User ) {
			$this->resolver->store_id( $existing->ID, $contact_id );
			$this->fields->to_wordpress( $existing->ID, $data );
			$this->tags->load_tags( $existing->ID );

			return $existing->ID;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $this->unique_login( $email ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => '' !== $role ? $role : get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->logger->error(
				'Could not create a user for this contact.',
				array(
					'contact_id' => $contact_id,
					'error'      => $user_id->get_error_message(),
				)
			);

			return null;
		}

		$this->resolver->store_id( $user_id, $contact_id );
		$this->fields->to_wordpress( $user_id, $data );
		$this->tags->load_tags( $user_id );

		do_action( 'conduit_contact_imported', $user_id, $contact_id );

		return $user_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function find_email( array $data ): ?string {
		foreach ( $data as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return null;
	}

	private function unique_login( string $email ): string {
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'user';
		$login = $base;
		$n     = 1;

		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;
		}

		return $login;
	}
}
