<?php

declare( strict_types=1 );

namespace Switchyard\Integration\Ecommerce;

use Switchyard\Admin\PostMetaBox;
use Switchyard\Crm\Order;
use Switchyard\Crm\OrderLine;
use Switchyard\Integration\AbstractIntegration;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\UserSync;

/**
 * Easy Digital Downloads.
 *
 * Covers both the 3.x order tables and the legacy payment post type, because
 * plenty of stores are still on the old data model.
 */
final class EasyDigitalDownloads extends AbstractIntegration {


	public function get_slug(): string {
		return 'edd';
	}

	public function get_name(): string {
		return 'Easy Digital Downloads';
	}

	public function is_available(): bool {
		return class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'EDD' );
	}

	public function register_hooks(): void {
		add_action( 'edd_complete_purchase', array( $this, 'on_complete' ), 20 );
		add_action( 'edd_post_refund_payment', array( $this, 'on_refund' ), 20 );
		add_filter( 'switchyard_wp_fields', array( $this, 'add_fields' ) );
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 * @return array<string, array<string, string>>
	 */
	public function add_fields( array $fields ): array {
		$fields[ __( 'Easy Digital Downloads', 'switchyard-crm' ) ] = array(
			'switchyard_edd_purchase_count' => __( 'Purchase Count', 'switchyard-crm' ),
			'switchyard_edd_lifetime_value' => __( 'Lifetime Value', 'switchyard-crm' ),
			'edd_customer_address'          => __( 'Customer Address', 'switchyard-crm' ),
		);

		return $fields;
	}

	public function on_complete( int $payment_id ): void {
		$this->guard(
			function () use ( $payment_id ): void {
				$email = (string) edd_get_payment_user_email( $payment_id );

				if ( '' === $email ) {
					return;
				}

				$user_id = (int) edd_get_payment_user_id( $payment_id );
				$tags    = $this->tags_for_payment( $payment_id );

				if ( $user_id > 0 ) {
					$this->store_customer_stats( $user_id, $email );

					$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );

					if ( ! empty( $tags ) ) {
						$this->tag_user( $user_id, $tags );
					}
				} else {
					$this->queue->dispatch(
						GuestSync::HOOK,
						array( $email, $this->guest_fields( $payment_id, $email ), $tags )
					);
				}

				/**
				 * Fires once an EDD order has been synced to the CRM.
				 *
				 * @param \Switchyard\Crm\Order $order   Normalised order, with line items.
				 * @param int                $user_id Customer user ID, 0 for a guest.
				 */
				do_action( 'switchyard_order_synced', $this->to_order( $payment_id, $email ), $user_id );
			}
		);
	}

	/**
 * @param mixed $payment
 */
	public function on_refund( $payment ): void {
		$this->guard(
			function () use ( $payment ): void {
				$payment_id = is_object( $payment ) && isset( $payment->ID ) ? (int) $payment->ID : (int) $payment;

				$user_id = (int) edd_get_payment_user_id( $payment_id );
				$options = $this->options();

				$refund_tags = $options['refund_tags'] ?? array();

				if ( is_array( $refund_tags ) && ! empty( $refund_tags ) && $user_id > 0 ) {
					/** @var list<string> $refund_tags */
					$this->tag_user( $user_id, $refund_tags );
				}

				if ( ! empty( $options['remove_tags_on_refund'] ) && $user_id > 0 ) {
					$this->queue->dispatch(
						'switchyard_remove_tags_async',
						array( $user_id, $this->tags_for_payment( $payment_id ) )
					);
				}
			}
		);
	}

	/**
	 * @return list<string>
	 */
	private function tags_for_payment( int $payment_id ): array {
		$tags = array();

		$cart = edd_get_payment_meta_cart_details( $payment_id );

		if ( is_array( $cart ) ) {
			foreach ( $cart as $item ) {
				$download_id = isset( $item['id'] ) ? (int) $item['id'] : 0;

				if ( $download_id <= 0 ) {
					continue;
				}

				$download_tags = get_post_meta( $download_id, PostMetaBox::META_PURCHASE_TAGS, true );

				if ( is_array( $download_tags ) ) {
					$tags = array_merge( $tags, array_map( 'strval', $download_tags ) );
				}
			}
		}

		/** @var list<string> $result */
		$result = apply_filters(
			'switchyard_edd_payment_tags',
			array_values( array_unique( array_merge( $tags, $this->option_tags( 'purchase_tags' ) ) ) ),
			$payment_id
		);

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function guest_fields( int $payment_id, string $email ): array {
		$map = get_option( 'switchyard_field_map', array() );

		if ( ! is_array( $map ) ) {
			return array();
		}

		$user_info = edd_get_payment_meta_user_info( $payment_id );

		$source = array(
			'user_email' => $email,
			'first_name' => is_array( $user_info ) ? ( $user_info['first_name'] ?? '' ) : '',
			'last_name'  => is_array( $user_info ) ? ( $user_info['last_name'] ?? '' ) : '',
		);

		$fields = array();

		foreach ( $map as $wp_key => $entry ) {
			if ( empty( $entry['active'] ) || empty( $entry['crm_field'] ) ) {
				continue;
			}

			if ( ! empty( $source[ $wp_key ] ) ) {
				$fields[ $entry['crm_field'] ] = $source[ $wp_key ];
			}
		}

		return $fields;
	}

	private function store_customer_stats( int $user_id, string $email ): void {
		if ( ! function_exists( 'edd_get_customer_by' ) ) {
			return;
		}

		$customer = edd_get_customer_by( 'email', $email );

		if ( ! $customer ) {
			return;
		}

		update_user_meta( $user_id, 'switchyard_edd_purchase_count', $customer->purchase_count ?? 0 );
		update_user_meta( $user_id, 'switchyard_edd_lifetime_value', $customer->purchase_value ?? 0 );
	}

	private function to_order( int $payment_id, string $email ): Order {
		$lines = array();

		$cart = edd_get_payment_meta_cart_details( $payment_id );

		if ( is_array( $cart ) ) {
			foreach ( $cart as $item ) {
				$qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );

				$lines[] = new OrderLine(
					(string) ( $item['id'] ?? 0 ),
					(string) ( $item['name'] ?? '' ),
					$qty,
					(float) ( $item['subtotal'] ?? 0 ) / $qty,
					null,
					get_permalink( (int) ( $item['id'] ?? 0 ) ) ? (string) get_permalink( (int) ( $item['id'] ?? 0 ) ) : null
				);
			}
		}

		$date = edd_get_payment_completed_date( $payment_id );

		return new Order(
			'edd',
			(string) $payment_id,
			$email,
			(float) edd_get_payment_amount( $payment_id ),
			(string) edd_get_payment_currency_code( $payment_id ),
			is_string( $date ) && '' !== $date ? (int) strtotime( $date ) : time(),
			$lines,
			0.0,
			(float) edd_get_payment_tax( $payment_id ),
			0.0,
			(string) edd_get_payment_status( $payment_id )
		);
	}
}
