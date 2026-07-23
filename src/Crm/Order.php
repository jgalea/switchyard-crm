<?php

declare( strict_types=1 );

namespace Conduit\Crm;

/**
 * A store-agnostic order, so WooCommerce and Easy Digital Downloads produce
 * the same shape and providers only learn one.
 */
final class Order {

	/**
	 * @param list<OrderLine>      $lines
	 * @param array<string, mixed> $meta
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $source_id,
		public readonly string $email,
		public readonly float $total,
		public readonly string $currency,
		public readonly int $placed_at,
		public readonly array $lines = array(),
		public readonly float $discount = 0.0,
		public readonly float $tax = 0.0,
		public readonly float $shipping = 0.0,
		public readonly string $status = '',
		public readonly ?string $order_url = null,
		public readonly array $meta = array(),
	) {}

	/**
	 * Stable identifier used to correlate this order with its CRM-side record.
	 */
	public function reference(): string {
		return $this->source . ':' . $this->source_id;
	}

	public function total_cents(): int {
		return (int) round( $this->total * 100 );
	}

	/**
	 * Orders cross the job queue, which serialises arguments as JSON, so they
	 * need a lossless array form rather than relying on object serialisation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source'    => $this->source,
			'source_id' => $this->source_id,
			'email'     => $this->email,
			'total'     => $this->total,
			'currency'  => $this->currency,
			'placed_at' => $this->placed_at,
			'lines'     => array_map( static fn( OrderLine $line ): array => $line->to_array(), $this->lines ),
			'discount'  => $this->discount,
			'tax'       => $this->tax,
			'shipping'  => $this->shipping,
			'status'    => $this->status,
			'order_url' => $this->order_url,
			'meta'      => $this->meta,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$lines = array();

		foreach ( is_array( $data['lines'] ?? null ) ? $data['lines'] : array() as $line ) {
			if ( is_array( $line ) ) {
				$lines[] = OrderLine::from_array( $line );
			}
		}

		return new self(
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['source_id'] ?? '' ),
			(string) ( $data['email'] ?? '' ),
			(float) ( $data['total'] ?? 0 ),
			(string) ( $data['currency'] ?? '' ),
			(int) ( $data['placed_at'] ?? 0 ),
			$lines,
			(float) ( $data['discount'] ?? 0 ),
			(float) ( $data['tax'] ?? 0 ),
			(float) ( $data['shipping'] ?? 0 ),
			(string) ( $data['status'] ?? '' ),
			isset( $data['order_url'] ) ? (string) $data['order_url'] : null,
			is_array( $data['meta'] ?? null ) ? $data['meta'] : array()
		);
	}
}
