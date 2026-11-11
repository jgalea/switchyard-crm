<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Crm\Exception\CrmException;
use Switchyard\Integration\Forms\AbstractFormIntegration;
use Switchyard\Integration\Registry as IntegrationRegistry;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Settings;

/**
 * Tag and form settings that live on the Integrations tab.
 *
 * Renders inside the form AdminPages opens for that tab and saves from its
 * handler, so the nonce and capability check are AdminPages' own.
 */
final class IntegrationSettings {

	private const STORES = array(
		'woocommerce' => 'WooCommerce',
		'edd'         => 'Easy Digital Downloads',
	);

	private Settings $settings;
	private TagPicker $picker;
	private ProviderRegistry $registry;
	private IntegrationRegistry $integrations;
	public function __construct( Settings $settings, TagPicker $picker, ProviderRegistry $registry, IntegrationRegistry $integrations ) {
		$this->settings     = $settings;
		$this->picker       = $picker;
		$this->registry     = $registry;
		$this->integrations = $integrations;
	}
	/**
	 * @return array<string, mixed>
	 */
	private function stored( string $slug ): array {
		$stored = get_option( 'switchyard_integration_' . $slug, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private function tag_list( $value ): array {
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	public function render(): void {
		if ( null === $this->registry->active() ) {
			return;
		}

		$choices = $this->picker->choices();

		$this->render_registration( $choices );

		foreach ( self::STORES as $slug => $name ) {
			if ( $this->integrations->is_loaded( $slug ) ) {
				$this->render_store( $slug, $name, $choices );
			}
		}

		foreach ( $this->integrations->loaded() as $slug => $integration ) {
			if ( $integration instanceof AbstractFormIntegration ) {
				$this->render_forms( $slug, $integration, $choices );
			}
		}
	}

	/**
	 * @param array<string, string>|null $choices
	 */
	private function render_registration( ?array $choices ): void {
		echo '<h2>' . esc_html__( 'New users', 'switchyard-crm' ) . '</h2>';

		if ( null === $choices ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM that supports tags to choose registration tags.', 'switchyard-crm' ) . '</p>';

			return;
		}

		echo '<input type="hidden" name="register_tags_present" value="1">';
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Tags for new registrations', 'switchyard-crm' ) . '</th><td>';

		$this->picker->render( 'register_tags', 'switchyard-register-tags', __( 'Tags for new registrations', 'switchyard-crm' ), $choices, $this->tag_list( $this->settings->array( 'register_tags' ) ) );

		echo '<p class="description">' . esc_html__( 'Applied to every new user when they register.', 'switchyard-crm' ) . '</p>';
		echo '</td></tr></tbody></table>';
	}

	/**
	 * @param array<string, string>|null $choices
	 */
	private function render_store( string $slug, string $name, ?array $choices ): void {
		echo '<h2>' . esc_html( $name ) . '</h2>';

		if ( null === $choices ) {
			echo '<p class="description">' . esc_html__( 'Connect a CRM that supports tags to choose purchase tags.', 'switchyard-crm' ) . '</p>';

			return;
		}

		$stored = $this->stored( $slug );

		echo '<input type="hidden" name="store[' . esc_attr( $slug ) . '][present]" value="1">';
		echo '<table class="form-table" role="presentation"><tbody>';

		$rows = array(
			'purchase_tags' => array( __( 'Tags for every purchase', 'switchyard-crm' ), __( 'Applied to the customer on top of any tags set on the product.', 'switchyard-crm' ) ),
			'refund_tags'   => array( __( 'Tags when an order is refunded', 'switchyard-crm' ), '' ),
		);

		foreach ( $rows as $key => $row ) {
			$id = 'switchyard-' . $slug . '-' . $key;

			printf( '<tr><th scope="row">%s</th><td>', esc_html( $row[0] ) );
			$this->picker->render( 'store[' . $slug . '][' . $key . ']', $id, $row[0], $choices, $this->tag_list( $stored[ $key ] ?? array() ) );

			if ( '' !== $row[1] ) {
				echo '<p class="description">' . esc_html( $row[1] ) . '</p>';
			}

			echo '</td></tr>';
		}

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="store[%s][remove_tags_on_refund]" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Refunds', 'switchyard-crm' ),
			esc_attr( $slug ),
			checked( ! empty( $stored['remove_tags_on_refund'] ), true, false ),
			esc_html__( 'Remove the purchase tags when an order is refunded', 'switchyard-crm' )
		);

		if ( 'woocommerce' === $slug && function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $status_key => $status_label ) {
				$status = preg_replace( '/^wc-/', '', (string) $status_key );
				$tags   = $this->tag_list( $stored['status_tags'][ $status ] ?? array() );

				/* translators: %s: order status, such as Processing. */
				$label = sprintf( __( 'Tags when an order becomes %s', 'switchyard-crm' ), (string) $status_label );

				printf( '<tr><th scope="row">%s</th><td>', esc_html( $label ) );
				$this->picker->render(
					'store[woocommerce][status_tags][' . $status . ']',
					'switchyard-woocommerce-status-' . $status,
					$label,
					$choices,
					$tags
				);
				echo '</td></tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, string>|null $choices
	 */
	private function render_forms( string $slug, AbstractFormIntegration $integration, ?array $choices ): void {
		echo '<h2>' . esc_html( $integration->get_name() ) . '</h2>';

		$forms = $integration->get_forms();

		if ( array() === $forms ) {
			echo '<p class="description">' . esc_html__( 'No forms found yet.', 'switchyard-crm' ) . '</p>';

			return;
		}

		$provider = $this->registry->active();

		try {
			$crm_fields = null === $provider ? array() : $provider->get_available_fields();
		} catch ( CrmException $e ) {
			$crm_fields = array();
		}

		$stored = $this->stored( $slug )['forms'] ?? array();

		echo '<input type="hidden" name="forms[' . esc_attr( $slug ) . '][present]" value="1">';
		echo '<p class="description">' . esc_html__( 'Choose which forms send their entries to the CRM and which CRM field each form field fills.', 'switchyard-crm' ) . '</p>';

		foreach ( $forms as $form_id => $title ) {
			$config = is_array( $stored[ $form_id ] ?? null ) ? $stored[ $form_id ] : array();
			$base   = 'forms[' . $slug . '][' . $form_id . ']';
			$dom    = 'switchyard-' . $slug . '-' . $form_id;
			$fields = $integration->get_form_fields( (string) $form_id );
			$map    = is_array( $config['map'] ?? null ) ? $config['map'] : array();

			printf(
				'<details class="switchyard-form"%s><summary><strong>%s</strong>%s</summary>',
				! empty( $config['enabled'] ) ? ' open' : '',
				esc_html( $title ),
				! empty( $config['enabled'] ) ? ' <span class="description">' . esc_html__( 'Syncing', 'switchyard-crm' ) . '</span>' : ''
			);

			echo '<table class="form-table" role="presentation"><tbody>';

			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[enabled]" value="1" %s> %s</label></td></tr>',
				esc_html__( 'Sync', 'switchyard-crm' ),
				esc_attr( $base ),
				checked( ! empty( $config['enabled'] ), true, false ),
				esc_html__( 'Send entries from this form to the CRM', 'switchyard-crm' )
			);

			printf( '<tr><th scope="row"><label for="%s-email">%s</label></th><td>', esc_attr( $dom ), esc_html__( 'Email field', 'switchyard-crm' ) );
			printf( '<select id="%s-email" name="%s[email_field]">', esc_attr( $dom ), esc_attr( $base ) );
			echo '<option value="">' . esc_html__( 'Detect automatically', 'switchyard-crm' ) . '</option>';

			foreach ( $fields as $field_key => $field_label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( (string) $field_key ),
					selected( (string) ( $config['email_field'] ?? '' ), (string) $field_key, false ),
					esc_html( $field_label )
				);
			}

			echo '</select></td></tr>';

			if ( null !== $choices ) {
				printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Tags', 'switchyard-crm' ) );
				$this->picker->render( $base . '[tags]', $dom . '-tags', __( 'Tags', 'switchyard-crm' ), $choices, $this->tag_list( $config['tags'] ?? array() ) );
				echo '</td></tr>';
			}

			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[update_existing]" value="1" %s> %s</label></td></tr>',
				esc_html__( 'Existing contacts', 'switchyard-crm' ),
				esc_attr( $base ),
				checked( ! array_key_exists( 'update_existing', $config ) || ! empty( $config['update_existing'] ), true, false ),
				esc_html__( 'Update the CRM fields of a contact that already exists', 'switchyard-crm' )
			);

			echo '</tbody></table>';

			if ( array() !== $crm_fields && array() !== $fields ) {
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'Form field', 'switchyard-crm' ) . '</th>';
				echo '<th>' . esc_html__( 'CRM field', 'switchyard-crm' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $fields as $field_key => $field_label ) {
					$select_id = $dom . '-map-' . sanitize_key( str_replace( '.', '-', (string) $field_key ) );

					printf( '<tr><td><label for="%s">%s</label></td><td>', esc_attr( $select_id ), esc_html( $field_label ) );
					printf( '<select id="%s" name="%s[map][%s]">', esc_attr( $select_id ), esc_attr( $base ), esc_attr( (string) $field_key ) );
					echo '<option value="">' . esc_html__( '— None —', 'switchyard-crm' ) . '</option>';

					foreach ( $crm_fields as $crm_key => $crm_field ) {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( (string) $crm_key ),
							selected( (string) ( $map[ $field_key ] ?? '' ), (string) $crm_key, false ),
							esc_html( $crm_field['label'] )
						);
					}

					echo '</select></td></tr>';
				}

				echo '</tbody></table>';
			}

			echo '</details>';
		}
	}

	/**
	 * The nonce and capability are checked by AdminPages::handle_save().
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	public function save(): void {
		if ( ! empty( $_POST['register_tags_present'] ) ) {
			$this->settings->set( 'register_tags', TagPicker::posted( $_POST['register_tags'] ?? array() ) );
		}

		foreach ( self::STORES as $slug => $name ) {
			$posted = $_POST['store'][ $slug ] ?? null;

			if ( ! is_array( $posted ) || empty( $posted['present'] ) ) {
				continue;
			}

			$options = $this->stored( $slug );

			$options['purchase_tags']         = TagPicker::posted( $posted['purchase_tags'] ?? array() );
			$options['refund_tags']           = TagPicker::posted( $posted['refund_tags'] ?? array() );
			$options['remove_tags_on_refund'] = ! empty( $posted['remove_tags_on_refund'] );

			if ( 'woocommerce' === $slug ) {
				$status_tags = array();

				foreach ( is_array( $posted['status_tags'] ?? null ) ? $posted['status_tags'] : array() as $status => $tags ) {
					$tags = TagPicker::posted( $tags );

					if ( array() !== $tags ) {
						$status_tags[ sanitize_key( (string) $status ) ] = $tags;
					}
				}

				$options['status_tags'] = $status_tags;
			}

			update_option( 'switchyard_integration_' . $slug, $options );
		}

		foreach ( $this->integrations->loaded() as $slug => $integration ) {
			$posted = $_POST['forms'][ $slug ] ?? null;

			if ( ! $integration instanceof AbstractFormIntegration || ! is_array( $posted ) || empty( $posted['present'] ) ) {
				continue;
			}

			$forms = array();

			foreach ( array_keys( $integration->get_forms() ) as $form_id ) {
				$entry = $posted[ $form_id ] ?? null;

				if ( ! is_array( $entry ) ) {
					continue;
				}

				$valid = $integration->get_form_fields( (string) $form_id );
				$map   = array();

				foreach ( is_array( $entry['map'] ?? null ) ? $entry['map'] : array() as $field_key => $crm_field ) {
					$field_key = sanitize_text_field( (string) $field_key );
					$crm_field = is_scalar( $crm_field ) ? sanitize_text_field( wp_unslash( (string) $crm_field ) ) : '';

					if ( '' !== $crm_field && isset( $valid[ $field_key ] ) ) {
						$map[ $field_key ] = $crm_field;
					}
				}

				$email_field = isset( $entry['email_field'] ) && is_scalar( $entry['email_field'] ) ? sanitize_text_field( wp_unslash( (string) $entry['email_field'] ) ) : '';

				$forms[ (string) $form_id ] = array(
					'enabled'         => ! empty( $entry['enabled'] ),
					'email_field'     => isset( $valid[ $email_field ] ) ? $email_field : '',
					'map'             => $map,
					'tags'            => TagPicker::posted( $entry['tags'] ?? array() ),
					'update_existing' => ! empty( $entry['update_existing'] ),
				);
			}

			$options          = $this->stored( $slug );
			$options['forms'] = $forms;

			update_option( 'switchyard_integration_' . $slug, $options );
		}
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}
