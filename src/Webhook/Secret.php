<?php

declare( strict_types=1 );

namespace Switchyard\Webhook;

/**
 * The shared secret that authenticates inbound CRM calls.
 *
 * Most CRMs do not sign their webhooks, so this is the only thing standing
 * between the endpoint and anyone who guesses the URL.
 */
final class Secret {

	public const OPTION = 'switchyard_webhook_secret';

	public static function generate(): string {
		return wp_generate_password( 32, false, false );
	}

	public static function get(): string {
		$secret = get_option( self::OPTION, '' );

		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = self::generate();

			update_option( self::OPTION, $secret );
		}

		return $secret;
	}

	public static function rotate(): string {
		$secret = self::generate();

		update_option( self::OPTION, $secret );

		return $secret;
	}

	/**
	 * Timing-safe comparison, so the endpoint cannot be used as an oracle to
	 * recover the secret one character at a time.
	 */
	public static function matches( string $candidate ): bool {
		$secret = self::get();

		return '' !== $candidate && hash_equals( $secret, $candidate );
	}

	/**
	 * The URL form, for CRMs that cannot send a custom header.
	 *
	 * Prefer header_name()/get() where the CRM supports it: a secret in a URL
	 * is recorded in access logs and can leak through Referer.
	 */
	public static function url( string $provider_slug ): string {
		return add_query_arg(
			'secret',
			self::get(),
			rest_url( 'switchyard/v1/webhook/' . $provider_slug )
		);
	}

	/**
	 * The endpoint without the secret, for use with the header.
	 */
	public static function endpoint( string $provider_slug ): string {
		return rest_url( 'switchyard/v1/webhook/' . $provider_slug );
	}

	public static function header_name(): string {
		return 'X-Switchyard-Secret';
	}
}
