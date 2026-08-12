<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Http;

final class Response {

	/**
 * @readonly
 */
	public int $status;
	/**
	 * @readonly
	 */
	public string $body;
	/**
	 * @var array<string, string>
	 * @readonly
	 */
	public array $headers = array();
	/**
	 * @var array<mixed>|null
	 * @readonly
	 */
	private ?array $json = null;
	/**
	 * @param array<string, string> $headers
	 * @param array<mixed>|null     $json
	 */
	public function __construct( int $status, string $body, array $headers = array(), ?array $json = null ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = $headers;
		$this->json    = $json;
	}
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
 * @param mixed $fallback
 * @return mixed
 */
	public function path( string $path, $fallback = null ) {
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
