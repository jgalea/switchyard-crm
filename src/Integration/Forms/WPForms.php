<?php

declare( strict_types=1 );

namespace Switchyard\Integration\Forms;

final class WPForms extends AbstractFormIntegration {

	public function get_slug(): string {
		return 'wpforms';
	}

	public function get_name(): string {
		return 'WPForms';
	}

	public function is_available(): bool {
		return function_exists( 'wpforms' );
	}

	public function register_hooks(): void {
		add_action( 'wpforms_process_complete', array( $this, 'on_submission' ), 20, 4 );
	}

	/**
 * @param array<int, array<string, mixed>> $fields
 * @param array<string, mixed>             $entry
 * @param array<string, mixed>             $form_data
 * @param mixed $entry_id
 */
	public function on_submission( array $fields, array $entry, array $form_data, $entry_id = 0 ): void {
		$this->guard(
			function () use ( $fields, $form_data ): void {
				$form_id = (string) ( $form_data['id'] ?? '' );

				if ( '' === $form_id ) {
					return;
				}

				$values = array();

				foreach ( $fields as $field_id => $field ) {
					$values[ (string) $field_id ] = $field['value'] ?? '';
				}

				$this->process_submission( $form_id, $values );
			}
		);
	}

	public function get_forms(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'wpforms',
				'posts_per_page' => 200,
				'post_status'    => 'publish',
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$forms[ (string) $post->ID ] = $post->post_title;
		}

		return $forms;
	}

	public function get_form_fields( string $form_id ): array {
		$post = get_post( (int) $form_id );

		if ( ! $post ) {
			return array();
		}

		$data = json_decode( $post->post_content, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$fields = array();

		foreach ( $data['fields'] ?? array() as $field_id => $field ) {
			$fields[ (string) $field_id ] = (string) ( $field['label'] ?? $field_id );
		}

		return $fields;
	}
}
