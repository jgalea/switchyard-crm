<?php

declare( strict_types=1 );

namespace Conduit\Crm\Provider;

use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Exception\CrmException;

/**
 * Refresh-token OAuth, shared by the providers that use it.
 *
 * Access tokens are cached in a transient with a safety margin so a token
 * that is technically still valid is refreshed before it expires mid-request.
 * Refresh tokens live in the settings and are never logged.
 */
trait RefreshesOAuthToken {

	/**
	 * Seconds of headroom before expiry, so a token that is technically still
	 * valid is refreshed rather than expiring mid-request.
	 *
	 * A method rather than a constant because constants in traits need PHP 8.2
	 * and this plugin supports 8.1.
	 */
	private function token_margin(): int {
		return 120;
	}

	/**
	 * Token endpoint for this provider.
	 */
	abstract protected function token_url(): string;

	/**
	 * Extra parameters the provider's token endpoint requires.
	 *
	 * @return array<string, string>
	 */
	protected function token_params(): array {
		return array();
	}

	private function token_transient(): string {
		return 'conduit_oauth_' . $this->get_slug();
	}

	protected function access_token(): string {
		$cached = get_transient( $this->token_transient() );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->refresh_access_token();
	}

	/**
	 * @throws ConnectionException
	 */
	protected function refresh_access_token(): string {
		$refresh_token = $this->credential( 'refresh_token' );

		if ( '' === $refresh_token ) {
			throw new ConnectionException(
				esc_html__( 'No refresh token is stored. Reconnect the account.', 'conduit-crm' )
			);
		}

		$params = array_merge(
			array(
				'refresh_token' => $refresh_token,
				'client_id'     => $this->credential( 'client_id' ),
				'client_secret' => $this->credential( 'client_secret' ),
				'grant_type'    => 'refresh_token',
			),
			$this->token_params()
		);

		try {
			$response = $this->client_factory
				->for_provider( $this->get_slug() )
				->base_url( '' )
				->headers( array( 'Content-Type' => 'application/x-www-form-urlencoded' ) )
				->request( 'POST', add_query_arg( $params, $this->token_url() ) );
		} catch ( CrmException $e ) {
			throw new ConnectionException(
				esc_html__( 'Could not refresh the access token. Reconnect the account.', 'conduit-crm' ),
				array( 'error' => esc_html( $e->getMessage() ) )
			);
		}

		$token = $response->path( 'access_token' );

		if ( ! is_string( $token ) || '' === $token ) {
			throw new ConnectionException( esc_html__( 'The token endpoint did not return an access token.', 'conduit-crm' ) );
		}

		$expires = (int) $response->path( 'expires_in', 3600 );

		set_transient( $this->token_transient(), $token, max( 60, $expires - $this->token_margin() ) );

		return $token;
	}

	protected function forget_access_token(): void {
		delete_transient( $this->token_transient() );
	}
}
