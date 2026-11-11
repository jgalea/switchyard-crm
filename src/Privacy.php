<?php

declare( strict_types=1 );

namespace Switchyard;

use Switchyard\Logger\LogTable;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

/**
 * Personal-data export and erasure for the data this plugin stores in WordPress.
 */
final class Privacy {

	private const STORE_META = array(
		'switchyard_wc_order_count',
		'switchyard_wc_total_spent',
		'switchyard_wc_last_order',
		'switchyard_edd_purchase_count',
		'switchyard_edd_lifetime_value',
	);

	private ContactResolver $resolver;
	private TagEngine $tags;
	public function __construct( ContactResolver $resolver, TagEngine $tags ) {
		$this->resolver = $resolver;
		$this->tags     = $tags;
	}
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['switchyard-crm'] = array(
			'exporter_friendly_name' => __( 'Switchyard CRM', 'switchyard-crm' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['switchyard-crm'] = array(
			'eraser_friendly_name' => __( 'Switchyard CRM', 'switchyard-crm' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email ): array {
		global $wpdb;

		$data = array();
		$user = get_user_by( 'email', $email );

		if ( $user instanceof \WP_User ) {
			$rows = array();
			$id   = $this->resolver->cached_id( $user->ID );

			if ( null !== $id ) {
				$rows[] = array(
					'name'  => __( 'CRM contact ID', 'switchyard-crm' ),
					'value' => $id,
				);
			}

			$tags = $this->tags->get_tags( $user->ID );

			if ( array() !== $tags ) {
				$rows[] = array(
					'name'  => __( 'CRM tags', 'switchyard-crm' ),
					'value' => implode( ', ', $tags ),
				);
			}

			$synced = get_user_meta( $user->ID, ContactResolver::meta_key( UserSync::META_LAST_SYNC ), true );

			if ( is_scalar( $synced ) && '' !== (string) $synced ) {
				$rows[] = array(
					'name'  => UserSync::META_LAST_SYNC,
					'value' => (string) $synced,
				);
			}

			foreach ( self::STORE_META as $key ) {
				$value = get_user_meta( $user->ID, $key, true );

				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$rows[] = array(
						'name'  => $key,
						'value' => (string) $value,
					);
				}
			}

			if ( array() !== $rows ) {
				$data[] = array(
					'group_id'    => 'switchyard-crm',
					'group_label' => __( 'CRM sync', 'switchyard-crm' ),
					'item_id'     => 'switchyard-crm-' . $user->ID,
					'data'        => $rows,
				);
			}
		}

		$table = LogTable::name();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';
		$enc   = '%' . $wpdb->esc_like( rawurlencode( $email ) ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, level, message FROM {$table} WHERE message LIKE %s OR context LIKE %s", $like, $like ) );
		// phpcs:enable

		foreach ( is_array( $logs ) ? $logs : array() as $log ) {
			$data[] = array(
				'group_id'    => 'switchyard-crm-log',
				'group_label' => __( 'CRM sync log', 'switchyard-crm' ),
				'item_id'     => 'switchyard-crm-log-' . (int) $log->id,
				'data'        => array(
					array(
						'name'  => __( 'Time', 'switchyard-crm' ),
						'value' => (string) $log->created_at,
					),
					array(
						'name'  => __( 'Message', 'switchyard-crm' ),
						'value' => (string) $log->message,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email ): array {
		global $wpdb;

		$removed = false;
		$user    = get_user_by( 'email', $email );
		$contact = null;

		if ( $user instanceof \WP_User ) {
			$contact = $this->resolver->cached_id( $user->ID );

			foreach ( array( UserSync::META_LAST_SYNC, ContactResolver::META_CONTACT_ID, TagEngine::META_TAGS ) as $key ) {
				$removed = delete_user_meta( $user->ID, ContactResolver::meta_key( $key ) ) || $removed;
			}

			foreach ( self::STORE_META as $key ) {
				$removed = delete_user_meta( $user->ID, $key ) || $removed;
			}
		}

		$table = LogTable::name();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';
		$enc   = '%' . $wpdb->esc_like( rawurlencode( $email ) ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$logs = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE message LIKE %s OR context LIKE %s OR message LIKE %s OR context LIKE %s", $like, $like, $enc, $enc ) );

		// Queued guest jobs carry the email in their arguments.
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$queued  = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) === $actions ) {
			$queued = $wpdb->query(
				$wpdb->prepare(
					"DELETE a FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id WHERE g.slug = %s AND ( a.args LIKE %s OR a.extended_args LIKE %s )",
					Sync\Queue::GROUP,
					$like,
					$like
				)
			);
		}
		// phpcs:enable

		/**
		 * Fires when a person's data is erased, so a site can also remove the
		 * contact from its CRM.
		 *
		 * @param string      $email      The erased email address.
		 * @param string|null $contact_id The CRM contact ID the user was linked to.
		 */
		do_action( 'switchyard_erased_personal_data', $email, $contact );

		return array(
			'items_removed'  => $removed || (int) $logs > 0 || (int) $queued > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Switchyard CRM',
			wp_kses_post(
				'<h3>' . __( 'CRM sync', 'switchyard-crm' ) . '</h3><p>'
				. __( 'When you register, update your profile, submit a connected form or place an order, your name, email address and the other details you provide are sent to our CRM provider so we can contact you and manage your account. The CRM contact ID and the tags assigned to you are stored on this site to control access to content. Sync activity, including error messages that may mention your email address, is logged here for a limited time.', 'switchyard-crm' )
				. '</p>'
			)
		);
	}
}
