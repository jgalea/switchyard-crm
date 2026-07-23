<?php

declare( strict_types=1 );

namespace Conduit;

/**
 * Typed accessor over the single settings option.
 *
 * Credentials live here too. They are never exposed through REST, never
 * logged, and are stripped from any array that leaves the admin screen.
 */
final class Settings {

	public const OPTION = 'conduit_settings';

	/** @var array<string, mixed>|null */
	private ?array $data = null;

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'provider'               => '',
			'credentials'            => array(),
			'sync_on_register'       => true,
			'sync_on_profile_update' => true,
			'register_tags'          => array(),
			'import_new_contacts'    => false,
			'restricted_message'     => __( 'You do not have access to this content.', 'conduit-crm' ),
			'restricted_redirect'    => 0,
			'filter_queries'         => true,
			'site_tracking'          => false,
			'log_level'              => 'error',
			'log_retention_days'     => 30,
			'async_sync'             => true,
			'staging_mode'           => false,
		);
	}

	/** @return array<string, mixed> */
	private function data(): array {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = is_array( $stored )
				? array_merge( self::defaults(), $stored )
				: self::defaults();
		}

		return $this->data;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data()[ $key ] ?? $fallback;
	}

	public function bool( string $key ): bool {
		return (bool) $this->get( $key, false );
	}

	public function int( string $key, int $fallback = 0 ): int {
		return (int) $this->get( $key, $fallback );
	}

	public function string( string $key, string $fallback = '' ): string {
		$value = $this->get( $key, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/** @return array<mixed> */
	public function array( string $key ): array {
		$value = $this->get( $key, array() );

		return is_array( $value ) ? $value : array();
	}

	public function set( string $key, mixed $value ): void {
		$data         = $this->data();
		$data[ $key ] = $value;
		$this->data   = $data;

		update_option( self::OPTION, $data );
	}

	/** @param array<string, mixed> $values */
	public function merge( array $values ): void {
		$data       = array_merge( $this->data(), $values );
		$this->data = $data;

		update_option( self::OPTION, $data );
	}

	/** @return array<string, mixed> */
	public function credentials(): array {
		return $this->array( 'credentials' );
	}

	public function provider_slug(): string {
		return $this->string( 'provider' );
	}

	/**
	 * Staging mode short-circuits every outbound write. Used when cloning a
	 * production site so a staging copy cannot mutate live CRM records.
	 */
	public function is_staging(): bool {
		if ( $this->bool( 'staging_mode' ) ) {
			return true;
		}

		/** @var bool $filtered */
		$filtered = apply_filters( 'conduit_staging_mode', false );

		return $filtered;
	}

	public function flush(): void {
		$this->data = null;
	}
}
