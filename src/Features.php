<?php

declare( strict_types=1 );

namespace Switchyard;

/**
 * Registry that companion plugins register capabilities into.
 *
 * It exists so a companion plugin can announce that it handles a capability,
 * and so integrations can ask whether something downstream will consume their
 * data.
 */
final class Features {

	/** @var array<string, object> */
	private array $features = array();

	public function register( string $slug, object $feature ): void {
		$this->features[ $slug ] = $feature;
	}

	public function has( string $slug ): bool {
		return isset( $this->features[ $slug ] );
	}

	public function get( string $slug ): ?object {
		return $this->features[ $slug ] ?? null;
	}

	/** @return list<string> */
	public function all(): array {
		return array_keys( $this->features );
	}
}
