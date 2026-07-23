<?php

declare( strict_types=1 );

namespace Conduit\Tests\Unit;

use Conduit\Logger\Logger;
use Conduit\Tests\TestCase;

/**
 * Redaction is the one thing in the logger that must never regress: support
 * work involves handing these logs to other people.
 */
final class LoggerTest extends TestCase {

	public function test_it_redacts_credential_shaped_keys(): void {
		$clean = Logger::redact(
			array(
				'api_key'       => 'abc123def456',
				'Authorization' => 'Bearer ' . 'sk_' . 'live_' . '0123456789',
				'access_token'  => 'xyz',
				'email'         => 'person@example.com',
			)
		);

		$this->assertSame( '[redacted]', $clean['api_key'] );
		$this->assertSame( '[redacted]', $clean['Authorization'] );
		$this->assertSame( '[redacted]', $clean['access_token'] );
		$this->assertSame( 'person@example.com', $clean['email'], 'Ordinary values must survive.' );
	}

	public function test_it_matches_keys_case_insensitively_and_by_substring(): void {
		$clean = Logger::redact(
			array(
				'X-Api-Key'          => 'secret',
				'client_secret'      => 'secret',
				'stripe_private_key' => 'secret',
				'CONTACT_TOKEN'      => 'secret',
			)
		);

		foreach ( $clean as $value ) {
			$this->assertSame( '[redacted]', $value );
		}
	}

	public function test_it_redacts_nested_structures(): void {
		$clean = Logger::redact(
			array(
				'request' => array(
					'headers' => array( 'api-key' => 'leak-me' ),
					'body'    => array( 'email' => 'person@example.com' ),
				),
			)
		);

		$this->assertSame( '[redacted]', $clean['request']['headers']['api-key'] );
		$this->assertSame( 'person@example.com', $clean['request']['body']['email'] );
	}

	/**
	 * A token can arrive inside a value whose key looks harmless, which is
	 * how header dumps leak.
	 */
	public function test_it_scrubs_tokens_embedded_in_values(): void {
		// Assembled at runtime so this fabricated sample does not sit in the
		// source as a literal that credential scanners will flag.
		$fake_key = 'sk_' . 'live_' . '9988776655443322';

		$clean = Logger::redact(
			array(
				'note'    => 'called with Bearer abcdef0123456789 and it worked',
				'snippet' => 'key=' . $fake_key,
			)
		);

		$this->assertStringNotContainsString( 'abcdef0123456789', $clean['note'] );
		$this->assertStringContainsString( '[redacted]', $clean['note'] );
		$this->assertStringNotContainsString( '9988776655443322', $clean['snippet'] );
	}

	public function test_it_leaves_ordinary_prose_alone(): void {
		$clean = Logger::redact( array( 'message' => 'Synced 4 users to the CRM.' ) );

		$this->assertSame( 'Synced 4 users to the CRM.', $clean['message'] );
	}
}
