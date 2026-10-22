<?php
/**
 * Support info: a plain text file with what we need to help, built on the site and downloaded
 * by the user. Nothing is sent anywhere.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the support info file (versions, server limits, settings, plugins, recent scans,
 * files that couldn't be checked, failed background jobs) for the user to preview or download
 * and attach to a support email.
 *
 * What it never contains: the site's address, usernames, email or IP addresses, any posts,
 * orders, donations, members or other customer data, passwords, keys or wp-config contents, or
 * the contents of any file. Absolute paths are cut back to the site's folder, and every free
 * text field (scan errors, job logs) is scrubbed of paths, addresses and the site's domain.
 */
final class Support {

	/** The admin-post action that serves the file. */
	const ACTION = 'airworthy_pro_support_info';

	/** How many recent scans to include. */
	const SCANS = 5;

	/** How many failed background jobs to include. */
	const JOBS = 10;

	/**
	 * Hooks the download handler.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'serve' ) );
	}

	/**
	 * The file's URL.
	 *
	 * @param bool $download True to download it, false to show it in the browser.
	 * @return string
	 */
	public static function url( $download ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'mode'     => $download ? 'download' : 'view',
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Sends the file, to view or to download.
	 */
	public static function serve() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$download = isset( $_GET['mode'] ) && 'download' === sanitize_key( wp_unslash( $_GET['mode'] ) );
		$text     = self::build();

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="airworthy-support-' . gmdate( 'Y-m-d-Hi' ) . '.txt"' );
		}
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/plain file, not HTML; every value was scrubbed in build().
		exit;
	}

	/**
	 * The whole file.
	 *
	 * @return string
	 */
	public static function build() {
		$lines = array_merge(
			self::intro(),
			self::versions(),
			self::settings(),
			self::theme_and_plugins(),
			self::scans(),
			self::jobs()
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Header and the promise about what isn't in it.
	 *
	 * @return string[]
	 */
	private static function intro() {
		return array(
			'Airworthy support info',
			'Made ' . gmdate( 'Y-m-d H:i' ) . ' UTC by Airworthy Pro ' . AIRWORTHY_PRO_VERSION . ', on the site itself. Nothing was sent anywhere.',
			'',
			'Contains: software versions, server limits, Airworthy\'s settings, plugin and theme names and versions, recent scan results and failed background jobs.',
			'Never contains: the site\'s address, usernames, email or IP addresses, posts, orders, donations, members or any other customer data, passwords, keys or wp-config.php contents, or the contents of any file. File paths start from the site\'s folder.',
		);
	}

	/**
	 * WordPress, PHP, database and server.
	 *
	 * @return string[]
	 */
	private static function versions() {
		global $wp_version, $wpdb;
		$php    = \Airworthy\Env::php_version();
		$build  = \Airworthy\Env::php_build();
		$patch  = \Airworthy\Targets::patch_status( $php );
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$server = strtok( $server, ' /' ); // The server name only, without its version.
		$info   = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$db     = ( false !== stripos( $info, 'mariadb' ) ? 'MariaDB ' : 'MySQL ' ) . $wpdb->db_version();
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			$db = 'SQLite';
		}
		$php_line = $php;
		if ( ! empty( $build['date'] ) ) {
			$php_line .= ', built ' . $build['date'];
		}
		if ( ! empty( $build['vendor'] ) ) {
			$php_line .= ', ' . \Airworthy\Env::vendor_name( $build['vendor'] );
		}
		return array(
			'',
			'== Versions ==',
			'Airworthy: ' . ( defined( 'AIRWORTHY_VERSION' ) ? AIRWORTHY_VERSION : 'not loaded' ),
			'Airworthy Pro: ' . AIRWORTHY_PRO_VERSION,
			'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' ) . ', language ' . get_locale(),
			'PHP: ' . $php_line . ' (' . PHP_SAPI . ')',
			'PHP security fixes: ' . ( $patch ? $patch['level'] : 'nothing known' ),
			'Memory limit: ' . ini_get( 'memory_limit' ) . ' (WordPress admin: ' . ( defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '?' ) . ')',
			'Max execution time: ' . ini_get( 'max_execution_time' ) . ' s',
			'Database: ' . $db,
			'Web server: ' . ( '' !== (string) $server ? $server : 'unknown' ),
			'Persistent object cache: ' . ( wp_using_ext_object_cache() ? 'yes' : 'no' ),
			'WP-Cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (a server cron runs it, or nothing does)' : 'on' ),
			'WP_DEBUG: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off' ),
		);
	}

	/**
	 * Airworthy's settings.
	 *
	 * @return string[]
	 */
	private static function settings() {
		$s       = \Airworthy\Settings::all();
		$consent = (string) get_site_option( 'airworthy_wporg_consent', '' );
		return array(
			'',
			'== Airworthy settings ==',
			'Scan speed: ' . $s['speed'],
			'Skip inactive plugins: ' . ( $s['skip_inactive'] ? 'yes' : 'no' ),
			'Never check: ' . ( $s['ignore'] ? implode( ', ', $s['ignore'] ) : 'nothing' ),
			'WordPress.org lookups: ' . ( 'yes' === $consent ? 'allowed' : ( 'no' === $consent ? 'not allowed' : 'not asked yet' ) ),
		);
	}

	/**
	 * The theme and every plugin, with versions.
	 *
	 * @return string[]
	 */
	private static function theme_and_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme = wp_get_theme();
		$out   = array( '', '== Theme ==', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
		if ( $theme->parent() ) {
			$out[] = 'Child of ' . $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' );
		}

		$plugins = get_plugins();
		$out[]   = '';
		$out[]   = '== Plugins (' . count( $plugins ) . ') ==';
		foreach ( $plugins as $file => $data ) {
			$state = is_plugin_active_for_network( $file ) ? 'network active' : ( is_plugin_active( $file ) ? 'active' : 'inactive' );
			$slug  = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$out[] = '- ' . $data['Name'] . ' ' . $data['Version'] . ' (' . $slug . '), ' . $state;
		}
		$mu = get_mu_plugins();
		if ( $mu ) {
			$out[] = 'Must-use: ' . implode( ', ', wp_list_pluck( $mu, 'Name' ) );
		}
		$dropins = get_dropins();
		if ( $dropins ) {
			$out[] = 'Drop-ins: ' . implode( ', ', array_keys( $dropins ) );
		}
		return $out;
	}

	/**
	 * The latest scans, and the files the latest one couldn't check.
	 *
	 * @return string[]
	 */
	private static function scans() {
		global $wpdb;
		$t = \Airworthy\Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own table, read once for this file.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, target_php, from_php, host_php, status, components_total, files_total, files_done, count_blocker, count_unknown, count_suppressed, count_guarded, count_warnings, count_ready, wporg_status, attempts, error, created_at, started_at, finished_at FROM %i ORDER BY id DESC LIMIT %d', $t['scans'], self::SCANS ) );
		$out  = array( '', '== Latest scans ==', 'Times are UTC. Rescanning one plugin later moves a scan\'s "finished" time.' );
		if ( ! $rows ) {
			$out[] = 'None yet.';
			return $out;
		}
		foreach ( $rows as $r ) {
			$out[] = sprintf(
				'#%d PHP %s from %s (server %s): %s; created %s, started %s, finished %s; components %d, files %d of %d; blocker %d, unknown %d, suppressed %d, guarded %d, warnings %d, ready %d; WordPress.org %s; attempts %d',
				$r->id,
				$r->target_php,
				'' !== $r->from_php ? $r->from_php : '?',
				'' !== $r->host_php ? $r->host_php : '?',
				$r->status,
				$r->created_at,
				$r->started_at ? $r->started_at : '-',
				$r->finished_at ? $r->finished_at : '-',
				$r->components_total,
				$r->files_done,
				$r->files_total,
				$r->count_blocker,
				$r->count_unknown,
				$r->count_suppressed,
				$r->count_guarded,
				$r->count_warnings,
				$r->count_ready,
				'' !== $r->wporg_status ? $r->wporg_status : 'off',
				$r->attempts
			);
			if ( ! empty( $r->error ) ) {
				$out[] = '  Error: ' . self::scrub( (string) $r->error );
			}
		}

		$latest = (int) $rows[0]->id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read once for this file.
		$files = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.name, i.file, i.rule, i.message FROM %i i JOIN %i c ON c.id = i.component_id WHERE i.scan_id = %d AND i.severity = 'scan' ORDER BY c.name, i.file LIMIT 50", $t['issues'], $t['components'], $latest ) );
		$out[] = '';
		$out[] = '== Files the latest scan couldn\'t check ==';
		if ( ! $files ) {
			$out[] = 'None.';
		}
		foreach ( $files as $f ) {
			$out[] = '- ' . $f->name . ': ' . self::scrub( (string) $f->file ) . ' (' . str_replace( 'Airworthy.Scan.', '', (string) $f->rule ) . ') ' . self::scrub( (string) $f->message );
		}
		return $out;
	}

	/**
	 * Airworthy's background jobs: how many are waiting, and the latest failures with their log.
	 *
	 * @return string[]
	 */
	private static function jobs() {
		$out = array( '', '== Background jobs (Action Scheduler, group "' . \Airworthy\Installer::JOB_GROUP . '") ==' );
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			$out[] = 'Action Scheduler isn\'t loaded.';
			return $out;
		}
		$group   = \Airworthy\Installer::JOB_GROUP;
		$pending = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			),
			'ids'
		);
		$failed  = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_FAILED,
				'per_page' => self::JOBS,
				'orderby'  => 'date',
				'order'    => 'DESC',
			),
			'ids'
		);
		$out[]   = 'Waiting: ' . count( $pending ) . ( count( $pending ) >= 100 ? '+' : '' );
		$out[]   = 'Latest failures: ' . ( $failed ? count( $failed ) : 'none' );
		$store   = \ActionScheduler::store();
		$logger  = \ActionScheduler::logger();
		foreach ( $failed as $id ) {
			$action = $store->fetch_action( $id );
			$out[]  = '- #' . (int) $id . ' ' . $action->get_hook() . ' ' . wp_json_encode( $action->get_args() );
			foreach ( array_slice( $logger->get_logs( $id ), -3 ) as $log ) {
				$date  = $log->get_date();
				$out[] = '  ' . ( $date ? $date->format( 'Y-m-d H:i' ) . ' ' : '' ) . self::scrub( (string) $log->get_message() );
			}
		}
		return $out;
	}

	/**
	 * A free-text value without paths outside the site, the site's domain, email or IP addresses.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function scrub( $text ) {
		$text  = (string) $text;
		$roots = array(
			WP_CONTENT_DIR               => 'wp-content',
			untrailingslashit( ABSPATH ) => '[site]',
		);
		foreach ( $roots as $path => $label ) {
			// Both spellings: as PHP reports it, and with forward slashes (Windows hosts).
			$text = str_replace( array( $path, wp_normalize_path( $path ) ), $label, $text );
		}
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$text = preg_replace( '#(?:[a-z]+://)?' . preg_quote( $host, '#' ) . '(?::\d+)?#i', '[site address]', $text );
			}
		}
		$text = preg_replace( '#/(home|Users|var/www|srv)/[^/\s]+#', '/$1/[user]', $text );
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
		// IP addresses: candidates first, then only real ones (so times like 20:58:41 stay).
		$text = preg_replace_callback(
			'/(?<![\w.:])(?:\d{1,3}(?:\.\d{1,3}){3}|[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7})(?![\w.:])/i',
			static function ( $m ) {
				return false !== filter_var( $m[0], FILTER_VALIDATE_IP ) ? '[ip]' : $m[0];
			},
			$text
		);
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}
}
