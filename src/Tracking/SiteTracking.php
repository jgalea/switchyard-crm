<?php

declare( strict_types=1 );

namespace Conduit\Tracking;

use Conduit\Crm\Contracts\SupportsTracking;
use Conduit\Crm\ProviderRegistry;
use Conduit\Settings;
use Conduit\Sync\ContactResolver;

/**
 * Prints the CRM's visitor tracking script, and identifies the visitor when
 * they are logged in.
 *
 * Output is escaped by the provider, which owns the shape of its own snippet.
 * Nothing prints at all unless site tracking is explicitly enabled, because
 * loading a third-party tracker without being asked is a consent problem.
 */
final class SiteTracking {

	private ProviderRegistry $registry;
	private ContactResolver $resolver;
	private Settings $settings;
	public function __construct( ProviderRegistry $registry, ContactResolver $resolver, Settings $settings ) {
		$this->registry = $registry;
		$this->resolver = $resolver;
		$this->settings = $settings;
	}
	public function register_hooks(): void {
		if ( ! $this->settings->bool( 'site_tracking' ) ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'print_scripts' ), 20 );
	}

	public function print_scripts(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! apply_filters( 'conduit_print_tracking', true ) ) {
			return;
		}

		$provider = $this->registry->active();

		if ( ! $provider instanceof SupportsTracking ) {
			return;
		}

		$script = $provider->get_tracking_script();

		if ( '' === $script ) {
			return;
		}

		// Provider snippets are vendor-defined markup, not user input.
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$identify = $provider->get_identify_script(
			$user->user_email,
			$this->resolver->cached_id( $user_id )
		);

		if ( '' !== $identify ) {
			echo $identify; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
