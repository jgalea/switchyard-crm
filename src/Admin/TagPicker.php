<?php

declare( strict_types=1 );

namespace Switchyard\Admin;

use Switchyard\Crm\Exception\CrmException;
use Switchyard\Sync\TagEngine;

/**
 * The CRM's tags as a labelled multi-select, shared by every screen that picks tags.
 */
final class TagPicker {

	private TagEngine $tags;
	public function __construct( TagEngine $tags ) {
		$this->tags = $tags;
	}
	/**
	 * @return array<string, string>|null Tag ID => label, or null when the CRM has no tags to list or could not be reached.
	 */
	public function choices(): ?array {
		$adapter = $this->tags->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			return null;
		}

		try {
			$choices = array();

			foreach ( $adapter->available() as $id => $label ) {
				$choices[ (string) $id ] = (string) $label;
			}

			return $choices;
		} catch ( CrmException $e ) {
			return null;
		}
	}

	/**
	 * @param array<string, string> $choices
	 * @param list<string>          $selected
	 */
	public function render( string $name, string $id, string $label, array $choices, array $selected ): void {
		// Tags that were saved but are no longer listed stay selectable, so
		// saving doesn't silently drop them.
		foreach ( $selected as $tag ) {
			if ( ! isset( $choices[ $tag ] ) ) {
				$choices[ $tag ] = '#' . $tag;
			}
		}

		printf( '<label for="%s" class="screen-reader-text">%s</label>', esc_attr( $id ), esc_html( $label ) );
		printf( '<select id="%s" name="%s[]" multiple size="6" class="switchyard-tags">', esc_attr( $id ), esc_attr( $name ) );

		foreach ( $choices as $tag => $tag_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $tag ),
				selected( in_array( (string) $tag, $selected, true ), true, false ),
				esc_html( $tag_label )
			);
		}

		echo '</select>';
	}

	/**
	 * @param mixed $value Raw posted value.
	 * @return list<string>
	 */
	public static function posted( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn ( $tag ): string => is_scalar( $tag ) ? sanitize_text_field( wp_unslash( (string) $tag ) ) : '',
					$value
				),
				static fn ( string $tag ): bool => '' !== $tag
			)
		);
	}
}
