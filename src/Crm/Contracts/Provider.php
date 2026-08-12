<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Contracts;

use Switchyard\Crm\Exception\ConnectionException;
use Switchyard\Crm\Exception\CrmException;

/**
 * The minimum every CRM provider implements.
 *
 * Anything a meaningful subset of CRMs cannot do lives in a capability
 * interface instead, so the engine can ask rather than catch.
 */
interface Provider {

	public function get_slug(): string;

	public function get_name(): string;

	/**
	 * Credential fields to render on the connection screen.
	 *
	 * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
	 */
	public function get_credential_fields(): array;

	/**
	 * Verifies credentials and prepares the provider for use.
	 *
	 * @param array<string, mixed> $credentials
	 *
	 * @throws ConnectionException When the credentials are rejected.
	 */
	public function connect( array $credentials ): void;

	/**
	 * @throws CrmException
	 */
	public function get_contact_id( string $email ): ?string;

	/**
	 * @param array<string, mixed> $fields Keyed by CRM field identifier.
	 *
	 * @return string The new contact identifier.
	 *
	 * @throws CrmException
	 */
	public function add_contact( array $fields ): string;

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @throws CrmException
	 */
	public function update_contact( string $contact_id, array $fields ): void;

	/**
	 * @return array<string, mixed> Keyed by CRM field identifier.
	 *
	 * @throws CrmException
	 */
	public function load_contact( string $contact_id ): array;

	/**
	 * Fields available to map against, including custom fields.
	 *
	 * @return array<string, array{label: string, type: string}>
	 *
	 * @throws CrmException
	 */
	public function get_available_fields(): array;
}
