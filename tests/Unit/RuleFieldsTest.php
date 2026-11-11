<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Brain\Monkey\Functions;
use Switchyard\Access\Rules;
use Switchyard\Admin\RuleFields;
use Switchyard\Admin\TagPicker;
use Switchyard\Logger\Logger;
use Switchyard\Tests\Support\FakeCrm;
use Switchyard\Tests\Support\World;
use Switchyard\Tests\TestCase;

final class RuleFieldsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();

		$this->written = array();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'update_term_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->written[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	private function fields(): RuleFields {
		$world = new World( new FakeCrm(), $this->createMock( Logger::class ) );

		return new RuleFields( new Rules( $world->tags ), new TagPicker( $world->tags ) );
	}

	public function test_a_term_rule_is_saved_for_someone_who_can_edit_the_term(): void {
		$_POST = array(
			'switchyard_term_nonce' => 'n',
			'switchyard_term_tags'  => array( '10' ),
			'switchyard_term_match' => 'all',
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->fields()->save_term( 7 );

		$this->assertSame( array( '10' ), $this->written[ Rules::TERM_META_TAGS ] );
		$this->assertSame( 'all', $this->written[ Rules::TERM_META_MATCH ] );
	}

	public function test_a_term_rule_is_not_saved_without_the_capability(): void {
		$_POST = array(
			'switchyard_term_nonce' => 'n',
			'switchyard_term_tags'  => array( '10' ),
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->fields()->save_term( 7 );

		$this->assertSame( array(), $this->written );
	}

	public function test_a_term_rule_is_not_saved_with_a_bad_nonce(): void {
		$_POST = array(
			'switchyard_term_nonce' => 'n',
			'switchyard_term_tags'  => array( '10' ),
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->fields()->save_term( 7 );

		$this->assertSame( array(), $this->written );
	}
}
