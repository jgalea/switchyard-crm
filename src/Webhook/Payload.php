<?php

declare( strict_types=1 );

namespace Conduit\Webhook;

/**
 * What a CRM webhook boils down to once the provider has parsed it.
 *
 * The listener deliberately does not trust tag data in the payload: it
 * re-reads tags from the API, because a webhook body can be replayed and a
 * stale one would otherwise grant or revoke access incorrectly.
 */
final class Payload {

	public function __construct(
		public readonly string $email,
		public readonly ?string $contact_id = null,
		public readonly string $type = 'update',
	) {}

	/**
	 * Whether this event should create a WordPress user when none exists.
	 */
	public function creates_user(): bool {
		return in_array( $this->type, array( 'subscribe', 'add', 'contact_add' ), true );
	}
}
