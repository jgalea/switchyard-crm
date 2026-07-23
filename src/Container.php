<?php

declare( strict_types=1 );

namespace Conduit;

use RuntimeException;

/**
 * Minimal service container. Services are registered as factories and
 * instantiated once, on first use.
 */
final class Container {

	/** @var array<string, callable(Container): object> */
	private array $factories = array();

	/** @var array<string, object> */
	private array $resolved = array();

	/**
	 * @param callable(Container): object $factory
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( sprintf( 'Service "%s" is not registered.', $id ) );
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}
}
