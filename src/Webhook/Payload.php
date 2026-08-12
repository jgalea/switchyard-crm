<?php

declare( strict_types=1 );

namespace Switchyard\Webhook;

/**
 * What a CRM webhook boils down to once the provider has parsed it.
 *
 * The listener deliberately does not trust tag data in the payload: it
 * re-reads tags from the API, because a webhook body can be replayed and a
 * stale one would otherwise grant or revoke access incorrectly.
 */
final class Payload {

	/**
 * @readonly
 */
	public string $email;
	/**
	 * @readonly
	 */
	public ?string $contact_id = null;
	/**
	 * @readonly
	 */
	public string $type = 'update';
	public function __construct( string $email, ?string $contact_id = null, string $type = 'update' ) {
		$this->email      = $email;
		$this->contact_id = $contact_id;
		$this->type       = $type;
	}
	/**
		* Whether this event should create a WordPress user when none exists.
		*/
	public function creates_user(): bool {
		return in_array( $this->type, array( 'subscribe', 'add', 'contact_add' ), true );
	}
}
