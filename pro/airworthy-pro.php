<?php
/**
 * Plugin Name:       Airworthy Pro
 * Plugin URI:        https://airworthywp.com/
 * Description:       Branded client reports for Airworthy scans: your logo, your name, your colour, ready to save as PDF. Needs the free Airworthy plugin.
 * Version:           0.1.1
 * Requires at least: 6.5
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

define( 'AIRWORTHY_PRO_VERSION', '0.1.1' );
define( 'AIRWORTHY_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRWORTHY_PRO_URL', plugin_dir_url( __FILE__ ) );

require_once AIRWORTHY_PRO_DIR . 'src/Branding.php';
require_once AIRWORTHY_PRO_DIR . 'src/Report.php';

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
	},
	20
);
