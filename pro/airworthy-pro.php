<?php
/**
 * Plugin Name:       Airworthy Pro
 * Plugin URI:        https://airworthywp.com/
 * Description:       An upgrade plan that ticks itself off, every PHP version at once, and branded client reports. Needs the free Airworthy plugin.
 * Version:           0.5.0
 * Requires at least: 6.9
 * Requires PHP:      7.2
 * Requires Plugins:  airworthy
 * Author:            DigitalKind
 * Author URI:        https://digitalkind.ie/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       airworthy-pro
 * Domain Path:       /languages
 *
 * @package AirworthyPro
 */

defined( 'ABSPATH' ) || exit;

define( 'AIRWORTHY_PRO_VERSION', '0.5.0' );
define( 'AIRWORTHY_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRWORTHY_PRO_URL', plugin_dir_url( __FILE__ ) );

// Freemius (licences and updates): the "Airworthy Pro" product in the DigitalKind store (created
// 26 Nov 2026). The Product ID and the PUBLIC key (pk_...) only, never the secret key. If either
// is emptied, Pro runs ungated and loads no SDK code.
define( 'AIRWORTHY_PRO_FS_ID', '46502' );
define( 'AIRWORTHY_PRO_FS_KEY', 'pk_6c85095e4d83a637d3cab1fb13735' );

require_once AIRWORTHY_PRO_DIR . 'src/Licence.php';
require_once AIRWORTHY_PRO_DIR . 'src/Scans.php';
require_once AIRWORTHY_PRO_DIR . 'src/Branding.php';
require_once AIRWORTHY_PRO_DIR . 'src/Report.php';
require_once AIRWORTHY_PRO_DIR . 'src/Welcome.php';
require_once AIRWORTHY_PRO_DIR . 'src/Versions.php';
require_once AIRWORTHY_PRO_DIR . 'src/Plan.php';
require_once AIRWORTHY_PRO_DIR . 'src/Support.php';
require_once AIRWORTHY_PRO_DIR . 'src/History.php';
require_once AIRWORTHY_PRO_DIR . 'src/Alerts.php';
require_once AIRWORTHY_PRO_DIR . 'src/Monitor.php';
require_once AIRWORTHY_PRO_DIR . 'src/MonitorTab.php';
require_once AIRWORTHY_PRO_DIR . 'src/Pages.php';
require_once AIRWORTHY_PRO_DIR . 'src/Watch.php';
require_once AIRWORTHY_PRO_DIR . 'src/WatchTab.php';
require_once AIRWORTHY_PRO_DIR . 'src/ZipTab.php';

// While an after-the-switch watch runs, start recording as early as possible.
AirworthyPro\Watch::boot();

if ( ! function_exists( 'airworthy_pro_fs' ) ) {
	/**
	 * Pro's Freemius instance: licences and updates. Only the licence essentials are shared with
	 * Freemius (site address, plugin and SDK version); tracking of other plugins stays off unless
	 * the admin opts in on the activation screen.
	 *
	 * @return Freemius|null Null until the Product ID and public key are set.
	 */
	function airworthy_pro_fs() {
		global $airworthy_pro_fs;
		if ( ! AirworthyPro\Licence::configured() ) {
			return null;
		}
		if ( ! isset( $airworthy_pro_fs ) ) {
			require_once AIRWORTHY_PRO_DIR . 'vendor/freemius/start.php';
			$airworthy_pro_fs = fs_dynamic_init(
				array(
					'id'               => AIRWORTHY_PRO_FS_ID,
					'slug'             => 'airworthy-pro',
					'premium_slug'     => 'airworthy-pro',
					'type'             => 'plugin',
					'public_key'       => AIRWORTHY_PRO_FS_KEY,
					'is_premium'       => true,
					'is_premium_only'  => true,
					'has_addons'       => false,
					'has_paid_plans'   => true,
					'is_org_compliant' => false,
					'menu'             => array(
						'slug'       => 'airworthy',
						'first-path' => 'tools.php?page=airworthy&tab=pro',
						'parent'     => array( 'slug' => is_multisite() ? 'settings.php' : 'tools.php' ),
						'account'    => true,
						'contact'    => false,
						'support'    => false,
						'pricing'    => false,
						'addons'     => false,
					),
				)
			);
		}
		return $airworthy_pro_fs;
	}

	if ( airworthy_pro_fs() ) {
		// Diagnostic info (WordPress and PHP versions, site title) starts off: the admin can opt in.
		airworthy_pro_fs()->add_filter( 'permission_diagnostic_default', '__return_false' );
		// Freemius does not allow an uninstall.php: cleanup runs from its after_uninstall hook.
		airworthy_pro_fs()->add_action( 'after_uninstall', 'airworthy_pro_uninstall_cleanup' );
		do_action( 'airworthy_pro_fs_loaded' );
	}
}

/**
 * Removes Airworthy Pro's own data when the plugin is deleted (called by Freemius' after_uninstall).
 */
function airworthy_pro_uninstall_cleanup() {
	foreach ( array( 'airworthy_pro_history', 'airworthy_pro_monitor', 'airworthy_pro_monitor_state', 'airworthy_pro_watch', 'airworthy_pro_watch_errors', 'airworthy_pro_watch_on' ) as $option ) {
		delete_option( $option );
		delete_site_option( $option );
	}
	foreach ( array( 'airworthy_pro_monitor_tick', 'airworthy_pro_monitor_check', 'airworthy_pro_watch_tick', 'airworthy_pro_watch_round' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

register_activation_hook( __FILE__, array( 'AirworthyPro\\Welcome', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AirworthyPro\\Monitor', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'AirworthyPro\\Watch', 'deactivate' ) );

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
		AirworthyPro\Welcome::register(); // Always: it shows the licence.
		if ( ! AirworthyPro\Licence::can_use() ) {
			return; // Not licensed on this site yet: only the Pro tab, with the activation steps.
		}
		AirworthyPro\Branding::register();
		AirworthyPro\Report::register();
		AirworthyPro\Versions::register();
		AirworthyPro\Plan::register();
		AirworthyPro\Support::register();
		AirworthyPro\Monitor::register();
		AirworthyPro\MonitorTab::register();
		AirworthyPro\Watch::register();
		AirworthyPro\WatchTab::register();
		AirworthyPro\ZipTab::register();
	},
	20
);
