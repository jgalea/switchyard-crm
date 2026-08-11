<?php

declare( strict_types=1 );

namespace Conduit\Tracking;

use Conduit\Crm\Contracts\SupportsTracking;
use Conduit\Crm\ProviderRegistry;
use Conduit\Settings;
use Conduit\Sync\ContactResolver;

/**
 * Enqueues the CRM's visitor tracking script, and identifies the visitor when
 * they are logged in.
 *
 * The vendor file is enqueued and its inline JavaScript is attached to a
 * dependency handle, so the queue the vendor snippet expects exists before the
 * file loads and the file itself stays async. Nothing is enqueued at all unless
 * site tracking is explicitly enabled, because loading a third-party tracker
 * without being asked is a consent problem.
 */
final class SiteTracking {

	private const HANDLE      = 'conduit-tracking';
	private const HANDLE_BOOT = 'conduit-tracking-boot';

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

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function enqueue_scripts(): void {
		if ( ! apply_filters( 'conduit_print_tracking', true ) ) {
			return;
		}

		$provider = $this->registry->active();

		if ( ! $provider instanceof SupportsTracking ) {
			return;
		}

		$src    = $provider->get_tracking_src();
		$inline = $provider->get_tracking_inline();

		if ( '' === $src && '' === $inline ) {
			return;
		}

		$inline .= $this->identify_script( $provider );

		if ( '' !== $inline ) {
			wp_register_script( self::HANDLE_BOOT, false, array(), CONDUIT_VERSION, false );
			wp_enqueue_script( self::HANDLE_BOOT );
			wp_add_inline_script( self::HANDLE_BOOT, $inline );
		}

		if ( '' === $src ) {
			return;
		}

		// No version argument: the URL belongs to the CRM's own CDN, which
		// handles its cache busting, and appending ours would fragment it.
		wp_enqueue_script(
			self::HANDLE,
			$src,
			'' !== $inline ? array( self::HANDLE_BOOT ) : array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			array(
				'in_footer' => false,
				'strategy'  => 'async',
			)
		);
	}

	private function identify_script( SupportsTracking $provider ): string {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return '';
		}

		return $provider->get_identify_inline(
			$user->user_email,
			$this->resolver->cached_id( $user_id )
		);
	}
}
