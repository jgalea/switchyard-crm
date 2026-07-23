<?php

declare( strict_types=1 );

namespace Conduit\Integration\Forms;

final class FluentForms extends AbstractFormIntegration {

	public function get_slug(): string {
		return 'fluent-forms';
	}

	public function get_name(): string {
		return 'Fluent Forms';
	}

	public function is_available(): bool {
		return defined( 'FLUENTFORM' );
	}

	public function register_hooks(): void {
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submission' ), 20, 3 );
	}

	/**
	 * @param array<string, mixed> $form_data
	 */
	public function on_submission( int $entry_id, array $form_data, mixed $form = null ): void {
		$this->guard(
			function () use ( $form_data, $form ): void {
				$form_id = '';

				if ( is_object( $form ) && isset( $form->id ) ) {
					$form_id = (string) $form->id;
				} elseif ( is_array( $form ) && isset( $form['id'] ) ) {
					$form_id = (string) $form['id'];
				}

				if ( '' === $form_id ) {
					return;
				}

				$this->process_submission( $form_id, $this->flatten( $form_data ) );
			}
		);
	}

	/**
	 * Fluent Forms nests composite fields (names, addresses) one level deep.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function flatten( array $data, string $prefix = '' ): array {
		$flat = array();

		foreach ( $data as $key => $value ) {
			$full = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$flat += $this->flatten( $value, $full );

				continue;
			}

			$flat[ $full ] = $value;
		}

		return $flat;
	}

	public function get_forms(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE status = 'published'" );

		$forms = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$forms[ (string) $row->id ] = (string) $row->title;
		}

		return $forms;
	}

	public function get_form_fields( string $form_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT form_fields FROM {$table} WHERE id = %d", (int) $form_id ) );

		if ( ! is_string( $json ) ) {
			return array();
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		return $this->collect_fields( $data['fields'] ?? array() );
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<string, string>
	 */
	private function collect_fields( array $fields ): array {
		$collected = array();

		foreach ( $fields as $field ) {
			// Container fields hold their inputs in nested columns.
			if ( isset( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $column ) {
					$collected += $this->collect_fields( $column['fields'] ?? array() );
				}

				continue;
			}

			$name = $field['attributes']['name'] ?? null;

			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$label = $field['settings']['label'] ?? $name;

			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				foreach ( $field['fields'] as $sub_key => $sub ) {
					$sub_name = $sub['attributes']['name'] ?? $sub_key;

					$collected[ $name . '.' . $sub_name ] = $label . ' — ' . ( $sub['settings']['label'] ?? $sub_name );
				}

				continue;
			}

			$collected[ $name ] = (string) $label;
		}

		return $collected;
	}
}
