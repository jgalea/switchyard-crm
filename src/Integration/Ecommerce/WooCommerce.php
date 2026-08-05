<?php

declare( strict_types=1 );

namespace Conduit\Integration\Ecommerce;

use Conduit\Crm\Order;
use Conduit\Crm\OrderLine;
use Conduit\Integration\AbstractIntegration;
use Conduit\Sync\GuestSync;
use Conduit\Sync\UserSync;
use WC_Order;
use WC_Order_Item_Product;

/**
 * WooCommerce.
 *
 * Free scope: customer sync, per-product and per-status tagging, guest
 * checkout, refund handling. Full order objects with line items go to the CRM
 * through the enhanced ecommerce feature, which the pro plugin registers; the
 * Order value object is built here either way so both paths share one shape.
 */
final class WooCommerce extends AbstractIntegration {

	private const META_SYNCED = '_conduit_synced';


	public function get_slug(): string {
		return 'woocommerce';
	}

	public function get_name(): string {
		return 'WooCommerce';
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 2 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'on_address_saved' ), 20 );

		add_filter( 'conduit_wp_fields', array( $this, 'add_billing_fields' ) );
	}

	/**
	 * Billing and shipping become mappable fields, which is most of why a
	 * store connects a CRM in the first place.
	 *
	 * @param array<string, array<string, string>> $fields
	 * @return array<string, array<string, string>>
	 */
	public function add_billing_fields( array $fields ): array {
		$fields[ __( 'WooCommerce Billing', 'conduit-crm' ) ] = array(
			'billing_first_name' => __( 'Billing First Name', 'conduit-crm' ),
			'billing_last_name'  => __( 'Billing Last Name', 'conduit-crm' ),
			'billing_company'    => __( 'Billing Company', 'conduit-crm' ),
			'billing_address_1'  => __( 'Billing Address 1', 'conduit-crm' ),
			'billing_address_2'  => __( 'Billing Address 2', 'conduit-crm' ),
			'billing_city'       => __( 'Billing City', 'conduit-crm' ),
			'billing_state'      => __( 'Billing State', 'conduit-crm' ),
			'billing_postcode'   => __( 'Billing Postcode', 'conduit-crm' ),
			'billing_country'    => __( 'Billing Country', 'conduit-crm' ),
			'billing_phone'      => __( 'Billing Phone', 'conduit-crm' ),
		);

		$fields[ __( 'WooCommerce Shipping', 'conduit-crm' ) ] = array(
			'shipping_first_name' => __( 'Shipping First Name', 'conduit-crm' ),
			'shipping_last_name'  => __( 'Shipping Last Name', 'conduit-crm' ),
			'shipping_address_1'  => __( 'Shipping Address 1', 'conduit-crm' ),
			'shipping_city'       => __( 'Shipping City', 'conduit-crm' ),
			'shipping_postcode'   => __( 'Shipping Postcode', 'conduit-crm' ),
			'shipping_country'    => __( 'Shipping Country', 'conduit-crm' ),
		);

		$fields[ __( 'WooCommerce Customer', 'conduit-crm' ) ] = array(
			'conduit_wc_order_count' => __( 'Order Count', 'conduit-crm' ),
			'conduit_wc_total_spent' => __( 'Lifetime Value', 'conduit-crm' ),
			'conduit_wc_last_order'  => __( 'Last Order Date', 'conduit-crm' ),
		);

		return $fields;
	}

	public function on_order_placed( int $order_id ): void {
		$this->guard(
			function () use ( $order_id ): void {
				$order = wc_get_order( $order_id );

				if ( ! $order instanceof WC_Order ) {
					return;
				}

				$this->sync_order( $order );
			}
		);
	}

	/**
 * @param mixed $order
 */
	public function on_status_changed( int $order_id, string $from, string $to, $order = null ): void {
		$this->guard(
			function () use ( $order_id, $to, $order ): void {
				if ( ! $order instanceof WC_Order ) {
					$order = wc_get_order( $order_id );
				}

				if ( ! $order instanceof WC_Order ) {
					return;
				}

				// The initial sync happens on checkout; this covers later
				// transitions and orders created in the admin.
				if ( ! $order->get_meta( self::META_SYNCED ) ) {
					$this->sync_order( $order );
				}

				$user_id = $order->get_user_id();
				$options = $this->options();

				$status_tags = $options['status_tags'][ $to ] ?? array();

				if ( is_array( $status_tags ) && ! empty( $status_tags ) ) {
					/** @var list<string> $status_tags */
					if ( $user_id > 0 ) {
						$this->tag_user( $user_id, $status_tags );
					} else {
						$this->queue->dispatch(
							GuestSync::HOOK,
							array( $order->get_billing_email(), array(), $status_tags )
						);
					}
				}

				do_action( 'conduit_woocommerce_status_changed', $this->to_order( $order ), $to, $user_id );
			}
		);
	}

	private function sync_order( WC_Order $order ): void {
		$user_id = $order->get_user_id();
		$email   = $order->get_billing_email();

		if ( '' === $email ) {
			return;
		}

		$tags = $this->tags_for_order( $order );

		if ( $user_id > 0 ) {
			$this->store_customer_stats( $user_id, $order );

			$this->queue->dispatch(
				UserSync::HOOK_PUSH,
				array( $user_id, $this->billing_overrides( $order ) )
			);

			if ( ! empty( $tags ) ) {
				$this->tag_user( $user_id, $tags );
			}
		} else {
			// Guest checkout. Field values come straight off the order,
			// because there is no user record to read them from.
			$this->queue->dispatch(
				GuestSync::HOOK,
				array( $email, $this->guest_fields( $order ), $tags )
			);
		}

		$order->update_meta_data( self::META_SYNCED, time() );
		$order->save();

		// The enhanced ecommerce feature listens here. Nothing in the free
		// plugin consumes it, and nothing breaks when no listener exists.
		do_action( 'conduit_order_synced', $this->to_order( $order ), $user_id );
	}

	public function on_refund( int $order_id, int $refund_id ): void {
		$this->guard(
			function () use ( $order_id ): void {
				$order = wc_get_order( $order_id );

				if ( ! $order instanceof WC_Order ) {
					return;
				}

				$options = $this->options();
				$user_id = $order->get_user_id();

				$refund_tags = $options['refund_tags'] ?? array();

				if ( is_array( $refund_tags ) && ! empty( $refund_tags ) && $user_id > 0 ) {
					/** @var list<string> $refund_tags */
					$this->tag_user( $user_id, $refund_tags );
				}

				// Purchase tags are removed on refund, otherwise a refunded
				// customer keeps the access their purchase granted.
				if ( ! empty( $options['remove_tags_on_refund'] ) && $user_id > 0 ) {
					$this->queue->dispatch(
						'conduit_remove_tags_async',
						array( $user_id, $this->tags_for_order( $order ) )
					);
				}

				do_action( 'conduit_order_refunded', $this->to_order( $order ), $user_id );
			}
		);
	}

	public function on_address_saved( int $user_id ): void {
		$this->guard(
			function () use ( $user_id ): void {
				$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );
			}
		);
	}

	/**
	 * @return list<string>
	 */
	private function tags_for_order( WC_Order $order ): array {
		$tags = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			foreach ( array( $item->get_product_id(), $item->get_variation_id() ) as $product_id ) {
				if ( $product_id <= 0 ) {
					continue;
				}

				$product_tags = get_post_meta( $product_id, '_conduit_tags', true );

				if ( is_array( $product_tags ) ) {
					$tags = array_merge( $tags, array_map( 'strval', $product_tags ) );
				}
			}
		}

		$global = $this->option_tags( 'purchase_tags' );

		/** @var list<string> $result */
		$result = apply_filters(
			'conduit_woocommerce_order_tags',
			array_values( array_unique( array_merge( $tags, $global ) ) ),
			$order
		);

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function billing_overrides( WC_Order $order ): array {
		return array(
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_company'    => $order->get_billing_company(),
			'billing_address_1'  => $order->get_billing_address_1(),
			'billing_address_2'  => $order->get_billing_address_2(),
			'billing_city'       => $order->get_billing_city(),
			'billing_state'      => $order->get_billing_state(),
			'billing_postcode'   => $order->get_billing_postcode(),
			'billing_country'    => $order->get_billing_country(),
			'billing_phone'      => $order->get_billing_phone(),
		);
	}

	/**
	 * Guest orders have no user, so the CRM field names have to be resolved
	 * from the map directly rather than through FieldMap::to_crm().
	 *
	 * @return array<string, mixed>
	 */
	private function guest_fields( WC_Order $order ): array {
		$map = get_option( 'conduit_field_map', array() );

		if ( ! is_array( $map ) ) {
			return array();
		}

		$source = array_merge(
			$this->billing_overrides( $order ),
			array(
				'user_email' => $order->get_billing_email(),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			)
		);

		$fields = array();

		foreach ( $map as $wp_key => $entry ) {
			if ( empty( $entry['active'] ) || empty( $entry['crm_field'] ) ) {
				continue;
			}

			if ( isset( $source[ $wp_key ] ) && '' !== $source[ $wp_key ] ) {
				$fields[ $entry['crm_field'] ] = $source[ $wp_key ];
			}
		}

		return $fields;
	}

	private function store_customer_stats( int $user_id, WC_Order $order ): void {
		$customer = new \WC_Customer( $user_id );

		$created = $order->get_date_created();

		update_user_meta( $user_id, 'conduit_wc_order_count', $customer->get_order_count() );
		update_user_meta( $user_id, 'conduit_wc_total_spent', $customer->get_total_spent() );
		update_user_meta( $user_id, 'conduit_wc_last_order', null !== $created ? $created->getTimestamp() : time() );
	}

	/**
	 * Normalizes a WooCommerce order into the store-agnostic shape.
	 */
	private function to_order( WC_Order $order ): Order {
		$lines = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );

			$lines[] = new OrderLine(
				(string) $item->get_product_id(),
				$item->get_name(),
				$qty,
				(float) $item->get_total() / $qty,
				$product && '' !== (string) $product->get_sku() ? (string) $product->get_sku() : null,
				$product ? ( get_permalink( $product->get_id() ) ? (string) get_permalink( $product->get_id() ) : null ) : null,
				$product ? ( wp_get_attachment_url( (int) $product->get_image_id() ) ? (string) wp_get_attachment_url( (int) $product->get_image_id() ) : null ) : null
			);
		}

		$created = $order->get_date_created();

		return new Order(
			'woocommerce',
			(string) $order->get_id(),
			$order->get_billing_email(),
			(float) $order->get_total(),
			$order->get_currency(),
			null !== $created ? $created->getTimestamp() : time(),
			$lines,
			(float) $order->get_discount_total(),
			(float) $order->get_total_tax(),
			(float) $order->get_shipping_total(),
			$order->get_status(),
			$order->get_view_order_url(),
			array( 'payment_method' => $order->get_payment_method_title() )
		);
	}
}
