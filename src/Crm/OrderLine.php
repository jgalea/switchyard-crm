<?php

declare( strict_types=1 );

namespace Conduit\Crm;

final class OrderLine {

	/**
 * @readonly
 */
	public string $product_id;
	/**
	 * @readonly
	 */
	public string $name;
	/**
	 * @readonly
	 */
	public int $quantity;
	/**
	 * @readonly
	 */
	public float $price;
	/**
	 * @readonly
	 */
	public ?string $sku = null;
	/**
	 * @readonly
	 */
	public ?string $product_url = null;
	/**
	 * @readonly
	 */
	public ?string $image_url = null;
	public function __construct( string $product_id, string $name, int $quantity, float $price, ?string $sku = null, ?string $product_url = null, ?string $image_url = null ) {
		$this->product_id  = $product_id;
		$this->name        = $name;
		$this->quantity    = $quantity;
		$this->price       = $price;
		$this->sku         = $sku;
		$this->product_url = $product_url;
		$this->image_url   = $image_url;
	}
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
