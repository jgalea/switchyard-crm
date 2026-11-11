<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Switchyard\Access\QueryFilter;
use Switchyard\Tests\TestCase;
use ReflectionClass;

/**
 * Pins the set of surfaces access control has to cover.
 *
 * Gating the singular view and the main query is the obvious half. The half
 * that actually leaks is everything else that renders a title, an excerpt or
 * a body: the REST API for every post type, the core sitemap, adjacent-post
 * links and oEmbed. rest_prepare_{$post_type} is per type, so registering
 * only rest_prepare_post left every page and custom post type returning full
 * content over REST.
 */
final class AccessSurfaceTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Access/QueryFilter.php'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function requiredHooks(): array {
		return array(
			'main query'         => array( 'pre_get_posts' ),
			'page lists'         => array( 'get_pages' ),
			'core sitemap'       => array( 'wp_sitemaps_posts_query_args' ),
			'previous post link' => array( 'get_previous_post_where' ),
			'next post link'     => array( 'get_next_post_where' ),
			'oembed'             => array( 'oembed_response_data' ),
			'comment feed'       => array( 'comment_feed_where' ),
		);
	}

	/**
	 * @dataProvider requiredHooks
	 */
	public function test_it_covers_every_leaking_surface( string $hook ): void {
		$this->assertStringContainsString(
			$hook,
			$this->source(),
			sprintf( 'Access control no longer covers the %s surface.', $hook )
		);
	}

	/**
	 * The specific regression: a per-type filter registered for one type only.
	 */
	public function test_rest_filtering_is_registered_for_every_gated_post_type(): void {
		$source = $this->source();

		$this->assertStringContainsString(
			'gated_post_types()',
			$source,
			'REST filtering must iterate the gated post types.'
		);

		$this->assertStringContainsString(
			'"rest_prepare_{$post_type}"',
			$source,
			'REST filtering must build the per-type filter name.'
		);

		$this->assertStringNotContainsString(
			"add_filter( 'rest_prepare_post',",
			$source,
			'Registering rest_prepare_post alone leaves pages and custom post types exposed.'
		);
	}

	public function test_the_public_filter_methods_exist(): void {
		$class = new ReflectionClass( QueryFilter::class );

		foreach (
			array(
				'exclude_restricted',
				'filter_rest_response',
				'filter_pages',
				'filter_sitemap_query',
				'filter_adjacent_post',
				'filter_oembed',
			) as $method
		) {
			$this->assertTrue(
				$class->hasMethod( $method ),
				sprintf( 'QueryFilter::%s() is missing.', $method )
			);
			$this->assertTrue(
				$class->getMethod( $method )->isPublic(),
				sprintf( 'QueryFilter::%s() must be public to be used as a callback.', $method )
			);
		}
	}

	/**
	 * The restricted-post list is cached in a transient, so every write that
	 * can change membership of that set has to drop it.
	 *
	 * Term rules live in term meta, and meta writes fire added_/updated_/
	 * deleted_term_meta rather than edited_term. Missing those left a newly
	 * restricted category readable in archives, search, feeds and the sitemap
	 * until the transient expired.
	 *
	 * @dataProvider invalidationHooks
	 */
	public function test_every_rule_write_invalidates_the_cache( string $hook ): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$this->assertStringContainsString(
			"'" . $hook . "'",
			$source,
			sprintf( '%s does not invalidate the restricted-post cache.', $hook )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalidationHooks(): array {
		return array(
			'post saved'        => array( 'save_post' ),
			'post deleted'      => array( 'deleted_post' ),
			'term edited'       => array( 'edited_term' ),
			'term deleted'      => array( 'delete_term' ),
			'terms assigned'    => array( 'set_object_terms' ),
			'term meta added'   => array( 'added_term_meta' ),
			'term meta updated' => array( 'updated_term_meta' ),
			'term meta deleted' => array( 'deleted_term_meta' ),
		);
	}

	/**
	 * The adjacent-post filter injects into a SQL WHERE clause, so the IDs it
	 * interpolates must be integer-cast.
	 */
	public function test_adjacent_post_filter_casts_ids(): void {
		$this->assertStringContainsString(
			"array_map( 'absint', $" . 'excluded )',
			$this->source(),
			'Adjacent-post exclusion interpolates into SQL and must absint the IDs.'
		);
	}
}
