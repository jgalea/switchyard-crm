<?php

declare( strict_types=1 );

namespace Conduit\Access;

use Conduit\Sync\TagEngine;
use WP_Post;

/**
 * Hides menu items whose target the visitor cannot reach, and menu items with
 * their own tag rules.
 *
 * A link to a page that returns "access denied" is worse than no link, so
 * this runs even when query filtering is off.
 */
final class MenuFilter {

	private Rules $rules;
	private TagEngine $tags;
	public const META_TAGS  = '_conduit_menu_tags';
	public const META_MATCH = '_conduit_menu_match';
	public const META_STATE = '_conduit_menu_state';

	public function __construct( Rules $rules, TagEngine $tags ) {
		$this->rules = $rules;
		$this->tags  = $tags;
	}

	public function register_hooks(): void {
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_items' ), 20 );
	}

	/**
 * @param mixed $items
 * @return array<int, WP_Post>|false
 */
	public function filter_items( $items ) {
		if ( ! is_array( $items ) || is_admin() ) {
			return $items;
		}

		$user_id = get_current_user_id();

		$visible = array_filter(
			$items,
			fn( WP_Post $item ): bool => $this->is_visible( $item, $user_id )
		);

		// Drop orphaned children so a hidden parent does not leave its
		// submenu floating at the top level.
		$visible_ids = array_map( static fn( WP_Post $item ): int => $item->ID, $visible );

		return array_values(
			array_filter(
				$visible,
				static function ( WP_Post $item ) use ( $visible_ids ): bool {
					$parent = (int) ( $item->menu_item_parent ?? 0 );

					return 0 === $parent || in_array( $parent, $visible_ids, true );
				}
			)
		);
	}

	private function is_visible( WP_Post $item, int $user_id ): bool {
		$state = get_post_meta( $item->ID, self::META_STATE, true );

		if ( 'logged_in' === $state && 0 === $user_id ) {
			return false;
		}

		if ( 'logged_out' === $state && $user_id > 0 ) {
			return false;
		}

		$required = get_post_meta( $item->ID, self::META_TAGS, true );

		if ( is_array( $required ) && ! empty( $required ) ) {
			if ( 0 === $user_id ) {
				return false;
			}

			$stored_match = get_post_meta( $item->ID, self::META_MATCH, true );
			$match        = is_string( $stored_match ) && '' !== $stored_match ? $stored_match : 'any';

			$ok = 'all' === $match
				? $this->tags->has_all_tags( $user_id, array_map( 'strval', $required ) )
				: $this->tags->has_any_tag( $user_id, array_map( 'strval', $required ) );

			if ( ! $ok ) {
				return false;
			}
		}

		// Inherit the target's own restriction.
		if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
			return $this->rules->can_access( (int) $item->object_id, $user_id );
		}

		return true;
	}
}
