<?php

declare( strict_types=1 );

namespace Conduit\Crm\Exception;

/**
 * Requests are suspended after repeated failures.
 */
final class CircuitOpenException extends CrmException {
}
