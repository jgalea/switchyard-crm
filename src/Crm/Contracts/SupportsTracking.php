<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

/**
 * Implemented by CRMs that provide a browser-side visitor tracking script.
 *
 * Providers return the pieces of their vendor snippet rather than finished
 * markup, so the caller can enqueue the file and attach the inline JavaScript
 * with the WordPress script API.
 */
interface SupportsTracking {

	/**
	 * Absolute URL of the vendor's tracking file, or '' when the snippet is
	 * inline only.
	 */
	public function get_tracking_src(): string;

	/**
	 * Inline JavaScript that prepares the vendor's global before its file
	 * loads. No script tags.
	 */
	public function get_tracking_inline(): string;

	/**
	 * Inline JavaScript that associates the current visitor with a contact. No
	 * script tags.
	 */
	public function get_identify_inline( string $email, ?string $contact_id ): string;
}
