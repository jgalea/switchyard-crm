<?php

declare( strict_types=1 );

namespace Conduit\Crm\Http;

use Conduit\Crm\Exception\ApiException;
use Conduit\Crm\Exception\CircuitOpenException;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\NotFoundException;
use Conduit\Crm\Exception\RateLimitException;
use Conduit\Logger\Logger;
use WP_Error;

/**
 * The single outbound path for every provider.
 *
 * Centralising this is what makes the "a CRM outage never breaks checkout"
 * guarantee enforceable: retries, backoff and the circuit breaker live here,
 * so no provider can accidentally hang a request thread on its own.
 */
final class Client {

	private const MAX_ATTEMPTS      = 3;
	private const BASE_BACKOFF      = 1;
	private const MAX_BACKOFF       = 30;
	private const FAILURE_THRESHOLD = 5;
	private const CIRCUIT_COOLDOWN  = 300;
	private const DEFAULT_TIMEOUT   = 15;

	private string $base_url = '';

	/** @var array<string, string> */
	private array $default_headers = array();

	private string $provider_slug = 'unknown';

	public function __construct( private Logger $logger ) {}

	/**
	 * Configuration returns a clone rather than mutating.
	 *
	 * One Client is shared across providers, so a fluent setter that mutated
	 * in place would leak one provider's base URL and auth headers into the
	 * next provider's requests.
	 */
	public function for_provider( string $slug ): self {
		$clone                = clone $this;
		$clone->provider_slug = $slug;

		return $clone;
	}

	public function base_url( string $url ): self {
		$clone           = clone $this;
		$clone->base_url = rtrim( $url, '/' );

		return $clone;
	}

	/** @param array<string, string> $headers */
	public function headers( array $headers ): self {
		$clone                  = clone $this;
		$clone->default_headers = $headers;

		return $clone;
	}

	/**
	 * @param array<string, scalar|null> $query
	 */
	public function get( string $path, array $query = array() ): Response {
		return $this->request( 'GET', $path, null, $query );
	}

	/** @param array<mixed> $body */
	public function post( string $path, array $body = array() ): Response {
		return $this->request( 'POST', $path, $body );
	}

	/** @param array<mixed> $body */
	public function put( string $path, array $body = array() ): Response {
		return $this->request( 'PUT', $path, $body );
	}

	/** @param array<mixed> $body */
	public function patch( string $path, array $body = array() ): Response {
		return $this->request( 'PATCH', $path, $body );
	}

	/** @param array<mixed> $body */
	public function delete( string $path, array $body = array() ): Response {
		return $this->request( 'DELETE', $path, $body );
	}

	/**
	 * @param array<mixed>|null          $body
	 * @param array<string, scalar|null> $query
	 *
	 * @throws CircuitOpenException When the provider is in cooldown.
	 * @throws ConnectionException  On transport failure.
	 * @throws RateLimitException   When retries are exhausted against a 429.
	 * @throws NotFoundException    On 404.
	 * @throws ApiException         On any other non-2xx response.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = array() ): Response {
		$this->guard_circuit();

		$url = $this->build_url( $path, $query );

		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'conduit_http_timeout', self::DEFAULT_TIMEOUT, $this->provider_slug ),
			'headers' => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Conduit-CRM/' . CONDUIT_VERSION . '; ' . home_url(),
				),
				$this->default_headers
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/json';
			$args['body']                    = 'application/json' === $args['headers']['Content-Type']
				? (string) wp_json_encode( $body )
				: $body;
		}

		/** @var array<string, mixed> $args */
		$args = apply_filters( 'conduit_http_args', $args, $this->provider_slug, $url );

		$attempt = 0;

