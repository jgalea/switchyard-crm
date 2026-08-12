<?php

declare( strict_types=1 );

namespace Switchyard\Integration;

use Switchyard\Container;
use Switchyard\Logger\Logger;

/**
 * Loads the integrations whose host plugin is actually active.
 *
 * Integrations are registered as factories so an integration for an inactive
 * plugin is never instantiated, and so another plugin can add its own through
 * the same path.
 */
final class Registry {

	private Container $container;
	private Logger $logger;
	/** @var array<string, callable(Container): Integration> */
	private array $factories = array();

	/** @var array<string, Integration> */
	private array $loaded = array();

	public function __construct( Container $container, Logger $logger ) {
		$this->container = $container;
		$this->logger    = $logger;
	}

	/**
	 * @param callable(Container): Integration $factory
	 */
	public function add( string $slug, callable $factory ): void {
		$this->factories[ $slug ] = $factory;
	}

	public function boot(): void {
		do_action( 'switchyard_register_integrations', $this );

		foreach ( $this->factories as $slug => $factory ) {
			try {
				$integration = $factory( $this->container );
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'An integration could not be constructed.',
					array(
						'integration' => $slug,
						'error'       => $e->getMessage(),
					)
				);

				continue;
			}

			if ( ! $integration->is_available() ) {
				continue;
			}

			if ( ! apply_filters( 'switchyard_enable_integration', true, $slug ) ) {
				continue;
			}

			// One integration throwing during hook registration must not stop
			// the others from loading.
			try {
				$integration->register_hooks();
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'An integration failed while registering its hooks.',
					array(
						'integration' => $slug,
						'error'       => $e->getMessage(),
					)
				);

				continue;
			}

			$this->loaded[ $slug ] = $integration;
		}
	}

	/** @return array<string, Integration> */
	public function loaded(): array {
		return $this->loaded;
	}

	public function is_loaded( string $slug ): bool {
		return isset( $this->loaded[ $slug ] );
	}

	/**
	 * Every registered integration and whether its host plugin is active,
	 * for the integrations screen.
	 *
	 * @return array<string, array{name: string, available: bool, loaded: bool}>
	 */
	public function status(): array {
		$status = array();

		foreach ( $this->factories as $slug => $factory ) {
			try {
				$integration = $this->loaded[ $slug ] ?? $factory( $this->container );
			} catch ( \Throwable $exception ) {
				continue;
			}

			$status[ $slug ] = array(
				'name'      => $integration->get_name(),
				'available' => $integration->is_available(),
				'loaded'    => isset( $this->loaded[ $slug ] ),
			);
		}

		return $status;
	}
}
