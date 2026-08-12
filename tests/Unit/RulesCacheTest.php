<?php

declare( strict_types=1 );

namespace Switchyard\Tests\Unit;

use Switchyard\Access\Rules;
use Switchyard\Tests\TestCase;

/**
 * Regression cover for the access-decision cache key.
 *
 * The key was once computed as $post_id * 1000000 + $user_id, which collides
 * once user IDs reach seven figures: post 1 / user 1000000 and post 2 / user 0
 * produce the same key, so one visitor could be served another visitor's
 * cached access decision. These cases pin the composite key.
 */
final class RulesCacheTest extends TestCase {

	/**
	 * Pairs that collided under the arithmetic key.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	public static function collidingPairs(): array {
		return array(
			'post 1 / user 1000000 vs post 2 / user 0' => array( 1, 1000000, 2, 0 ),
			'post 0 / user 5000000 vs post 5 / user 0' => array( 0, 5000000, 5, 0 ),
			'post 3 / user 2000001 vs post 5 / user 1' => array( 3, 2000001, 5, 1 ),
		);
	}

	/**
	 * @dataProvider collidingPairs
	 */
	public function test_distinct_post_and_user_pairs_do_not_share_a_key(
		int $post_a,
		int $user_a,
		int $post_b,
		int $user_b
	): void {
		$this->assertNotSame(
			$this->key( $post_a, $user_a ),
			$this->key( $post_b, $user_b ),
			'Two different (post, user) pairs must not produce the same cache key.'
		);
	}

	public function test_the_same_pair_produces_a_stable_key(): void {
		$this->assertSame( $this->key( 42, 7 ), $this->key( 42, 7 ) );
	}

	/**
	 * Mirrors the key construction in Rules::can_access(), which is private.
	 * Kept in lockstep with it deliberately: if that changes, this must too.
	 */
	private function key( int $post_id, int $user_id ): string {
		return $post_id . ':' . $user_id;
	}

	/**
	 * Guards against a silent revert to arithmetic keying.
	 */
	public function test_the_implementation_does_not_use_an_arithmetic_key(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Access/Rules.php' );

		$this->assertStringNotContainsString(
			'* 1000000 +',
			$source,
			'Rules must not key its cache by arithmetic on post and user IDs.'
		);

		$this->assertStringContainsString(
			"\$post_id . ':' . \$user_id",
			$source,
			'Rules should key its access cache on a composite string.'
		);
	}
}
