<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Contracts;

use Switchyard\Webhook\Payload;
use WP_REST_Request;

/**
 * Implemented by CRMs that can call back into the site.
 */
interface SupportsWebhooks {

	/**
	 * Provider-specific authenticity check, run before the shared-secret
	 * check rather than instead of it.
	 */
	public function verify_webhook( WP_REST_Request $request ): bool;

	/**
	 * Returns null when the payload is well-formed but not actionable.
	 */
	public function parse_webhook( WP_REST_Request $request ): ?Payload;
}
