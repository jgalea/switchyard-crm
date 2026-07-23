<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

/**
 * Implemented by CRMs that provide a browser-side visitor tracking script.
 */
interface SupportsTracking {

	/**
	 * Raw script tag or inline JavaScript to print in the page head.
	 */
	public function get_tracking_script(): string;

	/**
	 * Inline JavaScript that associates the current visitor with a contact.
	 */
	public function get_identify_script( string $email, ?string $contact_id ): string;
}
