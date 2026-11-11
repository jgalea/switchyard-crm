<?php

declare( strict_types=1 );

namespace Switchyard\Integration\Forms;

use Switchyard\Features;
use Switchyard\Integration\AbstractIntegration;
use Switchyard\Logger\Logger;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

/**
 * Shared behaviour for form plugins.
 *
 * Every form plugin does the same three things differently: name its fields,
 * expose its forms, and fire on submission. Subclasses supply those three
 * answers; everything after "we have an email and some values" is here.
 *
 * Per-form mapping is stored in Switchyard's own option rather than in each form
 * plugin's settings, so the configuration screen is identical for all of them
 * and a site can move a mapping between form plugins.
 */
abstract class AbstractFormIntegration extends AbstractIntegration {

	protected GuestSync $guests;
	public function __construct( UserSync $sync, TagEngine $tags, Queue $queue, Features $features, Logger $logger, GuestSync $guests ) {
		$this->guests = $guests;
		parent::__construct( $sync, $tags, $queue, $features, $logger );
	}
	/**
		* Forms available to configure.
		*
		* @return array<string, string> Form ID => title.
		*/
	abstract public function get_forms(): array;

	/**
	 * Fields on one form.
	 *
	 * @return array<string, string> Field key => label.
	 */
	abstract public function get_form_fields( string $form_id ): array;

	/**
	 * Configuration for one form.
	 *
	 * @return array{enabled: bool, map: array<string, string>, tags: list<string>, email_field: string, update_existing: bool}
	 */
	protected function form_config( string $form_id ): array {
		$all    = $this->options();
		$config = $all['forms'][ $form_id ] ?? array();

		return array(
			'enabled'         => ! empty( $config['enabled'] ),
			'map'             => is_array( $config['map'] ?? null ) ? $config['map'] : array(),
			'tags'            => is_array( $config['tags'] ?? null ) ? array_map( 'strval', $config['tags'] ) : array(),
			'email_field'     => (string) ( $config['email_field'] ?? '' ),
			'update_existing' => ! array_key_exists( 'update_existing', $config ) || ! empty( $config['update_existing'] ),
		);
	}

	/**
	 * The single path every form submission takes.
	 *
	 * @param array<string, mixed> $values Field key => submitted value.
	 */
	protected function process_submission( string $form_id, array $values ): void {
		$config = $this->form_config( $form_id );

		if ( ! $config['enabled'] ) {
			return;
		}

		$email = $this->find_email( $values, $config['email_field'] );

		if ( null === $email ) {
			$this->logger->notice(
				'A form submission had no email address, so nothing was synced.',
				array( 'form_id' => $form_id ),
				$this->get_slug()
			);

			return;
		}

		$fields = array();

		foreach ( $config['map'] as $form_field => $crm_field ) {
			if ( '' === $crm_field || ! isset( $values[ $form_field ] ) ) {
				continue;
			}

			$value = $values[ $form_field ];

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			// isset() above already ruled out null.
			if ( '' === $value ) {
				continue;
			}

			$fields[ $crm_field ] = $value;
		}

		/** @var array<string, mixed> $fields */
		$fields = apply_filters( 'switchyard_form_fields', $fields, $form_id, $values, $this->get_slug() );

		/** @var list<string> $tags */
		$tags = apply_filters( 'switchyard_form_tags', $config['tags'], $form_id, $values, $this->get_slug() );

		// Deferred, because a form plugin's submit handler is on the critical
		// path of a user waiting for a thank-you page.
		// A form can't overwrite the CRM record of a registered user unless that
		// user is the one submitting it.
		$owner  = get_user_by( 'email', $email );
		$update = $config['update_existing'] && ( ! $owner instanceof \WP_User || get_current_user_id() === $owner->ID );

		$this->queue->dispatch( GuestSync::HOOK, array( $email, $fields, $tags, $update ) );

		do_action( 'switchyard_form_submitted', $form_id, $email, $fields, $tags, $this->get_slug() );
	}

	/**
	 * @param array<string, mixed> $values
	 */
	protected function find_email( array $values, string $preferred = '' ): ?string {
		if ( '' !== $preferred && isset( $values[ $preferred ] ) ) {
			$candidate = $values[ $preferred ];

			if ( is_string( $candidate ) && is_email( $candidate ) ) {
				return $candidate;
			}
		}

		// Falling back to the first email-shaped value keeps a form working
		// when someone rebuilds it and the field key changes.
		foreach ( $values as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return null;
	}
}
