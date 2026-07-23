<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

use Conduit\Crm\Exception\CrmException;

/**
 * Implemented by CRMs with a first-class tag or label concept.
 */
interface SupportsTags {

	/**
	 * @return array<string, string> Tag identifier => human label.
	 *
	 * @throws CrmException
	 */
	public function get_available_tags(): array;

	/**
	 * @return list<string> Tag identifiers currently on the contact.
	 *
	 * @throws CrmException
	 */
	public function get_tags( string $contact_id ): array;

	/**
	 * @param list<string> $tag_ids
	 *
	 * @throws CrmException
	 */
	public function apply_tags( string $contact_id, array $tag_ids ): void;

	/**
	 * @param list<string> $tag_ids
	 *
	 * @throws CrmException
	 */
	public function remove_tags( string $contact_id, array $tag_ids ): void;

	/**
	 * Creates a tag and returns its identifier. Idempotent on label where the
	 * CRM allows it.
	 *
	 * @throws CrmException
	 */
	public function create_tag( string $label ): string;
}
