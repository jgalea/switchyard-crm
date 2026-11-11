<?php

declare( strict_types=1 );

namespace Switchyard\Access;

use Switchyard\Settings;
use WP_Post;
use WP_Query;
use WP_REST_Response;

/**
 * Keeps restricted content out of listings, feeds, search and REST.
 *
 * Filtering by post ID rather than by meta query is deliberate: rules can be
 * inherited from a taxonomy term and can be overridden by a filter, so the
 * decision cannot be expressed in SQL without duplicating the rule engine and
 * letting the two drift apart.
 */
final class QueryFilter {

	private Rules $rules;
	private Settings $settings;

	public function __construct( Rules $rules, Settings $settings ) {
		$this->rules    = $rules;
		$this->settings = $settings;
	}
	public function register_hooks(): void {
		if ( ! $this->settings->bool( 'filter_queries' ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'exclude_restricted' ) );
		add_filter( 'get_pages', array( $this, 'filter_pages' ) );

		// rest_prepare_{$post_type} is per type. Registering only
		// rest_prepare_post leaves every page and custom post type returning
		// its full content from the REST API regardless of its access rule.
		foreach ( $this->rules->gated_post_types() as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( $this, 'filter_rest_response' ), 10, 2 );
		}

		// Titles and URLs leak through these even when the body does not.
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap_query' ), 10, 2 );
		add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_post' ) );
		add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_post' ) );
		add_filter( 'oembed_response_data', array( $this, 'filter_oembed' ), 10, 2 );
	}

	public function exclude_restricted( WP_Query $query ): void {
		if ( is_admin() || $query->is_singular() ) {
			return;
		}

		if ( ! apply_filters( 'switchyard_filter_query', true, $query ) ) {
			return;
		}

		$excluded = $this->excluded_ids();

		if ( empty( $excluded ) ) {
			return;
		}

		$existing = $query->get( 'post__not_in' );
		$existing = is_array( $existing ) ? $existing : array();

		$query->set( 'post__not_in', array_merge( $existing, $excluded ) );
	}

	/**
	 * IDs of every post carrying an explicit restriction.
	 *
	 * Cached because it is needed on nearly every front-end query and changes
	 * only when a rule is edited.
	 *
	 * @return list<int>
	 */
	private function restricted_ids(): array {
		$cached = get_transient( 'switchyard_restricted_ids' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				Rules::META_ENABLED,
				'1'
			)
		);

		$ids = array_map( 'intval', $ids );

		$ids = array_merge( $ids, $this->posts_in_restricted_terms() );
		$ids = array_values( array_unique( $ids ) );

		set_transient( 'switchyard_restricted_ids', $ids, HOUR_IN_SECONDS );

		return $ids;
	}

	/**
	 * Posts assigned to a term that carries an access rule, in any taxonomy.
	 *
	 * Plain SQL rather than WP_Query: a query here would fire pre_get_posts
	 * and land back in exclude_restricted() with the cache still cold.
	 *
	 * @return list<int>
	 */
	private function posts_in_restricted_terms(): array {
		global $wpdb;

		$types = $this->rules->gated_post_types();

		if ( empty( $types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tr.object_id
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tm.meta_key = %s AND tm.meta_value != '' AND p.post_status = 'publish' AND p.post_type IN ({$placeholders})",
				array_merge( array( Rules::TERM_META_TAGS ), $types )
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Keeps restricted posts out of the core sitemap.
	 *
	 * @param array<string, mixed> $args
	 * @param string               $post_type Unused; part of the filter signature.
	 * @return array<string, mixed>
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
	public function filter_sitemap_query( array $args, string $post_type ): array {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
		$excluded = $this->excluded_ids();

		if ( empty( $excluded ) ) {
			return $args;
		}

		$existing = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] )
			? $args['post__not_in']
			: array();

		// post__not_in is a known scaling cost. It is used here deliberately:
		// a rule can be inherited from a taxonomy term and overridden by a
		// filter, so the decision cannot be expressed in SQL without
		// duplicating the rule engine and letting the two drift apart. The
		// restricted set is small and cached; correctness wins over the query
		// plan on a sitemap request.
		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		$args['post__not_in'] = array_merge( $existing, $excluded );

		return $args;
	}

	/**
	 * Stops previous/next links pointing at content the visitor cannot open.
	 */
	public function filter_adjacent_post( string $where ): string {
		$excluded = $this->excluded_ids();

		if ( empty( $excluded ) ) {
			return $where;
		}

		$list = implode( ',', array_map( 'absint', $excluded ) );

		return $where . " AND p.ID NOT IN ({$list})";
	}

	/**
 * oEmbed exposes the title and author of any published URL.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 * @param mixed $post
 */
	public function filter_oembed( array $data, $post ): array {
		if ( ! $post instanceof WP_Post || $this->rules->can_access( $post->ID ) ) {
			return $data;
		}

		return array();
	}

	/**
	 * Restricted post IDs the current visitor cannot reach.
	 *
	 * @return list<int>
	 */
	private function excluded_ids(): array {
		$user_id  = get_current_user_id();
		$excluded = array();

		foreach ( $this->restricted_ids() as $post_id ) {
			if ( ! $this->rules->can_access( $post_id, $user_id ) ) {
				$excluded[] = $post_id;
			}
		}

		return $excluded;
	}

	/**
 * @param mixed $post
 */
	public function filter_rest_response( WP_REST_Response $response, $post ): WP_REST_Response {
		if ( ! $post instanceof WP_Post || $this->rules->can_access( $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		foreach ( array( 'content', 'excerpt' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ]['rendered'] = '';

				if ( isset( $data[ $key ]['raw'] ) ) {
					$data[ $key ]['raw'] = '';
				}
			}
		}

		$data['switchyard_restricted'] = true;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * @param array<int, WP_Post> $pages
	 * @return array<int, WP_Post>
	 */
	public function filter_pages( array $pages ): array {
		if ( is_admin() ) {
			return $pages;
		}

		return array_values(
			array_filter(
				$pages,
				fn( WP_Post $page ): bool => $this->rules->can_access( $page->ID )
			)
		);
	}

	public static function flush_cache(): void {
		delete_transient( 'switchyard_restricted_ids' );
	}
}
