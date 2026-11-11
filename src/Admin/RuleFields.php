<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Access\MenuFilter;
use Switchyard\Access\QueryFilter;
use Switchyard\Access\Rules;
use WP_Post;
use WP_Term;

/**
 * Access rule fields on term edit screens and menu items.
 */
final class RuleFields {

	private Rules $rules;
	private TagPicker $picker;
	public function __construct( Rules $rules, TagPicker $picker ) {
		$this->rules  = $rules;
		$this->picker = $picker;
	}
	public function register_hooks(): void {
		// Taxonomies are registered on init, after this runs.
		add_action( 'init', array( $this, 'register_term_hooks' ), 20 );
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_menu_item' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_menu_item' ), 10, 2 );
	}

	public function register_term_hooks(): void {
		foreach ( $this->taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_term_add' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_edit' ) );
			add_action( "created_{$taxonomy}", array( $this, 'save_term' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save_term' ) );
		}
	}

	/**
	 * @return list<string>
	 */
	private function taxonomies(): array {
		$taxonomies = array();

		foreach ( $this->rules->gated_post_types() as $post_type ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $taxonomy ) {
				if ( $taxonomy->public && $taxonomy->show_ui ) {
					$taxonomies[ $name ] = $name;
				}
			}
		}

		return array_values( $taxonomies );
	}

	public function render_term_add(): void {
		$choices = $this->picker->choices();

		if ( null === $choices ) {
			return;
		}

		wp_nonce_field( 'switchyard_term_rule', 'switchyard_term_nonce' );

		echo '<div class="form-field"><label for="switchyard-term-tags">' . esc_html__( 'Switchyard access tags', 'switchyard-crm' ) . '</label>';
		$this->picker->render( 'switchyard_term_tags', 'switchyard-term-tags', __( 'Switchyard access tags', 'switchyard-crm' ), $choices, array() );
		echo '<p>' . esc_html__( 'Posts in this term need one of these tags. Leave empty for no rule.', 'switchyard-crm' ) . '</p></div>';
	}

	public function render_term_edit( WP_Term $term ): void {
		$choices = $this->picker->choices();

		if ( null === $choices ) {
			return;
		}

		$stored = get_term_meta( $term->term_id, Rules::TERM_META_TAGS, true );
		$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$match  = 'all' === get_term_meta( $term->term_id, Rules::TERM_META_MATCH, true ) ? 'all' : 'any';

		wp_nonce_field( 'switchyard_term_rule', 'switchyard_term_nonce' );

		echo '<tr class="form-field"><th scope="row"><label for="switchyard-term-tags">' . esc_html__( 'Switchyard access tags', 'switchyard-crm' ) . '</label></th><td>';
		$this->picker->render( 'switchyard_term_tags', 'switchyard-term-tags', __( 'Switchyard access tags', 'switchyard-crm' ), $choices, $stored );
		echo '<p><label for="switchyard-term-match">' . esc_html__( 'Requires', 'switchyard-crm' ) . '</label> ';
		echo '<select id="switchyard-term-match" name="switchyard_term_match">';
		printf( '<option value="any" %s>%s</option>', selected( $match, 'any', false ), esc_html__( 'any of these', 'switchyard-crm' ) );
		printf( '<option value="all" %s>%s</option>', selected( $match, 'all', false ), esc_html__( 'all of these', 'switchyard-crm' ) );
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Posts in this term need these tags unless the post sets its own rule.', 'switchyard-crm' ) . '</p>';
		echo '</td></tr>';
	}

	public function save_term( int $term_id ): void {
		$nonce = isset( $_POST['switchyard_term_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['switchyard_term_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'switchyard_term_rule' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$tags = TagPicker::posted( $_POST['switchyard_term_tags'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.

		if ( array() === $tags ) {
			delete_term_meta( $term_id, Rules::TERM_META_TAGS );
			delete_term_meta( $term_id, Rules::TERM_META_MATCH );
		} else {
			$match = isset( $_POST['switchyard_term_match'] ) ? sanitize_key( wp_unslash( $_POST['switchyard_term_match'] ) ) : 'any';

			update_term_meta( $term_id, Rules::TERM_META_TAGS, $tags );
			update_term_meta( $term_id, Rules::TERM_META_MATCH, 'all' === $match ? 'all' : 'any' );
		}

		QueryFilter::flush_cache();
	}

	/**
	 * @param int|string $item_id
	 * @param mixed      $item
	 */
	public function render_menu_item( $item_id, $item = null ): void {
		$item_id = (int) $item_id;
		$choices = $this->picker->choices();
		$state   = get_post_meta( $item_id, MenuFilter::META_STATE, true );
		$match   = 'all' === get_post_meta( $item_id, MenuFilter::META_MATCH, true ) ? 'all' : 'any';
		$stored  = get_post_meta( $item_id, MenuFilter::META_TAGS, true );
		$stored  = is_array( $stored ) ? array_map( 'strval', $stored ) : array();

		unset( $item );

		echo '<fieldset class="field-switchyard description-wide"><legend><strong>' . esc_html__( 'Switchyard visibility', 'switchyard-crm' ) . '</strong></legend>';

		printf( '<p><label for="switchyard-menu-state-%1$d">%2$s</label> ', absint( $item_id ), esc_html__( 'Show to', 'switchyard-crm' ) );
		printf( '<select id="switchyard-menu-state-%1$d" name="switchyard_menu_state[%1$d]">', absint( $item_id ) );

		foreach (
			array(
				''           => __( 'Everyone', 'switchyard-crm' ),
				'logged_in'  => __( 'Logged-in users', 'switchyard-crm' ),
				'logged_out' => __( 'Logged-out visitors', 'switchyard-crm' ),
			) as $value => $label
		) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( (string) $state, $value, false ), esc_html( $label ) );
		}

		echo '</select></p>';

		if ( null !== $choices ) {
			echo '<p>';
			$this->picker->render( 'switchyard_menu_tags[' . $item_id . ']', 'switchyard-menu-tags-' . $item_id, __( 'Required tags', 'switchyard-crm' ), $choices, $stored );
			echo '</p>';
			printf( '<p><label for="switchyard-menu-match-%1$d">%2$s</label> ', absint( $item_id ), esc_html__( 'Requires', 'switchyard-crm' ) );
			printf( '<select id="switchyard-menu-match-%1$d" name="switchyard_menu_match[%1$d]">', absint( $item_id ) );
			printf( '<option value="any" %s>%s</option>', selected( $match, 'any', false ), esc_html__( 'any of these', 'switchyard-crm' ) );
			printf( '<option value="all" %s>%s</option>', selected( $match, 'all', false ), esc_html__( 'all of these', 'switchyard-crm' ) );
			echo '</select></p>';
			echo '<input type="hidden" name="switchyard_menu_present[' . esc_attr( (string) $item_id ) . ']" value="1">';
		}

		echo '</fieldset>';
	}

	public function save_menu_item( int $menu_id, int $item_id ): void {
		unset( $menu_id );

		$nonce = isset( $_POST['update-nav-menu-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'update-nav_menu' ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$state = isset( $_POST['switchyard_menu_state'][ $item_id ] ) ? sanitize_key( wp_unslash( $_POST['switchyard_menu_state'][ $item_id ] ) ) : null;

		if ( null !== $state ) {
			if ( in_array( $state, array( 'logged_in', 'logged_out' ), true ) ) {
				update_post_meta( $item_id, MenuFilter::META_STATE, $state );
			} else {
				delete_post_meta( $item_id, MenuFilter::META_STATE );
			}
		}

		// Tags are only posted when the CRM's tag list loaded.
		if ( empty( $_POST['switchyard_menu_present'][ $item_id ] ) ) {
			return;
		}

		$tags = TagPicker::posted( $_POST['switchyard_menu_tags'][ $item_id ] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.

		if ( array() === $tags ) {
			delete_post_meta( $item_id, MenuFilter::META_TAGS );
			delete_post_meta( $item_id, MenuFilter::META_MATCH );

			return;
		}

		$match = isset( $_POST['switchyard_menu_match'][ $item_id ] ) ? sanitize_key( wp_unslash( $_POST['switchyard_menu_match'][ $item_id ] ) ) : 'any';

		update_post_meta( $item_id, MenuFilter::META_TAGS, $tags );
		update_post_meta( $item_id, MenuFilter::META_MATCH, 'all' === $match ? 'all' : 'any' );
	}
}
