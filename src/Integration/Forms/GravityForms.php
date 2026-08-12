<?php

declare( strict_types=1 );

namespace Switchyard\Integration\Forms;

use GFAPI;
use GFFormsModel;

final class GravityForms extends AbstractFormIntegration {

	public function get_slug(): string {
		return 'gravity-forms';
	}

	public function get_name(): string {
		return 'Gravity Forms';
	}

	public function is_available(): bool {
		return class_exists( 'GFAPI' );
	}

	public function register_hooks(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 20, 2 );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $form
	 */
	public function on_submission( array $entry, array $form ): void {
		$this->guard(
			function () use ( $entry, $form ): void {
				$form_id = (string) ( $form['id'] ?? '' );

				if ( '' === $form_id ) {
					return;
				}

				$values = array();

				foreach ( $form['fields'] ?? array() as $field ) {
					$field_id = (string) ( $field->id ?? '' );

					if ( '' === $field_id ) {
						continue;
					}

					// Composite fields (name, address) arrive as several
					// entry keys prefixed with the field ID.
					if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
						foreach ( $field->inputs as $input ) {
							$key = (string) ( $input['id'] ?? '' );

							if ( '' !== $key && isset( $entry[ $key ] ) ) {
								$values[ $key ] = $entry[ $key ];
							}
						}

						continue;
					}

					if ( isset( $entry[ $field_id ] ) ) {
						$values[ $field_id ] = $entry[ $field_id ];
					}
				}

				$this->process_submission( $form_id, $values );
			}
		);
	}

	public function get_forms(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$forms = array();

		foreach ( GFAPI::get_forms() as $form ) {
			$forms[ (string) $form['id'] ] = (string) ( $form['title'] ?? $form['id'] );
		}

		return $forms;
	}

	public function get_form_fields( string $form_id ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$form = GFAPI::get_form( (int) $form_id );

		if ( ! is_array( $form ) ) {
			return array();
		}

		$fields = array();

		foreach ( $form['fields'] ?? array() as $field ) {
			$label = (string) ( $field->label ?? '' );

			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					$key = (string) ( $input['id'] ?? '' );

					if ( '' !== $key ) {
						$fields[ $key ] = trim( $label . ' — ' . (string) ( $input['label'] ?? '' ), ' —' );
					}
				}

				continue;
			}

			$id = (string) ( $field->id ?? '' );

			if ( '' !== $id ) {
				$fields[ $id ] = '' !== $label ? $label : $id;
			}
		}

		return $fields;
	}
}
