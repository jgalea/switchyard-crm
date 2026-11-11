<?php

declare( strict_types=1 );

// api.php guards on ABSPATH, and the tests exercise it, so stand in for
// WordPress here.
define( 'ABSPATH', __DIR__ . '/' );

define( 'SWITCHYARD_TESTING', true );
define( 'SWITCHYARD_VERSION', '1.0.0' );
define( 'SWITCHYARD_DIR', dirname( __DIR__ ) . '/' );
define( 'SWITCHYARD_URL', 'https://example.test/wp-content/plugins/switchyard-crm/' );
define( 'SWITCHYARD_FILE', dirname( __DIR__ ) . '/switchyard-crm.php' );

define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );

// On PHP 7.4 these come from wp-includes/compat.php, which the plugin gets for
// free because it requires WordPress 6.4. Copied verbatim from core so the
// tests see the same semantics, including the empty-needle cases.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return false !== strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return 0 === strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack ) {
			return '' === $needle;
		}

		$len = strlen( $needle );

		return substr( $haystack, -$len, $len ) === $needle;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The plugin bootstrap normally does this; api.php is not in the composer
// files autoload because it guards on ABSPATH.
require_once dirname( __DIR__ ) . '/src/api.php';

if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Post {

		public int $ID = 0;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Query {

		/** @var array<string, mixed> */
		public array $query_vars = array();

		public bool $singular = false;

		public function is_singular(): bool {
			return $this->singular;
		}

		/** @return mixed */
		public function get( string $key, $fallback = '' ) {
			return $this->query_vars[ $key ] ?? $fallback;
		}

		/** @param mixed $value */
		public function set( string $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_User {

		public int $ID = 0;
		public string $user_email = '';
		public string $user_login = '';
		public string $display_name = '';
		public string $first_name = '';
		public string $last_name = '';

		/** @var list<string> */
		public array $roles = array();
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Error {

		public string $code;
		public string $message;

		/** @var mixed */
		public $data;

		/** @param mixed $data */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_REST_Server {

		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_REST_Request {

		/** @var array<string, mixed> */
		public array $params = array();

		/** @var array<string, string> */
		public array $headers = array();

		/** @return mixed */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_REST_Response {

		/** @var mixed */
		private $data;
		private int $status;

		/** @param mixed $data */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Comment_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Comment_Query {

		/** @var array<string, mixed> */
		public array $query_vars = array();
	}
}
