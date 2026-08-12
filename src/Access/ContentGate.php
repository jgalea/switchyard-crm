<?php

declare( strict_types=1 );

namespace Switchyard\Access;

use Switchyard\Settings;
use WP_Post;

/**
 * Enforces access rules on the front end.
 *
 * Gating only the_content is the usual mistake: the excerpt, the feed, the
 * REST response and the search results all still leak the material. This
 * class covers the singular view; QueryFilter covers the rest.
 */
final class ContentGate {

	private Rules $rules;
	private Settings $settings;
	public function __construct( Rules $rules, Settings $settings ) {
		$this->rules    = $rules;
		$this->settings = $settings;
	}
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'guard_singular' ), 5 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 20, 2 );
	}

	public function guard_singular(): void {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( $this->rules->can_access( $post->ID ) ) {
			return;
		}

		do_action( 'switchyard_access_denied', $post->ID, get_current_user_id() );

		$redirect = $this->rules->redirect_for( $post->ID );

		if ( 0 === $redirect ) {
			$redirect = $this->settings->int( 'restricted_redirect' );
		}

		if ( $redirect > 0 ) {
			$url = get_permalink( $redirect );

			if ( is_string( $url ) ) {
				wp_safe_redirect( $url, 302 );
				exit;
			}
		}

		/** @var string|null $custom */
		$custom = apply_filters( 'switchyard_access_denied_response', null, $post->ID );

		if ( is_string( $custom ) ) {
			wp_die(
				wp_kses_post( $custom ),
				esc_html__( 'Access denied', 'switchyard-crm' ),
				array( 'response' => 403 )
			);
		}
	}

	public function filter_content( string $content ): string {
		$post_id = get_the_ID();

		if ( ! is_int( $post_id ) || $this->rules->can_access( $post_id ) ) {
			return $content;
		}

		return $this->message( $post_id );
	}

	/**
 * @param mixed $post
 */
	public function filter_excerpt( string $excerpt, $post = null ): string {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;

		if ( $post_id <= 0 || $this->rules->can_access( $post_id ) ) {
			return $excerpt;
		}

		return '';
	}

	private function message( int $post_id ): string {
		$message = $this->settings->string( 'restricted_message' );

		// Translated here, at render time, rather than in the settings
		// defaults, which are read before init.
		if ( '' === $message ) {
			$message = __( 'You do not have access to this content.', 'switchyard-crm' );
		}

		/** @var string $message */
		$message = apply_filters( 'switchyard_restricted_message', $message, $post_id );

		return sprintf(
			'<div class="switchyard-restricted">%s</div>',
			wp_kses_post( wpautop( $message ) )
		);
	}
}