		while ( true ) {
			++$attempt;

			$raw = wp_remote_request( $url, $args );

			if ( $raw instanceof WP_Error ) {
				if ( $attempt < self::MAX_ATTEMPTS ) {
					$this->sleep( $this->backoff( $attempt ) );
					continue;
				}

				// Counted once per request that ultimately failed, not once
				// per attempt, so the breaker threshold means the same thing
				// whether the failure was a transport error or a 500.
				$this->record_failure();

				$this->logger->error(
					'Transport failure contacting the CRM.',
					array(
						'url'     => $url,
						'method'  => $method,
						'error'   => $raw->get_error_message(),
						'attempt' => $attempt,
					),
					$this->provider_slug
				);

				throw new ConnectionException( $raw->get_error_message(), array( 'url' => $url ) );
			}

			$response = $this->to_response( $raw );

			if ( $response->successful() ) {
				$this->record_success();
				$this->log_exchange( $method, $url, $body, $response, 'debug' );

				return $response;
			}

			if ( $this->is_retryable( $response->status ) && $attempt < self::MAX_ATTEMPTS ) {
				$this->sleep( $this->retry_delay( $response, $attempt ) );
				continue;
			}

			$this->record_failure();
			$this->log_exchange( $method, $url, $body, $response, 'error' );

			throw $this->to_exception( $response, $url );
		}
	}

	/** @param array<string, scalar|null> $query */
	private function build_url( string $path, array $query ): string {
		$url = str_starts_with( $path, 'http' )
			? $path
			: $this->base_url . '/' . ltrim( $path, '/' );

		return empty( $query ) ? $url : add_query_arg( array_filter( $query, static fn( $v ) => null !== $v ), $url );
	}

	/** @param array<string, mixed> $raw */
	private function to_response( array $raw ): Response {
		$headers = array();

		$raw_headers = wp_remote_retrieve_headers( $raw );

		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			/** @var array<string, string|array<string>> $all */
			$all = $raw_headers->getAll();

			foreach ( $all as $key => $value ) {
				$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		} elseif ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $key => $value ) {
				$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		return new Response(
			(int) wp_remote_retrieve_response_code( $raw ),
			(string) wp_remote_retrieve_body( $raw ),
			$headers
		);
	}

	private function is_retryable( int $status ): bool {
		return 429 === $status || $status >= 500;
	}

	private function retry_delay( Response $response, int $attempt ): int {
		$retry_after = $response->header( 'retry-after' );

		if ( null !== $retry_after && is_numeric( $retry_after ) ) {
			return min( self::MAX_BACKOFF, max( 1, (int) $retry_after ) );
		}

		return $this->backoff( $attempt );
	}

	private function backoff( int $attempt ): int {
		return (int) min( self::MAX_BACKOFF, self::BASE_BACKOFF * ( 2 ** ( $attempt - 1 ) ) );
	}

	private function sleep( int $seconds ): void {
		if ( $seconds > 0 && ! defined( 'CONDUIT_TESTING' ) ) {
			sleep( $seconds );
		}
	}

	private function to_exception( Response $response, string $url ): \Throwable {
		$context = array(
			'url'    => $url,
			'status' => $response->status,
			'body'   => substr( $response->body, 0, 500 ),
		);

		$message = $this->extract_error_message( $response );

		if ( 429 === $response->status ) {
			$exception   = new RateLimitException( $message, $context, 429 );
			$retry_after = $response->header( 'retry-after' );
			$exception->set_retry_after( is_numeric( $retry_after ) ? (int) $retry_after : 0 );

			return $exception;
		}

		if ( 404 === $response->status ) {
			return new NotFoundException( $message, $context, 404 );
		}

		if ( 401 === $response->status || 403 === $response->status ) {
			return new ConnectionException( $message, $context, $response->status );
		}

		return new ApiException( $message, $context, $response->status );
	}

	/**
	 * CRMs disagree on where the human-readable error lives. Checking the
	 * common shapes beats surfacing a raw JSON blob in a support ticket.
	 */
	private function extract_error_message( Response $response ): string {
		$json = $response->json();

		$candidates = array(
			$json['message'] ?? null,
			$json['error_description'] ?? null,
			$json['error'] ?? null,
			$json['detail'] ?? null,
			$json['title'] ?? null,
			$json['errors'][0]['detail'] ?? null,
			$json['errors'][0]['message'] ?? null,
			$json['errors'][0]['title'] ?? null,
			$json['fault']['faultstring'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return $candidate;
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The CRM returned an unexpected %d response.', 'conduit-crm' ),
			$response->status
		);
	}

	/** @param array<mixed>|null $body */
	private function log_exchange( string $method, string $url, ?array $body, Response $response, string $level ): void {
		$this->logger->log(
			$level,
			sprintf( '%s %s responded %d.', $method, $url, $response->status ),
			array(
				'request'  => $body,
				'response' => substr( $response->body, 0, 1000 ),
			),
			$this->provider_slug
		);
	}

	private function circuit_key(): string {
		return 'conduit_circuit_' . $this->provider_slug;
	}

	/** @throws CircuitOpenException */
	private function guard_circuit(): void {
		$state = get_transient( $this->circuit_key() );

		if ( is_array( $state ) && ( $state['open'] ?? false ) ) {
			throw new CircuitOpenException(
				__( 'Requests to this CRM are paused after repeated failures. They resume automatically.', 'conduit-crm' ),
				array( 'provider' => $this->provider_slug )
			);
		}
	}

	private function record_failure(): void {
		$state = get_transient( $this->circuit_key() );
		$count = is_array( $state ) ? (int) ( $state['failures'] ?? 0 ) : 0;
		++$count;

		if ( $count >= self::FAILURE_THRESHOLD ) {
			set_transient(
				$this->circuit_key(),
				array(
					'open'     => true,
					'failures' => $count,
				),
				self::CIRCUIT_COOLDOWN
			);

			$this->logger->error(
				'Suspending requests to the CRM after repeated failures.',
				array(
					'failures' => $count,
					'cooldown' => self::CIRCUIT_COOLDOWN,
				),
				$this->provider_slug
			);

			return;
		}

		set_transient(
			$this->circuit_key(),
			array(
				'open'     => false,
				'failures' => $count,
			),
			self::CIRCUIT_COOLDOWN
		);
	}

	private function record_success(): void {
		delete_transient( $this->circuit_key() );
	}
}
