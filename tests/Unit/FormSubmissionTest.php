<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Integration\Forms\FluentForms;
use Switchyard\Integration\Forms\GravityForms;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;

final class FormSubmissionTest extends TestCase {

	/** @var list<array{0: string, 1: array<mixed>}> */
	private array $dispatched = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback = false ) => 'switchyard_integration_gravity-forms' === $name
				? array( 'forms' => array( '3' => array( 'enabled' => true, 'map' => array( '1' => 'first_name' ), 'tags' => array( '9' ), 'email_field' => '2' ) ) )
				: $fallback
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'do_action_ref_array' )->alias(
			function ( string $hook, array $args ): void {
				$this->dispatched[] = array( $hook, $args );
			}
		);
	}

	private function gravity(): GravityForms {
		$logger = $this->createMock( Logger::class );
		$world = new World( new FakeCrm(), $logger );

		return new GravityForms( $world->users, $world->tags, $world->queue, $world->features, $world->logger, $world->guests );
	}

	/** @return array<string, mixed> */
	private function form(): array {
		$name     = (object) array( 'id' => 1 );
		$email    = (object) array( 'id' => 2 );

		return array(
			'id'     => 3,
			'fields' => array( $name, $email ),
		);
	}

	public function test_a_normal_entry_is_queued_for_the_crm(): void {
		$this->gravity()->on_submission(
			array( '1' => 'Gus', '2' => 'gus@example.com', 'status' => 'active' ),
			$this->form()
		);

		$this->assertCount( 1, $this->dispatched );
		$this->assertSame( 'switchyard_push_guest', $this->dispatched[0][0] );
		$this->assertSame( 'gus@example.com', $this->dispatched[0][1][0] );
	}

	public function test_a_spam_entry_is_not_sent_to_the_crm(): void {
		$this->gravity()->on_submission(
			array( '1' => 'Bot', '2' => 'bot@example.com', 'status' => 'spam' ),
			$this->form()
		);

		$this->assertSame( array(), $this->dispatched );
	}

	public function test_a_fluent_forms_spam_entry_is_not_sent_to_the_crm(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): string {
				return 'spam';
			}
		};

		$world = new World( new FakeCrm(), $this->createMock( Logger::class ) );
		$forms = new FluentForms( $world->users, $world->tags, $world->queue, $world->features, $world->logger, $world->guests );

		$forms->on_submission( 8, array( 'email' => 'bot@example.com' ), (object) array( 'id' => 3 ) );

		unset( $GLOBALS['wpdb'] );

		$this->assertSame( array(), $this->dispatched );
	}
}
