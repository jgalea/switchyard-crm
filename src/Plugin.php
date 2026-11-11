<?php

declare( strict_types=1 );

namespace Switchyard;

use Switchyard\Access\ContentGate;
use Switchyard\Access\MenuFilter;
use Switchyard\Access\QueryFilter;
use Switchyard\Access\Rules;
use Switchyard\Access\Shortcodes;
use Switchyard\Admin\AdminPages;
use Switchyard\Admin\IntegrationSettings;
use Switchyard\Admin\PostMetaBox;
use Switchyard\Admin\TagPicker;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Integration\Ecommerce\EasyDigitalDownloads;
use Switchyard\Integration\Ecommerce\WooCommerce;
use Switchyard\Integration\Forms\ContactForm7;
use Switchyard\Integration\Forms\FluentForms;
use Switchyard\Integration\Forms\GravityForms;
use Switchyard\Integration\Forms\WPForms;
use Switchyard\Integration\Registry as IntegrationRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\FieldMap;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;
use Switchyard\Tracking\SiteTracking;
use Switchyard\Webhook\Listener;

final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();

		$this->register_services();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	public function container(): Container {
		return $this->container;
	}

	private function register_services(): void {
		$c = $this->container;

		$c->set( Settings::class, static fn (): Settings => new Settings() );

		$c->set(
			Logger::class,
			static fn ( Container $c ): Logger => new Logger( $c->get( Settings::class ) )
		);

		$c->set(
			Client::class,
			static fn ( Container $c ): Client => new Client( $c->get( Logger::class ) )
		);

		$c->set(
			Features::class,
			static fn (): Features => new Features()
		);

		$c->set(
			ProviderRegistry::class,
			static fn ( Container $c ): ProviderRegistry => new ProviderRegistry(
				$c->get( Client::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			ContactResolver::class,
			static fn ( Container $c ): ContactResolver => new ContactResolver(
				$c->get( ProviderRegistry::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			FieldMap::class,
			static fn (): FieldMap => new FieldMap()
		);

		$c->set(
			TagEngine::class,
			static fn ( Container $c ): TagEngine => new TagEngine(
				$c->get( ProviderRegistry::class ),
				$c->get( ContactResolver::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			Queue::class,
			static fn ( Container $c ): Queue => new Queue(
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			UserSync::class,
			static fn ( Container $c ): UserSync => new UserSync(
				$c->get( ProviderRegistry::class ),
				$c->get( ContactResolver::class ),
				$c->get( FieldMap::class ),
				$c->get( TagEngine::class ),
				$c->get( Queue::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			GuestSync::class,
			static fn ( Container $c ): GuestSync => new GuestSync(
				$c->get( ProviderRegistry::class ),
				$c->get( ContactResolver::class ),
				$c->get( FieldMap::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			Rules::class,
			static fn ( Container $c ): Rules => new Rules( $c->get( TagEngine::class ) )
		);

		$c->set(
			ContentGate::class,
			static fn ( Container $c ): ContentGate => new ContentGate(
				$c->get( Rules::class ),
				$c->get( Settings::class )
			)
		);

		$c->set(
			QueryFilter::class,
			static fn ( Container $c ): QueryFilter => new QueryFilter(
				$c->get( Rules::class ),
				$c->get( Settings::class )
			)
		);

		$c->set(
			MenuFilter::class,
			static fn ( Container $c ): MenuFilter => new MenuFilter(
				$c->get( Rules::class ),
				$c->get( TagEngine::class )
			)
		);

		$c->set(
			Shortcodes::class,
			static fn ( Container $c ): Shortcodes => new Shortcodes( $c->get( TagEngine::class ), $c->get( FieldMap::class ) )
		);

		$c->set(
			Listener::class,
			static fn ( Container $c ): Listener => new Listener(
				$c->get( ProviderRegistry::class ),
				$c->get( ContactResolver::class ),
				$c->get( UserSync::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			)
		);

		$c->set(
			SiteTracking::class,
			static fn ( Container $c ): SiteTracking => new SiteTracking(
				$c->get( ProviderRegistry::class ),
				$c->get( ContactResolver::class ),
				$c->get( Settings::class )
			)
		);

		$c->set(
			IntegrationRegistry::class,
			static fn ( Container $c ): IntegrationRegistry => new IntegrationRegistry(
				$c,
				$c->get( Logger::class ),
				$c->get( Settings::class )
			)
		);

		$c->set(
			AdminPages::class,
			static fn ( Container $c ): AdminPages => new AdminPages(
				$c->get( Settings::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( FieldMap::class ),
				$c->get( IntegrationRegistry::class ),
				$c->get( IntegrationSettings::class )
			)
		);

		$c->set(
			TagPicker::class,
			static fn ( Container $c ): TagPicker => new TagPicker( $c->get( TagEngine::class ) )
		);

		$c->set(
			IntegrationSettings::class,
			static fn ( Container $c ): IntegrationSettings => new IntegrationSettings(
				$c->get( Settings::class ),
				$c->get( TagPicker::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( IntegrationRegistry::class )
			)
		);

		$c->set(
			PostMetaBox::class,
			static fn ( Container $c ): PostMetaBox => new PostMetaBox(
				$c->get( Rules::class ),
				$c->get( TagEngine::class )
			)
		);
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Installer::maybe_upgrade();

		$this->boot_action_scheduler();

		$this->get( UserSync::class )->register_hooks();
		$this->get( GuestSync::class )->register_hooks();
		$this->get( ContentGate::class )->register_hooks();
		$this->get( QueryFilter::class )->register_hooks();
		$this->get( MenuFilter::class )->register_hooks();
		$this->get( Shortcodes::class )->register_hooks();
		$this->get( Listener::class )->register_hooks();
		$this->get( SiteTracking::class )->register_hooks();

		if ( is_admin() ) {
			$this->get( AdminPages::class )->register_hooks();
			$this->get( PostMetaBox::class )->register_hooks();
		}

		$this->register_async_handlers();
		$this->register_integrations();
		$this->register_maintenance();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'switchyard', Cli\Command::class );
		}

		// Companion plugins register their features and integrations here.
		do_action( 'switchyard_register_features', $this->get( Features::class ) );
		do_action( 'switchyard_loaded', $this );
	}

	/**
	 * Action Scheduler is bundled, but WooCommerce and several other plugins
	 * ship their own copy. Loading it unconditionally would define its
	 * functions twice, so only load ours when nothing else has.
	 */
	private function boot_action_scheduler(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$bundled = SWITCHYARD_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

		if ( is_readable( $bundled ) ) {
			require_once $bundled;
		}
	}

	private function register_async_handlers(): void {
		$tags = $this->get( TagEngine::class );
		$sync = $this->get( UserSync::class );

		add_action(
			'switchyard_apply_tags_async',
			static function ( int $user_id, array $tag_list ) use ( $sync ): void {
				/** @var list<string> $tag_list */
				$sync->apply_tags_creating_contact( $user_id, $tag_list );
			},
			10,
			2
		);

		add_action(
			'switchyard_remove_tags_async',
			static function ( int $user_id, array $tag_list ) use ( $tags ): void {
				/** @var list<string> $tag_list */
				$tags->remove_tags( $user_id, $tag_list );
			},
			10,
			2
		);

		add_action(
			'switchyard_refresh_user_tags',
			static function ( int $user_id ) use ( $tags ): void {
				$tags->load_tags( $user_id );
			}
		);

		// Batch jobs use their own hook so a bulk resync can be cancelled
		// without also cancelling live per-user syncs sitting in the queue.
		add_action(
			'switchyard_batch_push_user',
			static function ( int $user_id ) use ( $sync ): void {
				$sync->push( $user_id );
			}
		);
	}

	private function register_integrations(): void {
		$registry = $this->get( IntegrationRegistry::class );

		$registry->add(
			'woocommerce',
			fn ( Container $c ): WooCommerce => new WooCommerce(
				$c->get( UserSync::class ),
				$c->get( TagEngine::class ),
				$c->get( Queue::class ),
				$c->get( Features::class ),
				$c->get( Logger::class )
			)
		);

		$registry->add(
			'edd',
			fn ( Container $c ): EasyDigitalDownloads => new EasyDigitalDownloads(
				$c->get( UserSync::class ),
				$c->get( TagEngine::class ),
				$c->get( Queue::class ),
				$c->get( Features::class ),
				$c->get( Logger::class )
			)
		);

		foreach (
			array(
				'gravity-forms'  => GravityForms::class,
				'wpforms'        => WPForms::class,
				'fluent-forms'   => FluentForms::class,
				'contact-form-7' => ContactForm7::class,
			) as $slug => $class
		) {
			$registry->add(
				$slug,
				static fn ( Container $c ): object => new $class(
					$c->get( UserSync::class ),
					$c->get( TagEngine::class ),
					$c->get( Queue::class ),
					$c->get( Features::class ),
					$c->get( Logger::class ),
					$c->get( GuestSync::class )
				)
			);
		}

		// Late, so integrations attach after their host plugin has loaded.
		add_action( 'init', array( $registry, 'boot' ), 5 );
	}

	private function register_maintenance(): void {
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( 'switchyard_prune_logs' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'switchyard_prune_logs' );
				}
			}
		);

		add_action(
			'switchyard_prune_logs',
			function (): void {
				$this->get( Logger::class )->prune();
			}
		);

		// Anything that can change which posts are restricted has to drop the
		// cache. Term rules are stored as term meta, and meta writes fire
		// their own hooks rather than edited_term, so a newly restricted
		// category would otherwise stay readable in archives, search, feeds
		// and the sitemap until the transient expired.
		$invalidators = array(
			'save_post',
			'deleted_post',
			'edited_term',
			'delete_term',
			'set_object_terms',
			'added_term_meta',
			'updated_term_meta',
			'deleted_term_meta',
		);

		foreach ( $invalidators as $hook ) {
			add_action( $hook, array( QueryFilter::class, 'flush_cache' ) );
		}
	}
}
