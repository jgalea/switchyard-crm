<?php

declare( strict_types=1 );

namespace Switchyard\Access;

use Switchyard\Admin\TagPicker;
use Switchyard\Sync\TagEngine;

/**
 * Per-block visibility: any block can be limited to logged-in users, logged-out
 * visitors, or users holding CRM tags.
 *
 * The rule is stored in the block's own attributes, so it travels with the
 * content and needs no server-side registration.
 */
final class BlockVisibility {

	private TagEngine $tags;
	private TagPicker $picker;
	public function __construct( TagEngine $tags, TagPicker $picker ) {
		$this->tags   = $tags;
		$this->picker = $picker;
	}
	public function register_hooks(): void {
		add_filter( 'render_block', array( $this, 'filter_block' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	public function filter_block( string $content, array $block ): string {
		$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$audience = isset( $attrs['switchyardAudience'] ) && is_string( $attrs['switchyardAudience'] ) ? $attrs['switchyardAudience'] : '';

		if ( '' === $audience ) {
			return $content;
		}

		$user_id = get_current_user_id();

		if ( 'logged_out' === $audience ) {
			return 0 === $user_id ? $content : '';
		}

		if ( 0 === $user_id ) {
			return '';
		}

		if ( 'tags' !== $audience ) {
			return $content;
		}

		$required = is_array( $attrs['switchyardTags'] ?? null ) ? array_values( array_map( 'strval', $attrs['switchyardTags'] ) ) : array();

		if ( array() === $required ) {
			return $content;
		}

		$allowed = 'all' === ( $attrs['switchyardMatch'] ?? 'any' )
			? $this->tags->has_all_tags( $user_id, $required )
			: $this->tags->has_any_tag( $user_id, $required );

		return $allowed ? $content : '';
	}

	public function enqueue_editor(): void {
		wp_enqueue_script(
			'switchyard-block-visibility',
			SWITCHYARD_URL . 'assets/block-visibility.js',
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			SWITCHYARD_VERSION,
			true
		);

		wp_set_script_translations( 'switchyard-block-visibility', 'switchyard-crm' );

		$options = array();

		foreach ( $this->picker->choices() ?? array() as $id => $label ) {
			$options[] = array(
				'value' => (string) $id,
				'label' => $label,
			);
		}

		wp_add_inline_script(
			'switchyard-block-visibility',
			'window.switchyardBlockVisibility = ' . wp_json_encode( array( 'tags' => $options ) ) . ';',
			'before'
		);
	}
}
