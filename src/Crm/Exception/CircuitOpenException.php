<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Exception;

/**
 * Requests are suspended after repeated failures.
 */
final class CircuitOpenException extends CrmException {
}
