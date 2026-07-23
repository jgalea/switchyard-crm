<?php

declare( strict_types=1 );

namespace Conduit\Tests\Unit;

use Brain\Monkey\Functions;
use Conduit\Crm\Exception\ApiException;
use Conduit\Crm\Exception\CircuitOpenException;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\NotFoundException;
use Conduit\Crm\Exception\RateLimitException;
use Conduit\Crm\Http\Client;
use Conduit\Logger\Logger;
use Conduit\Tests\TestCase;

/**
 * The client is the only thing standing between a flaky CRM and a broken
 * checkout, so its retry and breaker behaviour is tested directly.
 */
final class HttpClientTest extends TestCase {

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	private array $requests = array();

	private function client(): Client {
		$logger = $this->createMock( Logger::class );

		return ( new Client( $logger ) )->for_provider( 'test' )->base_url( 'https://api.test' );
	}

	/**
	 * @param array<int, array<string, mixed>> $responses
	 */
	private function stub_responses( array $responses ): void {
		$this->requests = array();
		$queue          = $responses;

		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) use ( &$queue ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array_shift( $queue ) ?? array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => $r['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( $r ) => $r['headers'] ?? array() );
	}

	public function test_it_returns_a_decoded_successful_response(): void {
		$this->stub_responses(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"contact":{"id":"42"}}',
					'headers'  => array(),
				),
			)
		);

		$response = $this->client()->get( '/contacts' );

		$this->assertTrue( $response->successful() );
		$this->assertSame( '42', $response->path( 'contact.id' ) );
	}

	public function test_it_retries_a_server_error_then_succeeds(): void {
		$this->stub_responses(
			array(
				array(
					'response' => array( 'code' => 503 ),
					'body'     => '',
					'headers'  => array(),
				),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"ok":true}',
					'headers'  => array(),
				),
			)
		);

		$response = $this->client()->get( '/contacts' );

		$this->assertTrue( $response->successful() );
		$this->assertCount( 2, $this->requests, 'The failed attempt should have been retried.' );
	}

	public function test_it_gives_up_after_the_attempt_limit(): void {
		$this->stub_responses(
			array_fill(
				0,
				5,
				array(
					'response' => array( 'code' => 500 ),
					'body'     => '{"message":"boom"}',
					'headers'  => array(),
				)
			)
		);

		$this->expectException( ApiException::class );

		try {
			$this->client()->get( '/contacts' );
		} finally {
			$this->assertCount( 3, $this->requests, 'Three attempts, not unbounded retries.' );
		}
	}

	public function test_it_raises_a_rate_limit_exception_carrying_retry_after(): void {
		$this->stub_responses(
			array_fill(
				0,
				3,
				array(
					'response' => array( 'code' => 429 ),
					'body'     => '{"message":"slow down"}',
					'headers'  => array( 'retry-after' => '7' ),
				)
			)
		);

		try {
			$this->client()->get( '/contacts' );

			$this->fail( 'Expected a RateLimitException.' );
		} catch ( RateLimitException $e ) {
			$this->assertSame( 7, $e->retry_after() );
			$this->assertSame( 'slow down', $e->getMessage() );
		}
	}

	public function test_it_maps_status_codes_to_specific_exceptions(): void {
		$cases = array(
			404 => NotFoundException::class,
			401 => ConnectionException::class,
			403 => ConnectionException::class,
			422 => ApiException::class,
		);

		foreach ( $cases as $status => $expected ) {
			$this->transients = array();

			$this->stub_responses(
				array(
					array(
						'response' => array( 'code' => $status ),
						'body'     => '{"message":"nope"}',
						'headers'  => array(),
					),
				)
			);

			try {
				$this->client()->get( '/contacts' );

				$this->fail( sprintf( 'Expected %s for status %d.', $expected, $status ) );
			} catch ( \Throwable $e ) {
				$this->assertInstanceOf( $expected, $e, sprintf( 'Status %d', $status ) );
			}
		}
	}

	/**
	 * A CRM that is down should stop being called, rather than adding a
	 * multi-second timeout to every pageview.
	 */
	public function test_it_opens_the_circuit_after_repeated_failures(): void {
		$this->stub_responses(
			array_fill(
				0,
				60,
				array(
					'response' => array( 'code' => 500 ),
					'body'     => '{}',
					'headers'  => array(),
				)
			)
		);

		$client = $this->client();

		// Five failed requests, matching the breaker threshold. Each request
		// counts once regardless of how many attempts it made internally.
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$client->get( '/contacts' );
			} catch ( \Throwable ) {
				continue;
			}
		}

		$this->expectException( CircuitOpenException::class );

		$client->get( '/contacts' );
	}

	public function test_a_success_resets_the_failure_count(): void {
		$this->stub_responses(
			array(
				array(
					'response' => array( 'code' => 500 ),
					'body'     => '{}',
					'headers'  => array(),
				),
				array(
					'response' => array( 'code' => 500 ),
					'body'     => '{}',
					'headers'  => array(),
				),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"ok":1}',
					'headers'  => array(),
				),
			)
		);

		$this->client()->get( '/contacts' );

		$this->assertSame( array(), $this->transients, 'A success should clear the breaker state.' );
	}

	/**
	 * Configuration must not leak between providers sharing one client.
	 */
	public function test_configuration_does_not_leak_between_providers(): void {
		$this->stub_responses( array() );

		$logger = $this->createMock( Logger::class );
		$base   = new Client( $logger );

		$one = $base->for_provider( 'one' )->base_url( 'https://one.test' )->headers( array( 'A' => '1' ) );
		$two = $base->for_provider( 'two' )->base_url( 'https://two.test' )->headers( array( 'B' => '2' ) );

		$one->get( '/x' );
		$two->get( '/x' );

		$this->assertSame( 'https://one.test/x', $this->requests[0]['url'] );
		$this->assertSame( 'https://two.test/x', $this->requests[1]['url'] );
		$this->assertArrayHasKey( 'A', $this->requests[0]['args']['headers'] );
		$this->assertArrayNotHasKey( 'A', $this->requests[1]['args']['headers'] );
	}
}
