<?php

declare( strict_types=1 );

namespace Switchyard\Access;

use Switchyard\Sync\TagEngine;
use WP_Post;

/**
 * Decides whether a user may see a piece of content.
 *
 * Rules live in post meta. Taxonomy-level rules are inherited by posts in
 * that term unless the post sets its own, which is what lets a site gate a
 * whole category without touching each post.
 */
final class Rules {

	private TagEngine $tags;
	public const META_ENABLED  = '_switchyard_restrict';
	public const META_TAGS     = '_switchyard_tags';
	public const META_MATCH    = '_switchyard_match';
	public const META_REDIRECT = '_switchyard_redirect';

	public const TERM_META_TAGS  = 'switchyard_tags';
	public const TERM_META_MATCH = 'switchyard_match';

	/** @var array<string, bool> */
	private array $cache = array();

	public function __construct( TagEngine $tags ) {
		$this->tags = $tags;
	}

	/**
	 * Whether the post is gated at all.
	 */
	public function is_restricted( int $post_id ): bool {
		if ( get_post_meta( $post_id, self::META_ENABLED, true ) ) {
			return true;
		}

		return ! empty( $this->inherited_rule( $post_id ) );
	}

	/**
	 * @return array{tags: list<string>, match: string}|null
	 */
	private function inherited_rule( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$tags = get_term_meta( $term->term_id, self::TERM_META_TAGS, true );

				if ( is_array( $tags ) && ! empty( $tags ) ) {
					return array(
						'tags'  => array_map( 'strval', $tags ),
						'match' => $this->match_mode( get_term_meta( $term->term_id, self::TERM_META_MATCH, true ) ),
					);
				}
			}
		}

		return null;
	}

	/**
	 * @return array{tags: list<string>, match: string}
	 */
	public function rule_for( int $post_id ): array {
		if ( get_post_meta( $post_id, self::META_ENABLED, true ) ) {
			$tags = get_post_meta( $post_id, self::META_TAGS, true );

			return array(
				'tags'  => is_array( $tags ) ? array_map( 'strval', $tags ) : array(),
				'match' => $this->match_mode( get_post_meta( $post_id, self::META_MATCH, true ) ),
			);
		}

		return $this->inherited_rule( $post_id ) ?? array(
			'tags'  => array(),
			'match' => 'any',
		);
	}

	/**
	 * The one question the rest of the plugin asks.
	 */
	public function can_access( int $post_id, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		// A composite string, never arithmetic on the two IDs. Multiplying the
		// post ID by a fixed factor and adding the user ID collides once user
		// IDs reach seven figures, which would hand one visitor another
		// visitor's cached access decision.
		$cache_key = $post_id . ':' . $user_id;

		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		$allowed = $this->evaluate( $post_id, $user_id );

		/** @var bool $allowed */
		$allowed = apply_filters( 'switchyard_can_access', $allowed, $post_id, $user_id );

		$this->cache[ $cache_key ] = $allowed;

		return $allowed;
	}

	private function evaluate( int $post_id, int $user_id ): bool {
		if ( ! $this->is_restricted( $post_id ) ) {
			return true;
		}

		// Anyone who can edit the post can see it, or a site owner locks
		// themselves out of their own content the moment the CRM is down.
		if ( $user_id > 0 && user_can( $user_id, 'edit_post', $post_id ) ) {
			return true;
		}

		if ( 0 === $user_id ) {
			return false;
		}

		$rule = $this->rule_for( $post_id );

		// A restriction with no tags means "logged in only", which is a
		// deliberate and commonly used configuration.
		if ( empty( $rule['tags'] ) ) {
			return true;
		}

		return 'all' === $rule['match']
			? $this->tags->has_all_tags( $user_id, $rule['tags'] )
			: $this->tags->has_any_tag( $user_id, $rule['tags'] );
	}

	/**
 * Normalises a stored match mode, defaulting to "any".
 * @param mixed $stored
 */
	private function match_mode( $stored ): string {
		return 'all' === $stored ? 'all' : 'any';
	}

	public function redirect_for( int $post_id ): int {
		$redirect = (int) get_post_meta( $post_id, self::META_REDIRECT, true );

		return $redirect > 0 ? $redirect : 0;
	}

	/**
	 * Post types the gate applies to.
	 *
	 * @return list<string>
	 */
	public function gated_post_types(): array {
		$types = get_post_types( array( 'public' => true ) );

		unset( $types['attachment'] );

		/** @var list<string> $types */
		$types = apply_filters( 'switchyard_gated_post_types', array_values( $types ) );

		return $types;
	}

	public function flush(): void {
		$this->cache = array();
	}
}
