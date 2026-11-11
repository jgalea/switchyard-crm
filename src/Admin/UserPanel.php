<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Crm\ProviderRegistry;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;
use WP_User;

/**
 * CRM details and sync buttons on the user profile screen, plus a row action in the users list.
 */
final class UserPanel {

	private UserSync $sync;
	private ContactResolver $resolver;
	private TagEngine $tags;
	private TagPicker $picker;
	private ProviderRegistry $registry;
	public function __construct( UserSync $sync, ContactResolver $resolver, TagEngine $tags, TagPicker $picker, ProviderRegistry $registry ) {
		$this->sync     = $sync;
		$this->resolver = $resolver;
		$this->tags     = $tags;
		$this->picker   = $picker;
		$this->registry = $registry;
	}
	public function register_hooks(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'admin_post_switchyard_user', array( $this, 'handle' ) );
		add_filter( 'user_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	private function url( int $user_id, string $operation ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=switchyard_user&do=' . $operation . '&user_id=' . $user_id ),
			'switchyard_user_' . $user_id
		);
	}

	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) || null === $this->registry->active() ) {
			return;
		}

		$contact_id = $this->resolver->cached_id( $user->ID );
		$choices    = $this->picker->choices() ?? array();
		$labels     = array();

		foreach ( $this->tags->get_tags( $user->ID ) as $tag ) {
			$labels[] = $choices[ $tag ] ?? '#' . $tag;
		}

		$synced = (int) get_user_meta( $user->ID, ContactResolver::meta_key( UserSync::META_LAST_SYNC ), true );

		echo '<h2>' . esc_html__( 'CRM', 'switchyard-crm' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Contact ID', 'switchyard-crm' ),
			null === $contact_id ? esc_html__( 'Not linked yet', 'switchyard-crm' ) : '<code>' . esc_html( $contact_id ) . '</code>'
		);

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Tags', 'switchyard-crm' ),
			array() === $labels ? esc_html__( 'None', 'switchyard-crm' ) : esc_html( implode( ', ', $labels ) )
		);

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Last sync', 'switchyard-crm' ),
			$synced > 0
				/* translators: %s: how long ago, such as "3 hours". */
				? esc_html( sprintf( __( '%s ago', 'switchyard-crm' ), human_time_diff( $synced ) ) )
				: esc_html__( 'Never', 'switchyard-crm' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Actions', 'switchyard-crm' ) . '</th><td>';

		foreach (
			array(
				'push'    => __( 'Push to CRM', 'switchyard-crm' ),
				'pull'    => __( 'Pull from CRM', 'switchyard-crm' ),
				'refresh' => __( 'Refresh tags', 'switchyard-crm' ),
			) as $do => $label
		) {
			printf( '<a href="%s" class="button">%s</a> ', esc_url( $this->url( $user->ID, $do ) ), esc_html( $label ) );
		}

		echo '</td></tr></tbody></table>';
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_User $user ): array {
		if ( current_user_can( 'edit_users' ) && null !== $this->registry->active() ) {
			$actions['switchyard_push'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->url( $user->ID, 'push' ) ), esc_html__( 'Sync to CRM', 'switchyard-crm' ) );
		}

		return $actions;
	}

	public function handle(): void {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) || ! check_admin_referer( 'switchyard_user_' . $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'switchyard-crm' ) );
		}

		$done = 'failed';

		if ( 'push' === $do ) {
			$done = null !== $this->sync->push( $user_id ) ? 'pushed' : 'failed';
		} elseif ( 'pull' === $do ) {
			$this->sync->pull( $user_id );
			$done = 'pulled';
		} elseif ( 'refresh' === $do ) {
			$this->tags->load_tags( $user_id );
			$done = 'refreshed';
		}

		wp_safe_redirect( add_query_arg( 'switchyard_user', $done, get_edit_user_link( $user_id ) ) );

		exit;
	}

	public function notice(): void {
		$done = isset( $_GET['switchyard_user'] ) ? sanitize_key( wp_unslash( $_GET['switchyard_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'pushed'    => array( 'success', __( 'User pushed to the CRM.', 'switchyard-crm' ) ),
			'pulled'    => array( 'success', __( 'User updated from the CRM.', 'switchyard-crm' ) ),
			'refreshed' => array( 'success', __( 'Tags refreshed from the CRM.', 'switchyard-crm' ) ),
			'failed'    => array( 'error', __( 'Nothing was sent to the CRM. Check the Logs tab for the reason.', 'switchyard-crm' ) ),
		);

		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $done ][0] ), esc_html( $messages[ $done ][1] ) );
		}
	}
}
