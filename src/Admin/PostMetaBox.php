<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Access\QueryFilter;
use Switchyard\Access\Rules;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Sync\SegmentAdapter;
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

	/**
	 * Null when the CRM could not be reached, so callers leave stored tags alone.
	 *
	 * @param list<string> $stored
	 * @return array<string, string>|null
	 */
	private function tag_choices( SegmentAdapter $adapter, array $stored ): ?array {
		try {
			$available = $adapter->available();
		} catch ( CrmException $exception ) {
			return null;
		}

		$choices = array();

		foreach ( $available as $id => $label ) {
			$choices[ (string) $id ] = (string) $label;
		}

		foreach ( $stored as $id ) {
			if ( ! isset( $choices[ $id ] ) ) {
				$choices[ $id ] = '#' . $id;
			}
		}

		return $choices;
	}

	/**
	 * @param array<string, string> $choices
	 * @param list<string>          $stored
	 */
	private function render_tag_select( string $name, string $label, array $choices, array $stored ): void {
		printf( '<label for="%1$s" class="screen-reader-text">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf( '<select id="%1$s" name="%1$s[]" multiple size="8" style="width:100%%;">', esc_attr( $name ) );

		foreach ( $choices as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $id ),
				in_array( (string) $id, $stored, true ) ? 'selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	private function render_tags_unavailable(): void {
		echo '<p class="description">' . esc_html__( 'The tags could not be loaded from the CRM. Saved tags are left unchanged.', 'switchyard-crm' ) . '</p>';
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
		$stored = get_post_meta( $post->ID, self::META_PURCHASE_TAGS, true );
		$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();

		$adapter = $this->tags->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM to choose tags.', 'switchyard-crm' ) . '</p>';

			return;
		}

		$choices = $this->tag_choices( $adapter, $stored );

		if ( null === $choices ) {
			$this->render_tags_unavailable();

			return;
		}

		wp_nonce_field( 'switchyard_purchase_tags_' . $post->ID, 'switchyard_purchase_tags_nonce' );

		printf( '<p><strong>%s</strong></p>', esc_html( $adapter->label() ) );

		$this->render_tag_select( 'switchyard_purchase_tags', __( 'Purchase tags', 'switchyard-crm' ), $choices, $stored );

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
		$enabled = (bool) get_post_meta( $post->ID, Rules::META_ENABLED, true );
		$stored  = get_post_meta( $post->ID, Rules::META_TAGS, true );
		$stored  = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$match   = 'all' === get_post_meta( $post->ID, Rules::META_MATCH, true ) ? 'all' : 'any';

		$adapter = $this->tags->adapter();
		$choices = null;

		if ( null !== $adapter && $adapter->supported() ) {
			$choices = $this->tag_choices( $adapter, $stored );
		}

		wp_nonce_field( 'switchyard_access_' . $post->ID, 'switchyard_access_nonce' );

		printf(
			'<p><label><input type="checkbox" name="switchyard_restrict" value="1" %s> %s</label></p>',
			checked( $enabled, true, false ),
			esc_html__( 'Restrict access', 'switchyard-crm' )
		);

		if ( null !== $adapter && $adapter->supported() && null === $choices ) {
			$this->render_tags_unavailable();

			return;
		}

		if ( null === $adapter || null === $choices ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM to restrict by tag. Without tags this restricts to logged-in users.', 'switchyard-crm' ) . '</p>';

			return;
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $adapter->label() ) );

		echo '<input type="hidden" name="switchyard_tags_present" value="1">';

		$this->render_tag_select( 'switchyard_tags', __( 'Access tags', 'switchyard-crm' ), $choices, $stored );

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

			// Without the tag field the CRM was unreachable when the box
			// rendered, so the stored tags are not this save's to clear.
			if ( ! empty( $_POST['switchyard_tags_present'] ) ) {
				delete_post_meta( $post_id, Rules::META_TAGS );
				delete_post_meta( $post_id, Rules::META_MATCH );
			}
		} else {
			update_post_meta( $post_id, Rules::META_ENABLED, 1 );

			if ( ! empty( $_POST['switchyard_tags_present'] ) ) {
				$tags = isset( $_POST['switchyard_tags'] ) && is_array( $_POST['switchyard_tags'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['switchyard_tags'] ) )
					: array();

				$match = isset( $_POST['switchyard_match'] ) ? sanitize_key( wp_unslash( $_POST['switchyard_match'] ) ) : 'any';

				update_post_meta( $post_id, Rules::META_TAGS, array_values( $tags ) );
				update_post_meta( $post_id, Rules::META_MATCH, 'all' === $match ? 'all' : 'any' );
			}
		}

		QueryFilter::flush_cache();
	}
}
