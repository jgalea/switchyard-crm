<?php

declare( strict_types=1 );

namespace Conduit\Crm;

final class OrderLine {

	public function __construct(
		public readonly string $product_id,
		public readonly string $name,
		public readonly int $quantity,
		public readonly float $price,
		public readonly ?string $sku = null,
		public readonly ?string $product_url = null,
		public readonly ?string $image_url = null,
	) {}

	public function subtotal(): float {
		return $this->price * $this->quantity;
	}

	public function price_cents(): int {
		return (int) round( $this->price * 100 );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'product_id'  => $this->product_id,
			'name'        => $this->name,
			'quantity'    => $this->quantity,
			'price'       => $this->price,
			'sku'         => $this->sku,
			'product_url' => $this->product_url,
			'image_url'   => $this->image_url,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['product_id'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			(int) ( $data['quantity'] ?? 1 ),
			(float) ( $data['price'] ?? 0 ),
			isset( $data['sku'] ) ? (string) $data['sku'] : null,
			isset( $data['product_url'] ) ? (string) $data['product_url'] : null,
			isset( $data['image_url'] ) ? (string) $data['image_url'] : null
		);
	}
}
