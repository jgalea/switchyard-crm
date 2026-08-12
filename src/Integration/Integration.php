<?php

declare( strict_types=1 );

namespace Switchyard\Integration;

interface Integration {

	public function get_slug(): string;

	public function get_name(): string;

	/**
	 * Whether the plugin this integrates with is present and active.
	 */
	public function is_available(): bool;

	/**
	 * Attaches to the host plugin's hooks. Only called when available.
	 */
	public function register_hooks(): void;
}
