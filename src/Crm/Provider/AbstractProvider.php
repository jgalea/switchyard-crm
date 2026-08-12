<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Provider;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Http\Client;

/**
 * Shared plumbing for providers: credential storage, a configured HTTP client,
 * and caching of the tag and field catalogues, which are read constantly and
 * change rarely.
 */
abstract class AbstractProvider implements Provider {

	protected Client $client_factory;
	protected const CATALOGUE_TTL = HOUR_IN_SECONDS * 6;

	/** @var array<string, mixed> */
	protected array $credentials = array();

	protected ?Client $http = null;

	public function __construct( Client $client_factory ) {
		$this->client_factory = $client_factory;
	}

	/**
	 * Base URL for this provider's API. Providers that derive it from an
	 * account-specific credential override this.
	 */
	abstract protected function base_url(): string;

	/**
	 * Auth and any provider-required headers.
	 *
	 * @return array<string, string>
	 */
	abstract protected function auth_headers(): array;

	/**
	 * A cheap authenticated request used to validate credentials.
	 *
	 * @throws ConnectionException
	 */
	abstract protected function verify_credentials(): void;

	public function connect( array $credentials ): void {
		$this->credentials = $credentials;
		$this->http        = null;

		foreach ( $this->get_credential_fields() as $key => $field ) {
			$required = $field['required'] ?? true;

			if ( $required && empty( $credentials[ $key ] ) ) {
				throw new ConnectionException(
					sprintf(
						/* translators: %s: credential field label. */
						esc_html__( '%s is required to connect.', 'switchyard-crm' ),
						esc_html( $field['label'] )
					)
				);
			}
		}

		$this->verify_credentials();
	}

	protected function http(): Client {
		if ( null === $this->http ) {
			$this->http = $this->client_factory
				->for_provider( $this->get_slug() )
				->base_url( $this->base_url() )
				->headers( $this->auth_headers() );
		}

		return $this->http;
	}

	protected function credential( string $key, string $fallback = '' ): string {
		$value = $this->credentials[ $key ] ?? $fallback;

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Catalogue reads (tags, fields, lists) hit the API on nearly every admin
	 * page load otherwise.
	 *
	 * @template T
	 * @param callable(): T $loader
	 * @return T
	 */
	protected function cached( string $key, callable $loader ) {
		$transient = 'switchyard_cat_' . $this->get_slug() . '_' . $key;
		$cached    = get_transient( $transient );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $loader();

		set_transient( $transient, $value, self::CATALOGUE_TTL );

		return $value;
	}

	public function flush_cache(): void {
		foreach ( array( 'tags', 'fields', 'lists' ) as $key ) {
			delete_transient( 'switchyard_cat_' . $this->get_slug() . '_' . $key );
		}
	}

	/**
	 * Splits a display name into given and family parts.
	 *
	 * Every provider needs this and none of them agree on whether they want
	 * one field or two.
	 *
	 * @return array{0: string, 1: string}
	 */
	protected function split_name( string $name ): array {
		$name = trim( $name );

		if ( '' === $name ) {
			return array( '', '' );
		}

		$parts = preg_split( '/\s+/', $name );
		$parts = is_array( $parts ) ? $parts : array( $name );
		$first = (string) array_shift( $parts );

		return array( $first, implode( ' ', $parts ) );
	}
}
