<?php

declare( strict_types=1 );

namespace Switchyard\Crm\Exception;

/**
 * The CRM is rate limiting this site.
 */
final class RateLimitException extends CrmException {

	private int $retry_after = 0;

	public function set_retry_after( int $seconds ): void {
		$this->retry_after = max( 0, $seconds );
	}

	/**
	 * Seconds the CRM asked us to wait, or 0 when it did not say.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}
}
