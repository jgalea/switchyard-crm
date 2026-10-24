<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Access\QueryFilter;
use Switchyard\Access\Rules;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Sync\TagEngine;
use WP_Post;

/**
 * Per-post access rules in the editor sidebar.
 */
final class PostMetaBox {

	public const META_PURCHASE_TAGS = '_switchyard_purchase_tags';

	private Rules $rules;
	private TagEngine $tags;
	public function __construct( Rules $rules, TagEngine $tags ) {
		$this->rules = $rules;
		$this->tags  = $tags;
	}
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_purchase_tags' ), 10, 2 );
	}

	/** @return list<string> */
	private function product_post_types(): array {
		/** @var list<string> $types */
		$types = apply_filters( 'switchyard_purchase_tag_post_types', array( 'product', 'download' ) );

		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	public function add(): void {
		add_meta_box(
			'switchyard-access',
			__( 'Switchyard access', 'switchyard-crm' ),
			array( $this, 'render' ),
			$this->rules->gated_post_types(),
			'side',
			'default'
		);

		$products = $this->product_post_types();

		if ( array() !== $products ) {
			add_meta_box(
				'switchyard-purchase-tags',
				__( 'Switchyard purchase tags', 'switchyard-crm' ),
				array( $this, 'render_purchase_tags' ),
				$products,
				'side',
				'default'
			);
		}
	}

	public function render_purchase_tags( WP_Post $post ): void {
		wp_nonce_field( 'switchyard_purchase_tags_' . $post->ID, 'switchyard_purchase_tags_nonce' );

		$stored = get_post_meta( $post->ID, self::META_PURCHASE_TAGS, true );
		$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();

		$adapter = $this->tags->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM to choose tags.', 'switchyard-crm' ) . '</p>';

			return;
		}

		try {
			$available = $adapter->available();
		} catch ( CrmException $exception ) {
			$available = array();
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $adapter->label() ) );

		echo '<select name="switchyard_purchase_tags[]" multiple size="8" style="width:100%;">';

		foreach ( $available as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $id ),
				in_array( (string) $id, $stored, true ) ? 'selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<p class="description">' . esc_html__( 'Applied to the customer when they buy this. Separate from the access tags above.', 'switchyard-crm' ) . '</p>';
	}

	/**
	 * @param WP_Post $post Unused, but part of the save_post signature.
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
	public function save_purchase_tags( int $post_id, WP_Post $post ): void {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['switchyard_purchase_tags_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['switchyard_purchase_tags_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'switchyard_purchase_tags_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$tags = isset( $_POST['switchyard_purchase_tags'] ) && is_array( $_POST['switchyard_purchase_tags'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['switchyard_purchase_tags'] ) )
			: array();

		if ( array() === $tags ) {
			delete_post_meta( $post_id, self::META_PURCHASE_TAGS );
		} else {
			update_post_meta( $post_id, self::META_PURCHASE_TAGS, array_values( $tags ) );
		}
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'switchyard_access_' . $post->ID, 'switchyard_access_nonce' );

		$enabled = (bool) get_post_meta( $post->ID, Rules::META_ENABLED, true );
		$stored  = get_post_meta( $post->ID, Rules::META_TAGS, true );
		$stored  = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$match   = 'all' === get_post_meta( $post->ID, Rules::META_MATCH, true ) ? 'all' : 'any';

		printf(
			'<p><label><input type="checkbox" name="switchyard_restrict" value="1" %s> %s</label></p>',
			checked( $enabled, true, false ),
			esc_html__( 'Restrict access', 'switchyard-crm' )
		);

		$adapter = $this->tags->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM to restrict by tag. Without tags this restricts to logged-in users.', 'switchyard-crm' ) . '</p>';

			return;
		}

		try {
			$available = $adapter->available();
		} catch ( CrmException $exception ) {
			$available = array();
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $adapter->label() ) );

		echo '<select name="switchyard_tags[]" multiple size="8" style="width:100%;">';

		foreach ( $available as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $id ),
				in_array( (string) $id, $stored, true ) ? 'selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<p><label>' . esc_html__( 'Requires', 'switchyard-crm' ) . ' ';
		echo '<select name="switchyard_match">';
		printf(
			'<option value="any" %s>%s</option>',
			selected( $match, 'any', false ),
			esc_html__( 'any of these', 'switchyard-crm' )
		);
		printf(
			'<option value="all" %s>%s</option>',
			selected( $match, 'all', false ),
			esc_html__( 'all of these', 'switchyard-crm' )
		);
		echo '</select></label></p>';

		echo '<p class="description">' . esc_html__( 'Leave empty to allow any logged-in user.', 'switchyard-crm' ) . '</p>';
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

		$nonce = isset( $_POST['switchyard_access_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['switchyard_access_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'switchyard_access_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['switchyard_restrict'] ) ) {
			delete_post_meta( $post_id, Rules::META_ENABLED );
			delete_post_meta( $post_id, Rules::META_TAGS );
			delete_post_meta( $post_id, Rules::META_MATCH );
		} else {
			$tags = isset( $_POST['switchyard_tags'] ) && is_array( $_POST['switchyard_tags'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['switchyard_tags'] ) )
				: array();

			$match = isset( $_POST['switchyard_match'] ) ? sanitize_key( wp_unslash( $_POST['switchyard_match'] ) ) : 'any';

			update_post_meta( $post_id, Rules::META_ENABLED, 1 );
			update_post_meta( $post_id, Rules::META_TAGS, array_values( $tags ) );
			update_post_meta( $post_id, Rules::META_MATCH, 'all' === $match ? 'all' : 'any' );
		}

		QueryFilter::flush_cache();
	}
}
