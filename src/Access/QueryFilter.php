<?php

declare( strict_types=1 );

namespace Conduit\Access;

use Conduit\Settings;
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

	public function __construct(
		private Rules $rules,
		private Settings $settings,
	) {}

	public function register_hooks(): void {
		if ( ! $this->settings->bool( 'filter_queries' ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'exclude_restricted' ) );
		add_filter( 'rest_prepare_post', array( $this, 'filter_rest_response' ), 10, 2 );
		add_filter( 'get_pages', array( $this, 'filter_pages' ) );
	}

	public function exclude_restricted( WP_Query $query ): void {
		if ( is_admin() || $query->is_singular() ) {
			return;
		}

		if ( ! apply_filters( 'conduit_filter_query', true, $query ) ) {
			return;
		}

		$restricted = $this->restricted_ids();

		if ( empty( $restricted ) ) {
			return;
		}

		$user_id  = get_current_user_id();
		$excluded = array();

		foreach ( $restricted as $post_id ) {
			if ( ! $this->rules->can_access( $post_id, $user_id ) ) {
				$excluded[] = $post_id;
			}
		}

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
		$cached = get_transient( 'conduit_restricted_ids' );

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

		set_transient( 'conduit_restricted_ids', $ids, HOUR_IN_SECONDS );

		return $ids;
	}

	/**
	 * @return list<int>
	 */
	private function posts_in_restricted_terms(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value != ''",
				Rules::TERM_META_TAGS
			)
		);

		if ( empty( $term_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $this->rules->gated_post_types(),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'category',
						'field'            => 'term_id',
						'terms'            => array_map( 'intval', $term_ids ),
						'include_children' => true,
					),
				),
			)
		);

		return array_map( 'intval', is_array( $posts ) ? $posts : array() );
	}

	/**
	 * @param WP_Post $post
	 */
	public function filter_rest_response( WP_REST_Response $response, mixed $post ): WP_REST_Response {
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

		$data['conduit_restricted'] = true;

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
		delete_transient( 'conduit_restricted_ids' );
	}
}
