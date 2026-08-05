<?php

declare( strict_types=1 );

namespace Conduit\Crm;

use Conduit\Crm\Contracts\Provider;
use Conduit\Crm\Exception\ConnectionException;
use Conduit\Crm\Http\Client;
use Conduit\Logger\Logger;
use Conduit\Settings;
use RuntimeException;

/**
 * Knows every available provider and hands back the connected one.
 */
final class ProviderRegistry {

	private Client $client;
	private Settings $settings;
	private Logger $logger;
	/** @var array<string, class-string<Provider>> */
	private array $providers = array();

	private ?Provider $active = null;

	private bool $connection_failed = false;

	public function __construct( Client $client, Settings $settings, Logger $logger ) {
		$this->client   = $client;
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->register_defaults();
	}

	private function register_defaults(): void {
		// Fully qualified deliberately: `Provider` is imported as the
		// contract interface, so a relative `Provider\Foo::class` would
		// resolve under the Contracts namespace and silently register
		// classes that do not exist.
		$defaults = array(
			\Conduit\Crm\Provider\ActiveCampaign::class,
			\Conduit\Crm\Provider\HubSpot::class,
			\Conduit\Crm\Provider\Mailchimp::class,
			\Conduit\Crm\Provider\Klaviyo::class,
			\Conduit\Crm\Provider\Kit::class,
			\Conduit\Crm\Provider\Brevo::class,
			\Conduit\Crm\Provider\FluentCrm::class,
			\Conduit\Crm\Provider\MailerLite::class,
			\Conduit\Crm\Provider\Zoho::class,
			\Conduit\Crm\Provider\Salesforce::class,
		);

		foreach ( $defaults as $class ) {
			$this->register( $class );
		}
	}

	/**
	 * @param class-string<Provider> $provider_class
	 */
	public function register( string $provider_class ): void {
		if ( ! class_exists( $provider_class ) || ! is_subclass_of( $provider_class, Provider::class ) ) {
			$this->logger->error(
				'Ignored a CRM provider that is missing or does not implement the contract.',
				array( 'class' => $provider_class )
			);

			return;
		}

		$instance = new $provider_class( $this->client );

		$this->providers[ $instance->get_slug() ] = $provider_class;
	}

	/**
	 * @return array<string, string> Slug => display name.
	 */
	public function available(): array {
		$list = array();

		foreach ( $this->providers as $slug => $class ) {
			$list[ $slug ] = ( new $class( $this->client ) )->get_name();
		}

		asort( $list );

		return $list;
	}

	public function make( string $slug ): Provider {
		if ( ! isset( $this->providers[ $slug ] ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Unknown CRM provider "%s".', $slug ) ) );
		}

		$class = $this->providers[ $slug ];

		return new $class( $this->client );
	}

	public function has( string $slug ): bool {
		return isset( $this->providers[ $slug ] );
	}

	/**
	 * The configured, connected provider, or null when the site has not
	 * finished setup or the credentials no longer work.
	 *
	 * Connection failure is remembered for the request so a broken credential
	 * does not produce one failed API call per integration hook.
	 */
	public function active(): ?Provider {
		if ( null !== $this->active ) {
			return $this->active;
		}

		if ( $this->connection_failed ) {
			return null;
		}

		$slug = $this->settings->provider_slug();

		if ( '' === $slug || ! $this->has( $slug ) ) {
			$this->connection_failed = true;

			return null;
		}

		try {
			$provider = $this->make( $slug );
			$provider->connect( $this->settings->credentials() );
		} catch ( ConnectionException $e ) {
			$this->connection_failed = true;

			$this->logger->error(
				'Could not connect to the CRM with the stored credentials.',
				array( 'error' => $e->getMessage() ),
				$slug
			);

			return null;
		}

		$this->active = $provider;

		return $this->active;
	}

	public function reset(): void {
		$this->active            = null;
		$this->connection_failed = false;
	}

	/**
	 * @param class-string $capability
	 */
	public function active_supports( string $capability ): bool {
		$provider = $this->active();

		return null !== $provider && $provider instanceof $capability;
	}
}
