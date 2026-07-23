<?php

declare( strict_types=1 );

namespace Conduit\Access;

use Conduit\Sync\TagEngine;

/**
 * Shortcodes for conditional content inside a post.
 */
final class Shortcodes {

	public function __construct( private TagEngine $tags ) {}

	public function register_hooks(): void {
		add_shortcode( 'conduit_if', array( $this, 'render_if' ) );
		add_shortcode( 'conduit_logged_in', array( $this, 'render_logged_in' ) );
		add_shortcode( 'conduit_logged_out', array( $this, 'render_logged_out' ) );
		add_shortcode( 'conduit_field', array( $this, 'render_field' ) );
	}

	/**
	 * [conduit_if tag="VIP" match="any" not="false"]…[/conduit_if]
	 *
	 * @param array<string, string>|string $atts
	 */
	public function render_if( mixed $atts, ?string $content = null ): string {
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

	/** @param array<string, string>|string $atts */
	public function render_logged_in( mixed $atts, ?string $content = null ): string {
		return ( is_user_logged_in() && null !== $content ) ? do_shortcode( $content ) : '';
	}

	/** @param array<string, string>|string $atts */
	public function render_logged_out( mixed $atts, ?string $content = null ): string {
		return ( ! is_user_logged_in() && null !== $content ) ? do_shortcode( $content ) : '';
	}

	/**
	 * [conduit_field key="first_name" default="there"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public function render_field( mixed $atts ): string {
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

		$value = match ( $atts['key'] ) {
			'user_email'   => $user ? $user->user_email : '',
			'display_name' => $user ? $user->display_name : '',
			'user_login'   => $user ? $user->user_login : '',
			default        => get_user_meta( $user_id, $atts['key'], true ),
		};

		$value = is_scalar( $value ) ? (string) $value : '';

		return esc_html( '' !== $value ? $value : $atts['default'] );
	}
}
