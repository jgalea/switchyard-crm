<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Support;

use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use Switchyard\Crm\Contracts\Provider;
use Switchyard\Crm\Http\Client;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Features;
use Switchyard\Logger\Logger;
use Switchyard\Settings;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\FieldMap;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

final class World {

	public Settings $settings;
	public ProviderRegistry $registry;
	public ContactResolver $resolver;
	public FieldMap $fields;
	public TagEngine $tags;
	public Queue $queue;
	public UserSync $users;
	public GuestSync $guests;
	public Features $features;

	/** @var Logger&MockObject */
	public $logger;

	/** @param Logger&MockObject $logger */
	public function __construct( Provider $provider, $logger ) {
		$this->logger   = $logger;
		$this->settings = new Settings();
		$this->registry = new ProviderRegistry( new Client( $logger ), $this->settings, $logger );
		$active         = new ReflectionProperty( $this->registry, 'active' );
		$active->setAccessible( true );
		$active->setValue( $this->registry, $provider );

		$this->resolver = new ContactResolver( $this->registry, $logger );
		$this->fields   = new FieldMap();
		$this->tags     = new TagEngine( $this->registry, $this->resolver, $this->settings, $logger );
		$this->queue    = new Queue( $this->settings, $logger );
		$this->users    = new UserSync( $this->registry, $this->resolver, $this->fields, $this->tags, $this->queue, $this->settings, $logger );
		$this->guests   = new GuestSync( $this->registry, $this->resolver, $this->fields, $this->settings, $logger );
		$this->features = new Features();
	}
}
