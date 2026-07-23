<?php

declare( strict_types=1 );

namespace Conduit\Crm\Contracts;

use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\Order;

/**
 * Implemented by CRMs with a native ecommerce object, so orders can be stored
 * as orders rather than flattened into custom fields.
 *
 * The free plugin ships this API surface and does not wire a feature to it;
 * the enhanced ecommerce add-on and any developer can call it directly.
 */
interface SupportsEcommerce {

	/**
	 * @return string The CRM-side order identifier.
	 *
	 * @throws CrmException
	 */
	public function add_order( Order $order ): string;

	/**
	 * @throws CrmException
	 */
	public function update_order( string $remote_id, Order $order ): void;

	/**
	 * @throws CrmException
	 */
	public function delete_order( string $remote_id ): void;
}
