<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

use Conduit\Crm\Exception\CrmException;

/**
 * Implemented by CRMs that model audiences, lists or groups separately from
 * tags. Subscription state is handled here rather than in the tag engine
 * because opt-in has legal meaning that tagging does not.
 */
interface SupportsLists {

	/**
	 * @return array<string, string> List identifier => human label.
	 *
	 * @throws CrmException
	 */
	public function get_available_lists(): array;

	/**
	 * @param list<string> $list_ids
	 *
	 * @throws CrmException
	 */
	public function add_to_lists( string $contact_id, array $list_ids ): void;

	/**
	 * @param list<string> $list_ids
	 *
	 * @throws CrmException
	 */
	public function remove_from_lists( string $contact_id, array $list_ids ): void;
}
