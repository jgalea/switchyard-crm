<?php

declare( strict_types=1 );

namespace Conduit\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

	/** @var array<string, mixed> */
	protected array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		$this->transients = array();

		$this->stub_wordpress();
	}

	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * The WordPress functions the units under test reach for.
	 *
	 * Transients are backed by a real array so circuit-breaker and cache
	 * behaviour can be asserted rather than mocked away.
	 */
	protected function stub_wordpress(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->alias( static fn( string $t ): string => strtolower( str_replace( ' ', '-', $t ) ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'is_email' )->alias(
			static fn( $email ): bool => is_string( $email ) && (bool) filter_var( $email, FILTER_VALIDATE_EMAIL )
		);

		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				if ( ! is_array( $args ) ) {
					return $url;
				}

				$separator = str_contains( (string) $url, '?' ) ? '&' : '?';

				return $url . $separator . http_build_query( $args );
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);

		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );

				return true;
			}
		);
	}
}
