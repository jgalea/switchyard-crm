<?php

declare( strict_types=1 );

namespace Conduit\Integration;

use Conduit\Features;
use Conduit\Logger\Logger;
use Conduit\Sync\Queue;
use Conduit\Sync\TagEngine;
use Conduit\Sync\UserSync;

/**
 * Shared behaviour for integrations.
 *
 * The important part is guard(): every callback an integration registers runs
 * inside a host plugin's critical path, so a thrown exception here would
 * become a fatal error during someone's checkout. Nothing escapes.
 */
abstract class AbstractIntegration implements Integration {

	public function __construct(
		protected UserSync $sync,
		protected TagEngine $tags,
		protected Queue $queue,
		protected Features $features,
		protected Logger $logger,
	) {}

	/**
	 * Runs a callback, logging and swallowing anything it throws.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T|null
	 */
	protected function guard( callable $callback ): mixed {
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'An integration callback failed.',
				array(
					'integration' => $this->get_slug(),
					'error'       => $e->getMessage(),
					'file'        => $e->getFile(),
					'line'        => $e->getLine(),
				),
				$this->get_slug()
			);

			return null;
		}
	}

	/**
	 * Settings stored per integration.
	 *
	 * @return array<string, mixed>
	 */
	protected function options(): array {
		$stored = get_option( 'conduit_integration_' . $this->get_slug(), array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @return list<string>
	 */
	protected function option_tags( string $key ): array {
		$value = $this->options()[ $key ] ?? array();

		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/**
	 * Applies tags to whoever owns this email, creating nothing.
	 *
	 * Guest checkouts and anonymous form submissions have no user, so the
	 * caller decides whether that is worth a sync.
	 *
	 * @param list<string> $tags
	 */
	protected function tag_user( int $user_id, array $tags ): void {
		if ( $user_id <= 0 || empty( $tags ) ) {
			return;
		}

		$this->queue->dispatch( 'conduit_apply_tags_async', array( $user_id, $tags ) );
	}
}
