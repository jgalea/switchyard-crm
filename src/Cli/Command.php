<?php

declare( strict_types=1 );

namespace Conduit\Cli;

use Conduit\Admin\Batch;
use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\ProviderRegistry;
use Conduit\Plugin;
use Conduit\Sync\TagEngine;
use Conduit\Sync\UserSync;
use WP_CLI;

/**
 * WP-CLI commands.
 *
 * Batch work on a large site belongs here rather than in a browser request.
 */
final class Command {

	/**
	 * Shows the current connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp conduit status
	 */
	public function status(): void {
		/** @var ProviderRegistry $registry */
		$registry = Plugin::instance()->get( ProviderRegistry::class );

		$provider = $registry->active();

		if ( null === $provider ) {
			WP_CLI::warning( 'No CRM is connected.' );

			return;
		}

		WP_CLI::success( sprintf( 'Connected to %s.', $provider->get_name() ) );

		/** @var TagEngine $tags */
		$tags    = Plugin::instance()->get( TagEngine::class );
		$adapter = $tags->adapter();

		if ( null !== $adapter && $adapter->supported() ) {
			try {
				WP_CLI::log( sprintf( '%s available: %d', $adapter->label(), count( $adapter->available() ) ) );
			} catch ( CrmException $e ) {
				WP_CLI::warning( $e->getMessage() );
			}
		}
	}

	/**
	 * Pushes one user, or every user, to the CRM.
	 *
	 * ## OPTIONS
	 *
	 * [<user>]
	 * : A user ID, login or email. Omit to sync everyone.
	 *
	 * ## EXAMPLES
	 *
	 *     wp conduit sync 42
	 *     wp conduit sync
	 *
	 * @param array<int, string> $args
	 */
	public function sync( array $args ): void {
		if ( empty( $args ) ) {
			$queued = Batch::queue_all( 'conduit_batch_push_user' );

			WP_CLI::success( sprintf( 'Queued %d users.', $queued ) );

			return;
		}

		$user = $this->find_user( $args[0] );

		/** @var UserSync $sync */
		$sync = Plugin::instance()->get( UserSync::class );

		$contact_id = $sync->push( $user );

		if ( null === $contact_id ) {
			WP_CLI::error( 'Sync failed. Check the log for the reason.' );
		}

		WP_CLI::success( sprintf( 'Synced user %d to contact %s.', $user, $contact_id ) );
	}

	/**
	 * Shows or refreshes a user's tags.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : A user ID, login or email.
	 *
	 * [--refresh]
	 * : Pull from the CRM rather than reading the cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp conduit tags 42 --refresh
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function tags( array $args, array $assoc = array() ): void {
		$user = $this->find_user( $args[0] );

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		$tags = isset( $assoc['refresh'] ) ? $engine->load_tags( $user ) : $engine->get_tags( $user );

		if ( empty( $tags ) ) {
			WP_CLI::log( 'No tags.' );

			return;
		}

		foreach ( $engine->labels( $tags ) as $id => $label ) {
			WP_CLI::log( sprintf( '%s  %s', $id, $label ) );
		}
	}

	/**
	 * Imports contacts from the CRM as WordPress users.
	 *
	 * ## OPTIONS
	 *
	 * [--tag=<tag>]
	 * : Only import contacts carrying this tag ID.
	 *
	 * [--role=<role>]
	 * : Role for created users. Defaults to the site's default role.
	 *
	 * ## EXAMPLES
	 *
	 *     wp conduit import --tag=12 --role=subscriber
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc = array() ): void {
		/** @var ProviderRegistry $registry */
		$registry = Plugin::instance()->get( ProviderRegistry::class );

		$provider = $registry->active();

		if ( ! $provider instanceof \Conduit\Crm\Contracts\SupportsContactImport ) {
			WP_CLI::error( 'This CRM cannot enumerate contacts.' );

			return;
		}

		/** @var UserSync $sync */
		$sync = Plugin::instance()->get( UserSync::class );

		$tag    = $assoc['tag'] ?? null;
		$role   = $assoc['role'] ?? '';
		$cursor = null;
		$total  = 0;

		do {
			try {
				$page = $provider->load_contact_ids( $tag, $cursor );
			} catch ( CrmException $e ) {
				WP_CLI::error( $e->getMessage() );

				return;
			}

			foreach ( $page['ids'] as $contact_id ) {
				if ( null !== $sync->import_contact( $contact_id, $role ) ) {
					++$total;
				}
			}

			$cursor = $page['cursor'];

			WP_CLI::log( sprintf( 'Imported %d so far.', $total ) );
		} while ( null !== $cursor );

		WP_CLI::success( sprintf( 'Imported %d contacts.', $total ) );
	}

	private function find_user( string $identifier ): int {
		$user = is_numeric( $identifier )
			? get_user_by( 'id', (int) $identifier )
			: ( is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier ) );

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'No user matching "%s".', $identifier ) );
		}

		return $user->ID;
	}
}
