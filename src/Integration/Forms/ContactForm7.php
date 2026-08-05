<?php

declare( strict_types=1 );

namespace Conduit\Integration\Forms;

use WPCF7_ContactForm;
use WPCF7_Submission;

final class ContactForm7 extends AbstractFormIntegration {

	public function get_slug(): string {
		return 'contact-form-7';
	}

	public function get_name(): string {
		return 'Contact Form 7';
	}

	public function is_available(): bool {
		return class_exists( 'WPCF7' );
	}

	public function register_hooks(): void {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_submission' ), 20 );
	}

	/**
 * @param mixed $contact_form
 */
	public function on_submission( $contact_form ): void {
		$this->guard(
			function () use ( $contact_form ): void {
				if ( ! $contact_form instanceof WPCF7_ContactForm ) {
					return;
				}

				$submission = WPCF7_Submission::get_instance();

				if ( ! $submission instanceof WPCF7_Submission ) {
					return;
				}

				$data = $submission->get_posted_data();

				if ( ! is_array( $data ) ) {
					return;
				}

				$this->process_submission( (string) $contact_form->id(), $data );
			}
		);
	}

	public function get_forms(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => 200,
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$forms[ (string) $post->ID ] = $post->post_title;
		}

		return $forms;
	}

	/**
	 * Contact Form 7 has no field schema API, so the tags are read off the
	 * form definition itself.
	 */
	public function get_form_fields( string $form_id ): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$form = WPCF7_ContactForm::get_instance( (int) $form_id );

		if ( ! $form instanceof WPCF7_ContactForm ) {
			return array();
		}

		$fields = array();

		foreach ( $form->scan_form_tags() as $tag ) {
			$name = is_object( $tag ) ? (string) ( $tag->name ?? '' ) : '';

			if ( '' !== $name && ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = $name;
			}
		}

		return $fields;
	}
}
