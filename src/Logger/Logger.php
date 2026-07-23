<?php

declare( strict_types=1 );

namespace Conduit\Logger;

use Conduit\Settings;

/**
 * Structured logging to a dedicated table.
 *
 * Every value passing through here is scrubbed of credentials first. Support
 * work depends on being able to hand a customer's log to a developer without
 * handing over their API key.
 */
class Logger {

	public const LEVELS = array(
		'debug'   => 10,
		'info'    => 20,
		'notice'  => 30,
		'warning' => 40,
		'error'   => 50,
	);

	private const REDACTED = '[redacted]';

	/**
	 * Matched case-insensitively against context keys and header names.
	 */
	private const SENSITIVE_KEYS = array(
		'authorization',
		'api_key',
		'apikey',
		'api-key',
		'access_token',
		'refresh_token',
		'client_secret',
		'password',
		'secret',
		'token',
		'private_key',
		'x-auth-token',
		'x-api-key',
	);

	public function __construct( private Settings $settings ) {}

	/** @param array<string, mixed> $context */
	public function debug( string $message, array $context = array(), string $source = '' ): void {
		$this->log( 'debug', $message, $context, $source );
	}

	/** @param array<string, mixed> $context */
	public function info( string $message, array $context = array(), string $source = '' ): void {
		$this->log( 'info', $message, $context, $source );
	}

	/** @param array<string, mixed> $context */
	public function notice( string $message, array $context = array(), string $source = '' ): void {
		$this->log( 'notice', $message, $context, $source );
	}

	/** @param array<string, mixed> $context */
	public function warning( string $message, array $context = array(), string $source = '' ): void {
		$this->log( 'warning', $message, $context, $source );
	}

	/** @param array<string, mixed> $context */
	public function error( string $message, array $context = array(), string $source = '' ): void {
		$this->log( 'error', $message, $context, $source );
	}

	/** @param array<string, mixed> $context */
	public function log( string $level, string $message, array $context = array(), string $source = '' ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		global $wpdb;

		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
		unset( $context['user_id'] );

		$context = self::redact( $context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			LogTable::name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => $level,
				'user_id'    => $user_id,
				'source'     => substr( $source, 0, 60 ),
				'message'    => $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private function should_log( string $level ): bool {
		$threshold = self::LEVELS[ $this->settings->string( 'log_level', 'error' ) ] ?? self::LEVELS['error'];
		$severity  = self::LEVELS[ $level ] ?? 0;

		return $severity >= $threshold;
	}

	/**
	 * Recursively replaces credential-shaped values.
	 *
	 * Keys are matched by substring because providers name their headers
	 * inconsistently. Bearer tokens embedded in a header string are stripped
	 * even when the key itself looks innocuous.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function redact( array $data ): array {
		$clean = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$clean[ $key ] = self::REDACTED;
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? self::scrub_string( $value ) : $value;
		}

		return $clean;
	}

	private static function is_sensitive_key( string $key ): bool {
		$needle = strtolower( $key );

		foreach ( self::SENSITIVE_KEYS as $sensitive ) {
			if ( str_contains( $needle, $sensitive ) ) {
				return true;
			}
		}

		return false;
	}

	private static function scrub_string( string $value ): string {
		$patterns = array(
			'/\b(Bearer|Token|Basic|Klaviyo-API-Key)\s+[A-Za-z0-9\-\._~\+\/=]{8,}/i' => '$1 ' . self::REDACTED,
			'/\b(pk_|sk_|xkeysib-|klav_)[A-Za-z0-9\-_]{8,}/i'                        => self::REDACTED,
		);

		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );
	}

	public function prune(): void {
		global $wpdb;

		$days  = max( 1, $this->settings->int( 'log_retention_days', 30 ) );
		$table = LogTable::name();

		// The table name is a trusted internal constant, and prepare() cannot
		// parameterise identifiers. The date is a real placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
