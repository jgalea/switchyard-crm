<?php

declare( strict_types=1 );

namespace Conduit\Access;

use Conduit\Sync\TagEngine;

/**
 * Shortcodes for conditional content inside a post.
 *
 * The wrapping shortcodes return their own enclosed content, which is post
 * content the author wrote in the editor and WordPress already ran through
 * kses on save for anyone without unfiltered_html. Running it through kses a
 * second time on output would strip embeds and the markup produced by nested
 * shortcodes, so the content is returned as authored. Values read from user
 * meta are escaped, in render_field.
 */
final class Shortcodes {

	private TagEngine $tags;
	public function __construct( TagEngine $tags ) {
		$this->tags = $tags;
	}

	public function register_hooks(): void {
		add_shortcode( 'conduit_if', array( $this, 'render_if' ) );
		add_shortcode( 'conduit_logged_in', array( $this, 'render_logged_in' ) );
		add_shortcode( 'conduit_logged_out', array( $this, 'render_logged_out' ) );
		add_shortcode( 'conduit_field', array( $this, 'render_field' ) );
	}

	/**
 * [conduit_if tag="VIP" match="any" not="false"]…[/conduit_if]
 *
 * @param mixed $atts
 */
	public function render_if( $atts, ?string $content = null ): string {
		$atts = shortcode_atts(
			array(
				'tag'   => '',
				'match' => 'any',
				'not'   => 'false',
			),
			is_array( $atts ) ? $atts : array(),
			'conduit_if'
		);

		if ( null === $content ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$required = array_values( array_filter( array_map( 'trim', explode( ',', $atts['tag'] ) ) ) );

		$matches = 'all' === $atts['match']
			? $this->tags->has_all_tags( $user_id, $required )
			: $this->tags->has_any_tag( $user_id, $required );

		$negate = in_array( strtolower( $atts['not'] ), array( 'true', '1', 'yes' ), true );

		if ( $negate ) {
			$matches = ! $matches;
		}

		return $matches ? do_shortcode( $content ) : '';
	}

	/** @param mixed $atts */
	public function render_logged_in( $atts, ?string $content = null ): string {
		return ( is_user_logged_in() && null !== $content ) ? do_shortcode( $content ) : '';
	}

	/** @param mixed $atts */
	public function render_logged_out( $atts, ?string $content = null ): string {
		return ( ! is_user_logged_in() && null !== $content ) ? do_shortcode( $content ) : '';
	}

	/**
 * [conduit_field key="first_name" default="there"]
 *
 * @param mixed $atts
 */
	public function render_field( $atts ): string {
		$atts = shortcode_atts(
			array(
				'key'     => '',
				'default' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'conduit_field'
		);

		$user_id = get_current_user_id();

		if ( 0 === $user_id || '' === $atts['key'] ) {
			return esc_html( $atts['default'] );
		}

		$user = get_userdata( $user_id );

		switch ( $atts['key'] ) {
			case 'user_email':
				$value = $user ? $user->user_email : '';
				break;
			case 'display_name':
				$value = $user ? $user->display_name : '';
				break;
			case 'user_login':
				$value = $user ? $user->user_login : '';
				break;
			default:
				$value = get_user_meta( $user_id, $atts['key'], true );
				break;
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		return esc_html( '' !== $value ? $value : $atts['default'] );
	}
}
