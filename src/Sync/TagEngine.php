<?php

declare( strict_types=1 );

namespace Conduit\Sync;

use Conduit\Crm\Exception\CrmException;
use Conduit\Crm\ProviderRegistry;
use Conduit\Logger\Logger;
use Conduit\Settings;

/**
 * Reads and writes a user's CRM tags.
 *
 * Access control consults this on every request, so reads come from user meta
 * and never touch the network. The cache is refreshed on login, on webhook,
 * and whenever this site writes a tag.
 */
final class TagEngine {

	public const META_TAGS = 'conduit_tags';

	private ?SegmentAdapter $adapter = null;

	public function __construct(
		private ProviderRegistry $registry,
		private ContactResolver $resolver,
		private Settings $settings,
		private Logger $logger,
	) {}

	public function adapter(): ?SegmentAdapter {
		if ( null !== $this->adapter ) {
			return $this->adapter;
		}

		$provider = $this->registry->active();

		if ( null === $provider ) {
			return null;
		}

		$this->adapter = new SegmentAdapter( $provider );

		return $this->adapter;
	}

	/**
	 * The user's tags, from cache.
	 *
	 * @return list<string>
	 */
	public function get_tags( int $user_id ): array {
		$tags = get_user_meta( $user_id, self::META_TAGS, true );

		$tags = is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array();

		/** @var list<string> $tags */
		$tags = apply_filters( 'conduit_user_tags', $tags, $user_id );

		return $tags;
	}

	/**
	 * @param list<string> $tags
	 */
	public function set_cache( int $user_id, array $tags ): void {
		$tags = array_values( array_unique( array_map( 'strval', $tags ) ) );

		update_user_meta( $user_id, self::META_TAGS, $tags );

		do_action( 'conduit_tags_updated', $user_id, $tags );
	}

	public function has_tag( int $user_id, string $tag ): bool {
		$tags = $this->get_tags( $user_id );

		if ( in_array( $tag, $tags, true ) ) {
			return true;
		}

		// Callers frequently pass a label where an ID is stored.
		$adapter = $this->adapter();

		if ( null === $adapter ) {
			return false;
		}

		$resolved = $adapter->resolve( array( $tag ) );

		return ! empty( $resolved ) && ! empty( array_intersect( $resolved, $tags ) );
	}

	/**
	 * @param list<string> $required
	 */
	public function has_any_tag( int $user_id, array $required ): bool {
		if ( empty( $required ) ) {
			return true;
		}

		$tags = $this->get_tags( $user_id );

		return ! empty( array_intersect( array_map( 'strval', $required ), $tags ) );
	}

	/**
	 * @param list<string> $required
	 */
	public function has_all_tags( int $user_id, array $required ): bool {
		if ( empty( $required ) ) {
			return true;
		}

		$tags = $this->get_tags( $user_id );

		return empty( array_diff( array_map( 'strval', $required ), $tags ) );
	}

	/**
	 * Pulls the authoritative tag set from the CRM.
	 *
	 * @return list<string>
	 */
	public function load_tags( int $user_id ): array {
		$adapter = $this->adapter();

		if ( null === $adapter || ! $adapter->can_read_membership() ) {
			return $this->get_tags( $user_id );
		}

		$contact_id = $this->resolver->resolve( $user_id );

		if ( null === $contact_id ) {
			return $this->get_tags( $user_id );
		}

		try {
			$tags = $adapter->get( $contact_id );
		} catch ( CrmException $e ) {
			$this->logger->warning(
				'Could not read tags from the CRM.',
				array(
					'error'   => $e->getMessage(),
					'user_id' => $user_id,
				)
			);

			return $this->get_tags( $user_id );
		}

		$this->set_cache( $user_id, $tags );

		return $tags;
	}

	/**
	 * @param list<string> $tags Identifiers or labels.
	 */
	public function apply_tags( int $user_id, array $tags ): bool {
		return $this->write( $user_id, $tags, true );
	}

	/**
	 * @param list<string> $tags Identifiers or labels.
	 */
	public function remove_tags( int $user_id, array $tags ): bool {
		return $this->write( $user_id, $tags, false );
	}

	/**
	 * @param list<string> $tags
	 */
	private function write( int $user_id, array $tags, bool $applying ): bool {
		if ( empty( $tags ) ) {
			return true;
		}

		$adapter = $this->adapter();

		if ( null === $adapter || ! $adapter->supported() ) {
			return false;
		}

		if ( $this->settings->is_staging() ) {
			$this->logger->info(
				'Staging mode is on, so this tag change was not sent to the CRM.',
				array(
					'tags'    => $tags,
					'user_id' => $user_id,
				)
			);

			return false;
		}

		$resolved = $adapter->resolve( $tags, $applying );

		if ( empty( $resolved ) ) {
			$this->logger->notice(
				'None of these tags exist in the CRM, so nothing was written.',
				array(
					'tags'    => $tags,
					'user_id' => $user_id,
				)
			);

			return false;
		}

		$current = $this->get_tags( $user_id );

		// Skip the API call when the cache already agrees. This is the single
		// biggest saving on sites that re-run the same automation on every
		// order status change.
		$delta = $applying
			? array_diff( $resolved, $current )
			: array_intersect( $resolved, $current );

		if ( empty( $delta ) ) {
			return true;
		}

		$contact_id = $this->resolver->resolve( $user_id );

		if ( null === $contact_id ) {
			return false;
		}

		try {
			if ( $applying ) {
				$adapter->apply( $contact_id, array_values( $delta ) );
			} else {
				$adapter->remove( $contact_id, array_values( $delta ) );
			}
		} catch ( CrmException $e ) {
			$this->logger->error(
				$applying ? 'Could not apply tags.' : 'Could not remove tags.',
				array(
					'error'   => $e->getMessage(),
					'tags'    => $delta,
					'user_id' => $user_id,
				)
			);

			return false;
		}

		$this->set_cache(
			$user_id,
			$applying
				? array_merge( $current, array_values( $delta ) )
				: array_values( array_diff( $current, $delta ) )
		);

		return true;
	}

	/**
	 * Labels for the tag IDs a user holds, for display in the admin.
	 *
	 * @param list<string> $tag_ids
	 * @return array<string, string>
	 */
	public function labels( array $tag_ids ): array {
		$adapter = $this->adapter();

		if ( null === $adapter ) {
			return array();
		}

		try {
			$available = $adapter->available();
		} catch ( CrmException ) {
			return array();
		}

		$labels = array();

		foreach ( $tag_ids as $id ) {
			$labels[ (string) $id ] = $available[ (string) $id ] ?? (string) $id;
		}

		return $labels;
	}
}
