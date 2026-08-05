<?php

declare( strict_types=1 );

namespace Conduit\Admin;

use Conduit\Access\QueryFilter;
use Conduit\Access\Rules;
use Conduit\Crm\Exception\CrmException;
use Conduit\Sync\TagEngine;
use WP_Post;

/**
 * Per-post access rules in the editor sidebar.
 */
final class PostMetaBox {

	private Rules $rules;
	private TagEngine $tags;
	public function __construct( Rules $rules, TagEngine $tags ) {
		$this->rules = $rules;
		$this->tags  = $tags;
	}
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function add(): void {
		add_meta_box(
			'conduit-access',
			__( 'Conduit access', 'conduit-crm' ),
			array( $this, 'render' ),
			$this->rules->gated_post_types(),
			'side',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'conduit_access_' . $post->ID, 'conduit_access_nonce' );

		$enabled = (bool) get_post_meta( $post->ID, Rules::META_ENABLED, true );
		$stored  = get_post_meta( $post->ID, Rules::META_TAGS, true );
		$stored  = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$match   = 'all' === get_post_meta( $post->ID, Rules::META_MATCH, true ) ? 'all' : 'any';

		printf(
			'<p><label><input type="checkbox" name="conduit_restrict" value="1" %s> %s</label></p>',
			checked( $enabled, true, false ),
			esc_html__( 'Restrict access', 'conduit-crm' )
		);

		$adapter = $this->tags->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM to restrict by tag. Without tags this restricts to logged-in users.', 'conduit-crm' ) . '</p>';

			return;
		}

		try {
			$available = $adapter->available();
		} catch ( CrmException $exception ) {
			$available = array();
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $adapter->label() ) );

		echo '<select name="conduit_tags[]" multiple size="8" style="width:100%;">';

		foreach ( $available as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $id ),
				in_array( (string) $id, $stored, true ) ? 'selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<p><label>' . esc_html__( 'Requires', 'conduit-crm' ) . ' ';
		echo '<select name="conduit_match">';
		printf(
			'<option value="any" %s>%s</option>',
			selected( $match, 'any', false ),
			esc_html__( 'any of these', 'conduit-crm' )
		);
		printf(
			'<option value="all" %s>%s</option>',
			selected( $match, 'all', false ),
			esc_html__( 'all of these', 'conduit-crm' )
		);
		echo '</select></label></p>';

		echo '<p class="description">' . esc_html__( 'Leave empty to allow any logged-in user.', 'conduit-crm' ) . '</p>';
	}

	/**
	 * @param WP_Post $post Unused, but part of the save_post signature.
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
	public function save( int $post_id, WP_Post $post ): void {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['conduit_access_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['conduit_access_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'conduit_access_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['conduit_restrict'] ) ) {
			delete_post_meta( $post_id, Rules::META_ENABLED );
			delete_post_meta( $post_id, Rules::META_TAGS );
			delete_post_meta( $post_id, Rules::META_MATCH );
		} else {
			$tags = isset( $_POST['conduit_tags'] ) && is_array( $_POST['conduit_tags'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['conduit_tags'] ) )
				: array();

			$match = isset( $_POST['conduit_match'] ) ? sanitize_key( wp_unslash( $_POST['conduit_match'] ) ) : 'any';

			update_post_meta( $post_id, Rules::META_ENABLED, 1 );
			update_post_meta( $post_id, Rules::META_TAGS, array_values( $tags ) );
			update_post_meta( $post_id, Rules::META_MATCH, 'all' === $match ? 'all' : 'any' );
		}

		QueryFilter::flush_cache();
	}
}
