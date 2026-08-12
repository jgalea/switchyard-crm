<?php
/**
 * Public API.
 *
 * These are the functions a site's own code and third-party plugins call.
 * They are intentionally forgiving: a null user resolves to the current user,
 * tags may be passed as labels or identifiers, and nothing throws.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Switchyard\Crm\Contracts\Provider;
use Switchyard\Plugin;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

if ( ! function_exists( 'switchyard' ) ) {
	/**
	 * The plugin container, for callers that need a service directly.
	 */
	function switchyard(): Plugin {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'switchyard_apply_tags' ) ) {
	/**
	 * @param list<string> $tags Identifiers or labels.
	 */
	function switchyard_apply_tags( array $tags, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		return $engine->apply_tags( $user_id, $tags );
	}
}

if ( ! function_exists( 'switchyard_remove_tags' ) ) {
	/**
	 * @param list<string> $tags
	 */
	function switchyard_remove_tags( array $tags, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		return $engine->remove_tags( $user_id, $tags );
	}
}

if ( ! function_exists( 'switchyard_has_tag' ) ) {
	function switchyard_has_tag( string $tag, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		return $engine->has_tag( $user_id, $tag );
	}
}

if ( ! function_exists( 'switchyard_get_tags' ) ) {
	/**
	 * @return list<string>
	 */
	function switchyard_get_tags( ?int $user_id = null ): array {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return array();
		}

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		return $engine->get_tags( $user_id );
	}
}

if ( ! function_exists( 'switchyard_refresh_tags' ) ) {
	/**
	 * Pulls the authoritative tag set from the CRM.
	 *
	 * @return list<string>
	 */
	function switchyard_refresh_tags( ?int $user_id = null ): array {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return array();
		}

		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		return $engine->load_tags( $user_id );
	}
}

if ( ! function_exists( 'switchyard_sync_user' ) ) {
	/**
	 * Pushes a user to the CRM now, bypassing the queue.
	 *
	 * @param array<string, mixed> $overrides
	 */
	function switchyard_sync_user( ?int $user_id = null, array $overrides = array() ): ?string {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		/** @var UserSync $sync */
		$sync = Plugin::instance()->get( UserSync::class );

		return $sync->push( $user_id, $overrides );
	}
}

if ( ! function_exists( 'switchyard_sync_contact' ) ) {
	/**
	 * Creates or updates a contact with no WordPress user behind it.
	 *
	 * @param array<string, mixed> $fields Keyed by CRM field identifier.
	 * @param list<string>         $tags
	 */
	function switchyard_sync_contact( string $email, array $fields = array(), array $tags = array() ): ?string {
		/** @var GuestSync $guests */
		$guests = Plugin::instance()->get( GuestSync::class );

		return $guests->push( $email, $fields, $tags );
	}
}

if ( ! function_exists( 'switchyard_get_contact_id' ) ) {
	function switchyard_get_contact_id( ?int $user_id = null, bool $refresh = false ): ?string {
		$user_id ??= get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		/** @var ContactResolver $resolver */
		$resolver = Plugin::instance()->get( ContactResolver::class );

		return $resolver->resolve( $user_id, $refresh );
	}
}

if ( ! function_exists( 'switchyard_can_access' ) ) {
	function switchyard_can_access( int $post_id, ?int $user_id = null ): bool {
		/** @var Switchyard\Access\Rules $rules */
		$rules = Plugin::instance()->get( Switchyard\Access\Rules::class );

		return $rules->can_access( $post_id, $user_id );
	}
}

if ( ! function_exists( 'switchyard_get_provider' ) ) {
	/**
	 * The connected CRM, or null when the site is not set up.
	 */
	function switchyard_get_provider(): ?Provider {
		/** @var Switchyard\Crm\ProviderRegistry $registry */
		$registry = Plugin::instance()->get( Switchyard\Crm\ProviderRegistry::class );

		return $registry->active();
	}
}

if ( ! function_exists( 'switchyard_available_tags' ) ) {
	/**
	 * Every tag in the CRM, for building a settings UI.
	 *
	 * @return array<string, string>
	 */
	function switchyard_available_tags(): array {
		/** @var TagEngine $engine */
		$engine = Plugin::instance()->get( TagEngine::class );

		$adapter = $engine->adapter();

		if ( null === $adapter ) {
			return array();
		}

		try {
			return $adapter->available();
		} catch ( Switchyard\Crm\Exception\CrmException $exception ) {
			return array();
		}
	}
}
