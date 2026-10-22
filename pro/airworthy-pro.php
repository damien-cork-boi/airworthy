<?php
/**
 * Plugin Name:       Airworthy Pro
 * Plugin URI:        https://airworthywp.com/
 * Description:       An upgrade plan that ticks itself off, every PHP version at once, and branded client reports. Needs the free Airworthy plugin.
 * Version:           0.2.3
 * Requires at least: 6.9
 * Requires PHP:      7.2
 * Requires Plugins:  airworthy
 * Author:            DigitalKind
 * Author URI:        https://digitalkind.ie/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       airworthy-pro
 *
 * @package AirworthyPro
 */

defined( 'ABSPATH' ) || exit;

define( 'AIRWORTHY_PRO_VERSION', '0.2.3' );
define( 'AIRWORTHY_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRWORTHY_PRO_URL', plugin_dir_url( __FILE__ ) );

require_once AIRWORTHY_PRO_DIR . 'src/Scans.php';
require_once AIRWORTHY_PRO_DIR . 'src/Branding.php';
require_once AIRWORTHY_PRO_DIR . 'src/Report.php';
require_once AIRWORTHY_PRO_DIR . 'src/Welcome.php';
require_once AIRWORTHY_PRO_DIR . 'src/Versions.php';
require_once AIRWORTHY_PRO_DIR . 'src/Plan.php';
require_once AIRWORTHY_PRO_DIR . 'src/Support.php';

register_activation_hook( __FILE__, array( 'AirworthyPro\\Welcome', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'AIRWORTHY_VERSION' ) ) {
			add_action(
				is_multisite() ? 'network_admin_notices' : 'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Airworthy Pro needs the free Airworthy plugin. Install and activate it to use client reports.', 'airworthy-pro' ) . '</p></div>';
					}
				}
			);
			return;
		}
		AirworthyPro\Branding::register();
		AirworthyPro\Report::register();
		AirworthyPro\Welcome::register();
		AirworthyPro\Versions::register();
		AirworthyPro\Plan::register();
		AirworthyPro\Support::register();
	},
	20
);
