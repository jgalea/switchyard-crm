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
use WP_Comment_Query;
use WP_Query;

final class QueryFilterTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	public function test_a_cold_cache_with_a_term_rule_never_runs_a_wp_query(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';
			public string $termmeta = 'wp_termmeta';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $posts = 'wp_posts';

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				return str_contains( $query, 'term_relationships' ) ? array( '11', '12' ) : array( '3' );
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

		Functions\when( 'get_posts' )->alias(
			function () use ( $filter, $query ): array {
				$filter->exclude_restricted( $query );

				throw new RuntimeException( 'the restricted-ID lookup ran a WP_Query' );
			}
		);

		$filter->exclude_restricted( $query );

		$this->assertSame( array( 3, 11, 12 ), $this->transients['switchyard_restricted_ids'] );
	}

	public function test_front_end_ajax_requests_are_filtered_like_any_other_listing(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				return array( '3' );
			}
		};

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$tags     = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );
		$filter   = new QueryFilter( new Rules( $tags ), $settings );
		$query    = new WP_Query();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post' )->justReturn( null );

		$filter->exclude_restricted( $query );

		$this->assertSame( array( 3 ), $query->get( 'post__not_in' ) );
	}

	public function test_comments_on_restricted_posts_are_excluded_from_comment_queries(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				return array( '3' );
			}
		};

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$tags     = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );
		$filter   = new QueryFilter( new Rules( $tags ), $settings );
		$query    = new WP_Comment_Query();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post' )->justReturn( null );

		$filter->exclude_restricted_comments( $query );

		$this->assertSame( array( 3 ), $query->query_vars['post__not_in'] );
	}

	public function test_the_site_wide_comment_feed_excludes_comments_on_restricted_posts(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				return array( '3' );
			}
		};

		$logger   = $this->createMock( Logger::class );
		$settings = new Settings();
		$registry = new ProviderRegistry( new Client( $logger ), $settings, $logger );
		$tags     = new TagEngine( $registry, new ContactResolver( $registry, $logger ), $settings, $logger );
		$filter   = new QueryFilter( new Rules( $tags ), $settings );
		
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertSame(
			' AND x AND comment_post_ID NOT IN (3)',
			$filter->filter_comment_feed( ' AND x' )
		);
	}
}
