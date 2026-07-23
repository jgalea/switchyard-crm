<?php

declare( strict_types=1 );

namespace Conduit\Crm\Exception;

use RuntimeException;

class CrmException extends RuntimeException {

	/** @var array<string, mixed> */
	private array $context;

	/** @param array<string, mixed> $context */
	public function __construct( string $message, array $context = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );

		$this->context = $context;
	}

	/** @return array<string, mixed> */
	public function context(): array {
		return $this->context;
	}
}
