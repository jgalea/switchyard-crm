<?php

declare( strict_types=1 );

namespace Switchyard\Integration;

use Switchyard\Features;
use Switchyard\Logger\Logger;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

/**
 * Shared behaviour for integrations.
 *
 * The important part is guard(): every callback an integration registers runs
 * inside a host plugin's critical path, so a thrown exception here would
 * become a fatal error during someone's checkout. Nothing escapes.
 */
abstract class AbstractIntegration implements Integration {

	protected UserSync $sync;
	protected TagEngine $tags;
	protected Queue $queue;
	protected Features $features;
	protected Logger $logger;
	public function __construct( UserSync $sync, TagEngine $tags, Queue $queue, Features $features, Logger $logger ) {
		$this->sync     = $sync;
		$this->tags     = $tags;
		$this->queue    = $queue;
		$this->features = $features;
		$this->logger   = $logger;
	}
	/**
		* Runs a callback, logging and swallowing anything it throws.
		*
		* @template T
		* @param callable(): T $callback
		* @return T|null
		*/
	protected function guard( callable $callback ) {
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
		$stored = get_option( 'switchyard_integration_' . $this->get_slug(), array() );

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

		$this->queue->dispatch( 'switchyard_apply_tags_async', array( $user_id, $tags ) );
	}
}
