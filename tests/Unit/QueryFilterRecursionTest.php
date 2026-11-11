<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use RuntimeException;
use Switchyard\Access\QueryFilter;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Access\Rules;
use Switchyard\Settings;
use Switchyard\Tests\TestCase;
use WP_Query;

final class QueryFilterRecursionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	public function test_a_cold_cache_with_a_term_rule_does_not_recurse_through_pre_get_posts(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';
			public string $termmeta = 'wp_termmeta';

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				return str_contains( $query, 'termmeta' ) ? array( '7' ) : array( '3' );
			}
		};

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$tags     = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );
		$filter   = new QueryFilter( new Rules( $tags ), $settings );
		$query    = new WP_Query();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post' )->justReturn( null );

		$depth = 0;

		Functions\when( 'get_posts' )->alias(
			function () use ( &$depth, $filter, $query ): array {
				if ( ++$depth > 3 ) {
					throw new RuntimeException( 'pre_get_posts recursion' );
				}

				$filter->exclude_restricted( $query );

				return array( 11 );
			}
		);

		$filter->exclude_restricted( $query );

		$this->assertSame( 1, $depth );
		$this->assertSame( array( 3, 11 ), $this->transients['switchyard_restricted_ids'] );
	}
}
