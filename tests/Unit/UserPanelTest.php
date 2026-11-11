<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use RuntimeException;
use Switchyard\Admin\TagPicker;
use Switchyard\Admin\UserPanel;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;

final class UserPanelTest extends TestCase {

	protected function tearDown(): void {
		$_GET = array();

		parent::tearDown();
	}

	private function panel(): UserPanel {
		Functions\when( 'get_option' )->justReturn( array() );

		$world = new World( new FakeCrm(), $this->createMock( Logger::class ) );

		return new UserPanel( $world->users, $world->resolver, $world->tags, new TagPicker( $world->tags ), $world->registry );
	}

	public function test_a_user_who_cannot_edit_the_target_user_cannot_trigger_a_sync(): void {
		$_GET = array(
			'user_id' => '4',
			'do'      => 'push',
		);

		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->alias( static fn ( string $cap ): bool => 'edit_user' !== $cap );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'blocked' );
			}
		);

		$this->expectExceptionMessage( 'blocked' );

		$this->panel()->handle();
	}
}
