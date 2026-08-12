<?php

declare( strict_types=1 );

namespace Switchyard\Webhook;

use Switchyard\Crm\Contracts\SupportsWebhooks;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Inbound endpoint for CRM automations.
 *
 * Deliberately does not trust tag data in the request body. A webhook body
 * can be replayed or forged; the tags are always re-read from the CRM after
 * the call, so the worst a bad request can do is cause an extra API read.
 */
final class Listener {

	private ProviderRegistry $registry;
	private ContactResolver $resolver;
	private TagEngine $tags;
	private UserSync $sync;
	private Settings $settings;
	private Logger $logger;
	public function __construct( ProviderRegistry $registry, ContactResolver $resolver, TagEngine $tags, UserSync $sync, Settings $settings, Logger $logger ) {
		$this->registry = $registry;
		$this->resolver = $resolver;
		$this->tags     = $tags;
		$this->sync     = $sync;
		$this->settings = $settings;
		$this->logger   = $logger;
	}
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'switchyard/v1',
			'/webhook/(?P<provider>[a-z0-9\-]+)',
			array(
				'methods'             => array( WP_REST_Server::CREATABLE, WP_REST_Server::READABLE ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
 * @return bool|\WP_Error
 */
	public function authorise( WP_REST_Request $request ) {
		// A header is preferred over the query parameter: a secret in the URL
		// lands in the web server's access log and can leak through Referer.
		// The query parameter stays supported because several CRMs cannot send
		// a custom header on a webhook.
		$secret = $request->get_header( 'x_switchyard_secret' );

		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = $request->get_param( 'secret' );
		}

		if ( ! is_string( $secret ) || ! Secret::matches( $secret ) ) {
			$this->logger->warning(
				'Rejected a webhook with a bad or missing secret.',
				array( 'provider' => (string) $request->get_param( 'provider' ) )
			);

			return new WP_Error(
				'switchyard_forbidden',
				__( 'Invalid webhook secret.', 'switchyard-crm' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$slug     = (string) $request->get_param( 'provider' );
		$provider = $this->registry->active();

		if ( null === $provider || $provider->get_slug() !== $slug ) {
			return new WP_REST_Response(
				array( 'message' => __( 'That CRM is not the one connected to this site.', 'switchyard-crm' ) ),
				400
			);
		}

		if ( ! $provider instanceof SupportsWebhooks ) {
			return new WP_REST_Response(
				array( 'message' => __( 'This CRM does not support webhooks.', 'switchyard-crm' ) ),
				400
			);
		}

		if ( ! $provider->verify_webhook( $request ) ) {
			$this->logger->warning( 'A webhook failed provider verification.', array(), $slug );

			return new WP_REST_Response( array( 'message' => __( 'Verification failed.', 'switchyard-crm' ) ), 403 );
		}

		$payload = $provider->parse_webhook( $request );

		if ( null === $payload ) {
			// Well-formed but not actionable. 200 so the CRM does not retry.
			return new WP_REST_Response( array( 'message' => 'ignored' ), 200 );
		}

		$user_id = $this->resolve_user( $payload );

		if ( null === $user_id ) {
			if ( ! $payload->creates_user() || ! $this->settings->bool( 'import_new_contacts' ) ) {
				return new WP_REST_Response( array( 'message' => 'no matching user' ), 200 );
			}

			$user_id = null !== $payload->contact_id
				? $this->sync->import_contact( $payload->contact_id )
				: null;

			if ( null === $user_id ) {
				return new WP_REST_Response( array( 'message' => 'could not import' ), 200 );
			}
		}

		if ( null !== $payload->contact_id ) {
			$this->resolver->store_id( $user_id, $payload->contact_id );
		}

		// Authoritative read, rather than trusting the request body.
		$this->tags->load_tags( $user_id );

		do_action( 'switchyard_webhook_received', $payload, $user_id );

		$this->logger->info(
			'Processed a webhook.',
			array(
				'user_id' => $user_id,
				'type'    => $payload->type,
			),
			$slug
		);

		return new WP_REST_Response(
			array(
				'message' => 'ok',
				'user_id' => $user_id,
			),
			200
		);
	}

	private function resolve_user( Payload $payload ): ?int {
		$user_id = $this->resolver->user_for_email( $payload->email );

		if ( null !== $user_id ) {
			return $user_id;
		}

		// The email may have changed in the CRM, so fall back to the stored
		// contact ID before concluding there is no user.
		return null !== $payload->contact_id
			? $this->resolver->user_for_contact( $payload->contact_id )
			: null;
	}
}
