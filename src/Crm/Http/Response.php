<?php

declare( strict_types=1 );

namespace Conduit\Crm\Http;

final class Response {

	/**
	 * @param array<string, string> $headers
	 * @param array<mixed>|null     $json
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = array(),
		private readonly ?array $json = null,
	) {}

	/** @return array<mixed> */
	public function json(): array {
		if ( null !== $this->json ) {
			return $this->json;
		}

		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Reads a value from the decoded body using dot notation.
	 */
	public function path( string $path, mixed $fallback = null ): mixed {
		$value = $this->json();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function successful(): bool {
		return $this->status >= 200 && $this->status < 300;
	}
}
