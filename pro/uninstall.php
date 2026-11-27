<?php
/**
 * Removes Airworthy Pro's own data when the plugin is deleted.
 *
 * @package AirworthyPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'airworthy_pro_history', 'airworthy_pro_monitor', 'airworthy_pro_monitor_state', 'airworthy_pro_watch', 'airworthy_pro_watch_errors', 'airworthy_pro_watch_on' ) as $airworthy_pro_option ) {
	delete_option( $airworthy_pro_option );
	delete_site_option( $airworthy_pro_option );
}
wp_clear_scheduled_hook( 'airworthy_pro_monitor_tick' );
wp_clear_scheduled_hook( 'airworthy_pro_monitor_check' );
wp_clear_scheduled_hook( 'airworthy_pro_watch_tick' );
wp_clear_scheduled_hook( 'airworthy_pro_watch_round' );
