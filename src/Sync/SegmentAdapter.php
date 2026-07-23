<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Contracts\SupportsLists;
use Conduit\Crm\Contracts\SupportsTags;
use Conduit\Crm\Exception\CrmException;

/**
 * Presents one segmentation model regardless of what the CRM calls it.
 *
 * Roughly half the supported CRMs have tags; the rest only have lists,
 * audiences or groups. Access control has to work on both, so everything
 * above this layer talks about "tags" and this class decides whether that
 * means a tag call or a list call.
 */
final class SegmentAdapter {

	private const MODE_TAGS  = 'tags';
	private const MODE_LISTS = 'lists';
	private const MODE_NONE  = 'none';

	public function __construct( private Provider $provider ) {}

	private function mode(): string {
		if ( $this->provider instanceof SupportsTags ) {
			return self::MODE_TAGS;
		}

		if ( $this->provider instanceof SupportsLists ) {
			return self::MODE_LISTS;
		}

		return self::MODE_NONE;
	}

	public function supported(): bool {
		return self::MODE_NONE !== $this->mode();
	}

	/**
	 * What the CRM calls its segmentation unit, for use in the admin UI.
	 */
	public function label(): string {
		return match ( $this->mode() ) {
			self::MODE_TAGS  => __( 'Tags', 'conduit-crm' ),
			self::MODE_LISTS => __( 'Lists', 'conduit-crm' ),
			default          => __( 'Segments', 'conduit-crm' ),
		};
	}

	/**
	 * @return array<string, string> Identifier => label.
	 *
	 * @throws CrmException
	 */
	public function available(): array {
		return match ( $this->mode() ) {
			self::MODE_TAGS  => $this->tags()->get_available_tags(),
			self::MODE_LISTS => $this->lists()->get_available_lists(),
			default          => array(),
		};
	}

	/**
	 * @return list<string>
	 *
	 * @throws CrmException
	 */
	public function get( string $contact_id ): array {
		return match ( $this->mode() ) {
			self::MODE_TAGS => $this->tags()->get_tags( $contact_id ),
			// List providers have no per-contact membership read that is
			// cheap enough to call on every page load, so membership is
			// tracked from the writes this site makes.
			default         => array(),
		};
	}

	/**
	 * Whether get() returns authoritative data, or whether the local cache is
	 * the only record of membership.
	 */
	public function can_read_membership(): bool {
		return self::MODE_TAGS === $this->mode();
	}

	/**
	 * @param list<string> $ids
	 *
	 * @throws CrmException
	 */
	public function apply( string $contact_id, array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}

		match ( $this->mode() ) {
			self::MODE_TAGS  => $this->tags()->apply_tags( $contact_id, $ids ),
			self::MODE_LISTS => $this->lists()->add_to_lists( $contact_id, $ids ),
			default          => null,
		};
	}

	/**
	 * @param list<string> $ids
	 *
	 * @throws CrmException
	 */
	public function remove( string $contact_id, array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}

		match ( $this->mode() ) {
			self::MODE_TAGS  => $this->tags()->remove_tags( $contact_id, $ids ),
			self::MODE_LISTS => $this->lists()->remove_from_lists( $contact_id, $ids ),
			default          => null,
		};
	}

	/**
	 * @throws CrmException
	 */
	public function create( string $label ): string {
		if ( self::MODE_TAGS === $this->mode() ) {
			return $this->tags()->create_tag( $label );
		}

		throw new CrmException(
			__( 'This CRM does not support creating segments from WordPress. Create it in the CRM first.', 'conduit-crm' )
		);
	}

	public function can_create(): bool {
		return self::MODE_TAGS === $this->mode();
	}

	/**
	 * Resolves a mixed list of identifiers and labels to identifiers.
	 *
	 * Integrations are configured with whatever the site owner typed, so a
	 * value may be an ID, an exact label, or a label that differs only by
	 * case. Unresolvable values are dropped rather than sent, because a bad
	 * identifier is a silent no-op in most CRMs and an error in the rest.
	 *
	 * @param list<string> $values
	 * @return list<string>
	 */
	public function resolve( array $values, bool $create_missing = false ): array {
		if ( empty( $values ) ) {
			return array();
		}

		try {
			$available = $this->available();
		} catch ( CrmException ) {
			return array();
		}

		$by_label = array();

		foreach ( $available as $id => $label ) {
			$by_label[ strtolower( $label ) ] = (string) $id;
		}

		$resolved = array();

		foreach ( $values as $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				continue;
			}

			if ( isset( $available[ $value ] ) ) {
				$resolved[] = $value;

				continue;
			}

			$key = strtolower( $value );

			if ( isset( $by_label[ $key ] ) ) {
				$resolved[] = $by_label[ $key ];

				continue;
			}

			if ( $create_missing && $this->can_create() ) {
				try {
					$resolved[] = $this->create( $value );
				} catch ( CrmException ) {
					continue;
				}
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	private function tags(): SupportsTags {
		/** @var SupportsTags $provider */
		$provider = $this->provider;

		return $provider;
	}

	private function lists(): SupportsLists {
		/** @var SupportsLists $provider */
		$provider = $this->provider;

		return $provider;
	}
}
