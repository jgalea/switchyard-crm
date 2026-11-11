<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Access\BlockVisibility;
use Switchyard\Admin\TagPicker;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;

final class BlockVisibilityTest extends TestCase {

	/** @var array<string, mixed> */
	private array $user_meta = array();

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->user_meta = array( 'switchyard_tags' => array( '10' ) );
		$this->user      = 0;

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_current_user_id' )->alias( fn (): int => $this->user );
		Functions\when( 'get_user_meta' )->alias( fn ( int $id, string $key ) => $this->user_meta[ $key ] ?? '' );
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function render( array $attrs ): string {
		$world  = new World( new FakeCrm(), $this->createMock( Logger::class ) );
		$filter = new BlockVisibility( $world->tags, new TagPicker( $world->tags ) );

		return $filter->filter_block( '<p>secret</p>', array( 'attrs' => $attrs ) );
	}

	public function test_a_block_without_a_rule_always_renders(): void {
		$this->assertSame( '<p>secret</p>', $this->render( array() ) );
	}

	public function test_logged_in_blocks_are_hidden_from_visitors(): void {
		$this->assertSame( '', $this->render( array( 'switchyardAudience' => 'logged_in' ) ) );

		$this->user = 4;

		$this->assertSame( '<p>secret</p>', $this->render( array( 'switchyardAudience' => 'logged_in' ) ) );
	}

	public function test_logged_out_blocks_are_hidden_from_users(): void {
		$this->assertSame( '<p>secret</p>', $this->render( array( 'switchyardAudience' => 'logged_out' ) ) );

		$this->user = 4;

		$this->assertSame( '', $this->render( array( 'switchyardAudience' => 'logged_out' ) ) );
	}

	public function test_tag_blocks_need_the_tag(): void {
		$this->user = 4;

		$this->assertSame( '<p>secret</p>', $this->render( array( 'switchyardAudience' => 'tags', 'switchyardTags' => array( '10' ) ) ) );
		$this->assertSame( '', $this->render( array( 'switchyardAudience' => 'tags', 'switchyardTags' => array( '11' ) ) ) );
		$this->assertSame( '', $this->render( array( 'switchyardAudience' => 'tags', 'switchyardTags' => array( '10', '11' ), 'switchyardMatch' => 'all' ) ) );
	}

	public function test_tag_blocks_are_hidden_from_visitors(): void {
		$this->assertSame( '', $this->render( array( 'switchyardAudience' => 'tags', 'switchyardTags' => array( '10' ) ) ) );
	}
}
