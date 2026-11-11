<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Crm\Contracts\SupportsWebhooks;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Integration\Registry as IntegrationRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Logger\LogTable;
use Switchyard\Settings;
use Switchyard\Sync\FieldMap;
use Switchyard\Webhook\Secret;

/**
 * Settings screens.
 *
 * Deliberately plain: WordPress admin markup, no build step, no upsell
 * surfaces. Credentials are write-only in the UI, so an existing key is never
 * rendered back into the page source.
 */
final class AdminPages {

	private Settings $settings;
	private ProviderRegistry $registry;
	private FieldMap $fields;
	private IntegrationRegistry $integrations;
	private const CAPABILITY = 'manage_options';
	private const SLUG       = 'switchyard-crm';

	public function __construct( Settings $settings, ProviderRegistry $registry, FieldMap $fields, IntegrationRegistry $integrations ) {
		$this->settings     = $settings;
		$this->registry     = $registry;
		$this->fields       = $fields;
		$this->integrations = $integrations;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_switchyard_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_switchyard_action', array( $this, 'handle_action' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Switchyard CRM', 'switchyard-crm' ),
			__( 'Switchyard CRM', 'switchyard-crm' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			81
		);
	}

	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, array( 'connection', 'sync', 'access', 'integrations', 'logs', 'tools' ), true )
			? $tab
			: 'connection';
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = $this->current_tab();

		$tabs = array(
			'connection'   => __( 'Connection', 'switchyard-crm' ),
			'sync'         => __( 'Sync', 'switchyard-crm' ),
			'access'       => __( 'Access', 'switchyard-crm' ),
			'integrations' => __( 'Integrations', 'switchyard-crm' ),
			'logs'         => __( 'Logs', 'switchyard-crm' ),
			'tools'        => __( 'Tools', 'switchyard-crm' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Switchyard CRM', 'switchyard-crm' ) . '</h1>';

		$this->render_notices();

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
				$tab === $slug ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</h2>';

		switch ( $tab ) {
			case 'sync':
				$this->render_sync();
				break;
			case 'access':
				$this->render_access();
				break;
			case 'integrations':
				$this->render_integrations();
				break;
			case 'logs':
				$this->render_logs();
				break;
			case 'tools':
				$this->render_tools();
				break;
			default:
				$this->render_connection();
				break;
		}

		echo '</div>';
	}

	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['switchyard_notice'] ) ? sanitize_key( wp_unslash( $_GET['switchyard_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'       => array( 'success', __( 'Settings saved.', 'switchyard-crm' ) ),
			'connected'   => array( 'success', __( 'Connected to the CRM.', 'switchyard-crm' ) ),
			'failed'      => array( 'error', __( 'Could not connect. Check the credentials and try again.', 'switchyard-crm' ) ),
			'refreshed'   => array( 'success', __( 'Tag and field lists refreshed.', 'switchyard-crm' ) ),
			'rotated'     => array( 'success', __( 'Webhook secret rotated. Update it in your CRM.', 'switchyard-crm' ) ),
			'logscleared' => array( 'success', __( 'Logs cleared.', 'switchyard-crm' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $type, $message ] = $messages[ $notice ];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	private function form_open( string $tab ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'switchyard_save_' . $tab );
		printf( '<input type="hidden" name="action" value="switchyard_save">' );
		printf( '<input type="hidden" name="tab" value="%s">', esc_attr( $tab ) );
	}

	private function render_connection(): void {
		$this->form_open( 'connection' );

		$current  = $this->settings->provider_slug();
		$provider = $this->registry->active();

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="switchyard-provider">' . esc_html__( 'CRM', 'switchyard-crm' ) . '</label></th><td>';
		echo '<select name="provider" id="switchyard-provider">';
		echo '<option value="">' . esc_html__( '— Select —', 'switchyard-crm' ) . '</option>';

		foreach ( $this->registry->available() as $slug => $name ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $current, $slug, false ),
				esc_html( $name )
			);
		}

		echo '</select>';

		if ( null !== $provider ) {
			echo ' <span style="color:#1a7f37;">' . esc_html__( 'Connected', 'switchyard-crm' ) . '</span>';
		} elseif ( '' !== $current ) {
			echo ' <span style="color:#b32d2e;">' . esc_html__( 'Not connected', 'switchyard-crm' ) . '</span>';
		}

		echo '<p class="description">' . esc_html__( 'Save after changing the CRM to see its credential fields.', 'switchyard-crm' ) . '</p>';
		echo '</td></tr>';

		if ( '' !== $current && $this->registry->has( $current ) ) {
			$stored = $this->settings->credentials();

			foreach ( $this->registry->make( $current )->get_credential_fields() as $key => $field ) {
				$id = 'switchyard-cred-' . $key;

				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

				if ( 'checkbox' === $field['type'] ) {
					printf(
						'<input type="checkbox" id="%s" name="credentials[%s]" value="1" %s>',
						esc_attr( $id ),
						esc_attr( $key ),
						checked( ! empty( $stored[ $key ] ), true, false )
					);
				} elseif ( 'password' === $field['type'] ) {
					// Never render the stored secret back to the browser.
					printf(
						'<input type="password" id="%s" name="credentials[%s]" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
						esc_attr( $id ),
						esc_attr( $key ),
						empty( $stored[ $key ] )
							? ''
							: esc_attr__( 'Saved. Leave blank to keep.', 'switchyard-crm' )
					);
				} else {
					printf(
						'<input type="text" id="%s" name="credentials[%s]" value="%s" class="regular-text">',
						esc_attr( $id ),
						esc_attr( $key ),
						esc_attr( is_scalar( $stored[ $key ] ?? '' ) ? (string) ( $stored[ $key ] ?? '' ) : '' )
					);
				}

				if ( ! empty( $field['help'] ) ) {
					echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
				}

				echo '</td></tr>';
			}
		}

		echo '</tbody></table>';

		submit_button( __( 'Save and connect', 'switchyard-crm' ) );

		echo '</form>';

		if ( $provider instanceof SupportsWebhooks ) {
			$this->render_webhook_box( $provider->get_slug() );
		}
	}

	private function render_webhook_box( string $slug ): void {
		echo '<h2>' . esc_html__( 'Webhook', 'switchyard-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Point your CRM at this endpoint to push tag changes back into WordPress.', 'switchyard-crm' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Preferred: send the secret as a header', 'switchyard-crm' ) . '</strong></p>';

		printf(
			'<p><code style="user-select:all;">%s</code><br><code style="user-select:all;">%s: %s</code></p>',
			esc_html( Secret::endpoint( $slug ) ),
			esc_html( Secret::header_name() ),
			esc_html( Secret::get() )
		);

		echo '<p>' . esc_html__( 'If your CRM cannot send a custom header, use this URL instead. It carries the secret in the query string, which is recorded in server access logs.', 'switchyard-crm' ) . '</p>';

		printf(
			'<p><code style="user-select:all;">%s</code></p>',
			esc_html( Secret::url( $slug ) )
		);

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=switchyard_action&do=rotate_secret' ),
					'switchyard_action'
				)
			),
			esc_html__( 'Rotate secret', 'switchyard-crm' )
		);
	}

	private function render_sync(): void {
		$provider = $this->registry->active();

		if ( null === $provider ) {
			echo '<p>' . esc_html__( 'Connect a CRM first.', 'switchyard-crm' ) . '</p>';

			return;
		}

		$this->form_open( 'sync' );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row( 'sync_on_register', __( 'Sync on registration', 'switchyard-crm' ) );
		$this->checkbox_row( 'sync_on_profile_update', __( 'Sync on profile update', 'switchyard-crm' ) );
		if ( $provider instanceof SupportsWebhooks ) {
			$this->checkbox_row( 'import_new_contacts', __( 'Create users from webhooks', 'switchyard-crm' ) );
		}
		$this->checkbox_row( 'async_sync', __( 'Sync in the background', 'switchyard-crm' ) );
		$this->checkbox_row( 'site_tracking', __( 'Enable site tracking', 'switchyard-crm' ) );
		$this->checkbox_row( 'staging_mode', __( 'Staging mode (block all writes)', 'switchyard-crm' ) );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Field mapping', 'switchyard-crm' ) . '</h2>';

		try {
			$crm_fields = $provider->get_available_fields();
		} catch ( CrmException $e ) {
			$crm_fields = array();

			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $e->getMessage() ) );
		}

		$map = $this->fields->all();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'WordPress field', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'CRM field', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Sync', 'switchyard-crm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->fields->available_wp_fields() as $group => $group_fields ) {
			printf( '<tr><td colspan="3"><strong>%s</strong></td></tr>', esc_html( $group ) );

			foreach ( $group_fields as $key => $label ) {
				$entry = $map[ $key ] ?? array(
					'crm_field' => '',
					'type'      => 'text',
					'active'    => false,
				);

				echo '<tr>';
				printf( '<td><label for="switchyard-map-%s">%s</label></td>', esc_attr( $key ), esc_html( $label ) );

				echo '<td><select id="switchyard-map-' . esc_attr( $key ) . '" name="map[' . esc_attr( $key ) . '][crm_field]">';
				echo '<option value="">' . esc_html__( '— None —', 'switchyard-crm' ) . '</option>';

				foreach ( $crm_fields as $crm_key => $crm_field ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $crm_key ),
						selected( $entry['crm_field'] ?? '', $crm_key, false ),
						esc_html( $crm_field['label'] )
					);
				}

				echo '</select>';
				printf(
					'<input type="hidden" name="map[%s][type]" value="%s">',
					esc_attr( $key ),
					esc_attr( $crm_fields[ $entry['crm_field'] ?? '' ]['type'] ?? 'text' )
				);
				echo '</td>';

				printf(
					'<td><input type="checkbox" name="map[%s][active]" value="1" %s></td>',
					esc_attr( $key ),
					checked( ! empty( $entry['active'] ), true, false )
				);

				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		submit_button();

		echo '</form>';
	}

	private function render_access(): void {
		$this->form_open( 'access' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="switchyard-message">' . esc_html__( 'Restricted message', 'switchyard-crm' ) . '</label></th><td>';
		printf(
			'<textarea id="switchyard-message" name="restricted_message" rows="3" class="large-text">%s</textarea>',
			esc_textarea( $this->settings->string( 'restricted_message' ) )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="switchyard-redirect">' . esc_html__( 'Redirect to', 'switchyard-crm' ) . '</label></th><td>';
		// Arguments are an integer and a translated literal; the function
		// escapes the option markup it builds from them.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_dropdown_pages(
			array(
				'name'              => 'restricted_redirect',
				'id'                => 'switchyard-redirect',
				'selected'          => $this->settings->int( 'restricted_redirect' ),
				'show_option_none'  => __( '— Show the message instead —', 'switchyard-crm' ),
				'option_none_value' => '0',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</td></tr>';

		$this->checkbox_row( 'filter_queries', __( 'Hide restricted content from listings, search and feeds', 'switchyard-crm' ) );

		echo '</tbody></table>';

		submit_button();

		echo '</form>';
	}

	private function render_integrations(): void {
		echo '<p>' . esc_html__( 'Integrations load when their plugin is active. Untick one to keep it from loading.', 'switchyard-crm' ) . '</p>';

		$this->form_open( 'integrations' );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Integration', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'switchyard-crm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->integrations->status() as $slug => $status ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $status['name'] ) );
			echo '<td>' . $this->toggle( 'integrations', $slug, $status['enabled'], $status['locked'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $status['loaded'] ) {
				echo '<td><span style="color:#1a7f37;">' . esc_html__( 'Active', 'switchyard-crm' ) . '</span></td>';
			} elseif ( $status['available'] ) {
				echo '<td>' . esc_html__( 'Available, not loaded', 'switchyard-crm' ) . '</td>';
			} else {
				echo '<td><span style="color:#646970;">' . esc_html__( 'Plugin not active', 'switchyard-crm' ) . '</span></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		$modules = $this->module_toggles();

		if ( array() !== $modules ) {
			echo '<h2>' . esc_html__( 'Modules', 'switchyard-crm' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Module', 'switchyard-crm' ) . '</th>';
			echo '<th>' . esc_html__( 'Enabled', 'switchyard-crm' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $modules as $slug => $module ) {
				printf( '<tr><td>%s</td>', esc_html( $module['label'] ) );
				echo '<td>' . $this->toggle( 'modules', $slug, $module['enabled'], $module['locked'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</tbody></table>';
		}

		submit_button();

		echo '</form>';
	}

	/**
	 * The checkbox markup, escaped here. A locked toggle is one a filter
	 * overrides, so the stored choice would have no effect.
	 */
	private function toggle( string $group, string $slug, bool $enabled, bool $locked ): string {
		$html = sprintf(
			'<input type="checkbox" name="toggle[%s][%s]" value="1" %s %s>',
			esc_attr( $group ),
			esc_attr( $slug ),
			checked( $enabled, true, false ),
			disabled( $locked, true, false )
		);

		if ( $locked ) {
			$html .= ' <span class="description">' . esc_html__( 'Set by a filter on this site.', 'switchyard-crm' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Modules that companion plugins let the owner switch off.
	 *
	 * @return array<string, array{label: string, enabled: bool, locked: bool}>
	 */
	private function module_toggles(): array {
		/** @var array<string, array{label: string, enabled: bool, locked: bool}> $modules */
		$modules = apply_filters( 'switchyard_module_toggles', array() );

		return $modules;
	}

	private function render_logs(): void {
		global $wpdb;

		$table = LogTable::name();

		// $table is $wpdb->prefix . 'switchyard_log'; identifiers cannot be bound,
		// and an admin log viewer is deliberately uncached.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200" );

		$this->form_open( 'logs' );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="switchyard-loglevel">' . esc_html__( 'Log level', 'switchyard-crm' ) . '</label></th><td>';
		echo '<select id="switchyard-loglevel" name="log_level">';

		foreach ( array( 'debug', 'info', 'notice', 'warning', 'error' ) as $level ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $level ),
				selected( $this->settings->string( 'log_level' ), $level, false ),
				esc_html( ucfirst( $level ) )
			);
		}

		echo '</select></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save', 'switchyard-crm' ), 'secondary' );
		echo '</form>';

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=switchyard_action&do=clear_logs' ),
					'switchyard_action'
				)
			),
			esc_html__( 'Clear logs', 'switchyard-crm' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'switchyard-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'switchyard-crm' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing logged yet.', 'switchyard-crm' ) . '</td></tr>';
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( (string) $row->created_at ) );
			printf( '<td>%s</td>', esc_html( (string) $row->level ) );
			printf( '<td>%s</td>', esc_html( (string) $row->source ) );
			printf(
				'<td>%s%s</td>',
				esc_html( (string) $row->message ),
				$row->context
					? '<details><summary>' . esc_html__( 'Context', 'switchyard-crm' ) . '</summary><pre style="white-space:pre-wrap;">' . esc_html( (string) $row->context ) . '</pre></details>'
					: ''
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_tools(): void {
		$actions = array(
			'refresh_catalogues' => __( 'Refresh tags and fields from the CRM', 'switchyard-crm' ),
			'resync_users'       => __( 'Resync all users to the CRM', 'switchyard-crm' ),
			'refresh_all_tags'   => __( 'Pull tags for all users from the CRM', 'switchyard-crm' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $actions as $do => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>';
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=switchyard_action&do=' . $do ),
						'switchyard_action'
					)
				),
				esc_html__( 'Run', 'switchyard-crm' )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Batch jobs run in the background. Watch the Logs tab for progress.', 'switchyard-crm' ) . '</p>';
	}

	private function checkbox_row( string $key, string $label ): void {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s> %s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( $this->settings->bool( $key ), true, false ),
			esc_html__( 'Enabled', 'switchyard-crm' )
		);
	}

	public function handle_save(): void {
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

		if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( 'switchyard_save_' . $tab ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'switchyard-crm' ) );
		}

		$notice = 'saved';

		switch ( $tab ) {
			case 'connection':
				$notice = $this->save_connection();
				break;
			case 'sync':
				$this->save_sync();
				break;
			case 'access':
				$this->save_access();
				break;
			case 'integrations':
				$this->save_integrations();
				break;
			case 'logs':
				$this->save_logs();
				break;
		}

		$this->redirect( $tab, $notice );
	}

	/**
	 * The nonce is verified in handle_save() before this is reached.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function save_connection(): string {
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		// Unslashed here and sanitized per key and per value in the loop below,
		// which the sniff cannot follow across an array traversal.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['credentials'] ) && is_array( $_POST['credentials'] )
			? wp_unslash( $_POST['credentials'] )
			: array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$existing = $this->settings->credentials();
		$clean    = array();

		foreach ( $submitted as $key => $value ) {
			$key   = sanitize_key( (string) $key );
			$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

			// A blank password field means "keep what is stored", so the user
			// does not have to re-enter a key to change an unrelated setting.
			if ( '' === $value && isset( $existing[ $key ] ) ) {
				$clean[ $key ] = $existing[ $key ];

				continue;
			}

			$clean[ $key ] = $value;
		}

		$this->settings->merge(
			array(
				'provider'    => $provider,
				'credentials' => $clean,
			)
		);

		$this->registry->reset();

		if ( '' === $provider ) {
			return 'saved';
		}

		return null === $this->registry->active() ? 'failed' : 'connected';
	}

	/**
	 * The nonce is verified in handle_save() before this is reached.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function save_sync(): void {
		$values = array(
			'sync_on_register'       => ! empty( $_POST['sync_on_register'] ),
			'sync_on_profile_update' => ! empty( $_POST['sync_on_profile_update'] ),
			'async_sync'             => ! empty( $_POST['async_sync'] ),
			'site_tracking'          => ! empty( $_POST['site_tracking'] ),
			'staging_mode'           => ! empty( $_POST['staging_mode'] ),
		);

		// The field is only shown for CRMs that send webhooks; keep the stored value otherwise.
		if ( $this->registry->active() instanceof SupportsWebhooks ) {
			$values['import_new_contacts'] = ! empty( $_POST['import_new_contacts'] );
		}

		$this->settings->merge( $values );

		// Same shape as the credentials array: sanitized per entry below.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['map'] ) && is_array( $_POST['map'] )
			? wp_unslash( $_POST['map'] )
			: array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$map = array();

		foreach ( $submitted as $wp_key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$crm_field = sanitize_text_field( (string) ( $entry['crm_field'] ?? '' ) );

			if ( '' === $crm_field || ! $this->fields->allows( sanitize_key( (string) $wp_key ) ) ) {
				continue;
			}

			$map[ sanitize_key( (string) $wp_key ) ] = array(
				'crm_field' => $crm_field,
				'type'      => sanitize_key( (string) ( $entry['type'] ?? 'text' ) ),
				'active'    => ! empty( $entry['active'] ),
			);
		}

		$this->fields->save( $map );
	}

	/**
	 * The nonce is verified in handle_save() before this is reached.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function save_access(): void {
		// wp_kses_post() and the int cast below are the sanitizers.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->settings->merge(
			array(
				'restricted_message'  => wp_kses_post( wp_unslash( $_POST['restricted_message'] ?? '' ) ),
				'restricted_redirect' => (int) wp_unslash( $_POST['restricted_redirect'] ?? 0 ),
				'filter_queries'      => ! empty( $_POST['filter_queries'] ),
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * The nonce is verified in handle_save() before this is reached.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function save_integrations(): void {
		$submitted = isset( $_POST['toggle'] ) && is_array( $_POST['toggle'] )
			? array_map( 'array_keys', array_filter( wp_unslash( $_POST['toggle'] ), 'is_array' ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$groups = array(
			'integrations' => array( 'disabled_integrations', $this->integrations->status() ),
			'modules'      => array( 'disabled_modules', $this->module_toggles() ),
		);

		$values = array();

		foreach ( $groups as $group => $config ) {
			list( $key, $rows ) = $config;

			$on = array_map( 'sanitize_key', $submitted[ $group ] ?? array() );
			// Slugs not listed here (a companion plugin is inactive) keep their stored state.
			$off = array_values( array_diff( $this->settings->array( $key ), array_keys( $rows ) ) );

			foreach ( $rows as $slug => $row ) {
				// A locked row has no live checkbox, so its stored choice stays as it was.
				if ( $row['locked'] ? $this->settings->is_switched_off( $key, $slug ) : ! in_array( $slug, $on, true ) ) {
					$off[] = $slug;
				}
			}

			$values[ $key ] = $off;
		}

		$this->settings->merge( $values );
	}

	/**
	 * The nonce is verified in handle_save() before this is reached.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function save_logs(): void {
		$level = isset( $_POST['log_level'] ) ? sanitize_key( wp_unslash( $_POST['log_level'] ) ) : 'error';

		$this->settings->set(
			'log_level',
			array_key_exists( $level, Logger::LEVELS ) ? $level : 'error'
		);
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( 'switchyard_action' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'switchyard-crm' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		$notice = 'saved';
		$tab    = 'tools';

		switch ( $do ) {
			case 'rotate_secret':
				Secret::rotate();
				$notice = 'rotated';
				$tab    = 'connection';
				break;

			case 'clear_logs':
				global $wpdb;
				$table = LogTable::name();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query( "TRUNCATE TABLE {$table}" );
				$notice = 'logscleared';
				$tab    = 'logs';
				break;

			case 'refresh_catalogues':
				$provider = $this->registry->active();

				if ( $provider instanceof \Switchyard\Crm\Provider\AbstractProvider ) {
					$provider->flush_cache();
				}

				$notice = 'refreshed';
				break;

			case 'resync_users':
				Batch::queue_all( 'switchyard_batch_push_user' );
				break;

			case 'refresh_all_tags':
				Batch::queue_all( 'switchyard_refresh_user_tags' );
				break;
		}

		$this->redirect( $tab, $notice );
	}

	private function redirect( string $tab, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'tab'               => $tab,
					'switchyard_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}
}
