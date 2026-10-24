<?php
/**
 * Plugin Name:       Switchyard CRM Sync and Access
 * Plugin URI:        https://github.com/jgalea/switchyard-crm
 * Description:       Connect WordPress to your CRM. Sync users, purchases and form entries, and gate content with CRM tags.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Jean Galea
 * Author URI:        https://jeangalea.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       switchyard-crm
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const SWITCHYARD_VERSION = '1.1.0';

define( 'SWITCHYARD_FILE', __FILE__ );
define( 'SWITCHYARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWITCHYARD_URL', plugin_dir_url( __FILE__ ) );

$switchyard_autoloader = SWITCHYARD_DIR . 'vendor/autoload.php';

if ( ! is_readable( $switchyard_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Switchyard CRM is missing its dependencies. Run composer install in the plugin directory.', 'switchyard-crm' )
			);
		}
	);

	return;
}

require_once $switchyard_autoloader;

// Loaded here rather than through composer's files autoload: it guards on
// ABSPATH, so it must only ever be included from inside WordPress.
require_once SWITCHYARD_DIR . 'src/api.php';

register_activation_hook( __FILE__, array( Switchyard\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Switchyard\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Switchyard\Plugin::instance()->boot();
	},
	5
);
