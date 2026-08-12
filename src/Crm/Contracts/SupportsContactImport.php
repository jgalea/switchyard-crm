<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Contracts;

use Switchyard\Crm\Exception\CrmException;

/**
 * Implemented by CRMs that can enumerate contacts, which is what batch import
 * needs. A CRM can be fully usable without this.
 */
interface SupportsContactImport {

	/**
	 * Returns a page of contact identifiers, optionally filtered by tag.
	 *
	 * @return array{ids: list<string>, cursor: string|null}
	 *
	 * @throws CrmException
	 */
	public function load_contact_ids( ?string $tag_id = null, ?string $cursor = null ): array;
}
