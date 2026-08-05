<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use Conduit\Logger\Logger;
use Conduit\Settings;

/**
 * Defers CRM work off the request that triggered it.
 *
 * Action Scheduler is the transport when it is available, which it is on any
 * WooCommerce site and via the bundled copy otherwise. When async is turned
 * off, or the scheduler is missing, work runs inline so behaviour degrades to
 * slower rather than broken.
 */
final class Queue {

	private Settings $settings;
	private Logger $logger;
	public const GROUP = 'conduit';

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	private function should_defer(): bool {
		if ( ! $this->settings->bool( 'async_sync' ) || ! $this->available() ) {
			return false;
		}

		// Running inline during WP-CLI and cron keeps batch jobs observable
		// rather than scheduling millions of follow-up actions.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}

		return (bool) apply_filters( 'conduit_defer_sync', true );
	}

	/**
	 * @param array<mixed> $args
	 */
	public function dispatch( string $hook, array $args = array() ): void {
		if ( ! $this->should_defer() ) {
			if ( ! $this->available() && $this->settings->bool( 'async_sync' ) ) {
				$this->logger->debug(
					'Action Scheduler is unavailable, so this job ran inline.',
					array( 'hook' => $hook )
				);
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- caller supplies an already-prefixed hook.
				do_action_ref_array( $hook, $args );

			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Schedules work for later, used for retries after a rate limit.
	 *
	 * @param array<mixed> $args
	 */
	public function schedule( int $timestamp, string $hook, array $args = array() ): void {
		if ( ! $this->available() ) {
			wp_schedule_single_event( $timestamp, $hook, $args );

			return;
		}

		as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
	}

	/**
	 * Whether an identical job is already queued, so repeated triggers within
	 * one request collapse into a single sync.
	 *
	 * @param array<mixed> $args
	 */
	public function is_queued( string $hook, array $args = array() ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	public function cancel( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
		}
	}
}
