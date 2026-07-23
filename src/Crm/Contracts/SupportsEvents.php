<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

use Conduit\Crm\Exception\CrmException;

/**
 * Implemented by CRMs with an activity timeline that accepts custom events.
 */
interface SupportsEvents {

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws CrmException
	 */
	public function track_event( string $contact_id, string $event, array $data = array() ): void;
}
