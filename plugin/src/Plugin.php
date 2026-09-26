<?php
/**
 * Plugin bootstrap.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up hooks. Nothing is registered for front-end requests: all work happens in the
 * admin screen, background jobs or WP-CLI.
 */
final class Plugin {

	/**
	 * Registers hooks for the contexts the plugin runs in.
	 */
	public static function boot() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
			( new Admin\Page() )->register();
		}
	}
}
