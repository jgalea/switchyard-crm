<?php
/**
 * Plugin Name:       Conduit CRM
 * Plugin URI:        https://github.com/jgalea/conduit-crm
 * Description:       Connect WordPress to your CRM. Sync users, purchases and form entries, and gate content with CRM tags.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jean Galea
 * Author URI:        https://github.com/jgalea
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       conduit-crm
 * Domain Path:       /languages
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const CONDUIT_VERSION = '1.0.0';

define( 'CONDUIT_FILE', __FILE__ );
define( 'CONDUIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONDUIT_URL', plugin_dir_url( __FILE__ ) );

$conduit_autoloader = CONDUIT_DIR . 'vendor/autoload.php';

if ( ! is_readable( $conduit_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Conduit CRM is missing its dependencies. Run composer install in the plugin directory.', 'conduit-crm' )
			);
		}
	);

	return;
}

require_once $conduit_autoloader;

register_activation_hook( __FILE__, array( Conduit\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Conduit\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Conduit\Plugin::instance()->boot();
	},
	5
);
