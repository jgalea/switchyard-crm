<?php

declare( strict_types=1 );

namespace Conduit\Admin;

use Conduit\Sync\Queue;

/**
 * Fans a job out over every user, one scheduled action per user.
 *
 * Per-user actions rather than one long-running job: a site with 50,000 users
 * would otherwise time out, and a single failure would lose the whole run.
 */
final class Batch {

	private const CHUNK = 500;

	public static function queue_all( string $hook ): int {
		$queued = 0;
		$paged  = 1;

		do {
			$user_ids = get_users(
				array(
					'fields'  => 'ID',
					'number'  => self::CHUNK,
					'paged'   => $paged,
					'orderby' => 'ID',
				)
			);

			foreach ( $user_ids as $user_id ) {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( $hook, array( (int) $user_id ), Queue::GROUP );
				} else {
					do_action( $hook, (int) $user_id );
				}

				++$queued;
			}

			++$paged;
			$fetched = count( $user_ids );
		} while ( self::CHUNK === $fetched );

		return $queued;
	}
}
