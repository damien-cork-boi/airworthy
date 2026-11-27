<?php
/**
 * Monitoring: scheduled re-scans, and alerts when something got worse.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Env;
use Airworthy\Scan\Queue;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Airworthy's own scan on a schedule (and after plugins or themes are updated), keeps the
 * results in History, and emails when something new would break. Nothing leaves the site except
 * the email WordPress itself sends.
 *
 * One daily WP-Cron tick decides what is due: a scan when the chosen interval has passed, and
 * the PHP support reminders. A scan finishing (the free plugin's airworthy_scan_finished action)
 * is what triggers the comparison; a follow-up check every two minutes covers a scan that
 * finishes while nobody visits the site, and an older free plugin without that action.
 */
final class Monitor {

	/** Site option: the settings. */
	const OPTION = 'airworthy_pro_monitor';

	/** Site option: what monitoring is doing (last run, the scan it waits for, what it already told you). */
	const STATE = 'airworthy_pro_monitor_state';

	/** WP-Cron: the daily tick. */
	const TICK = 'airworthy_pro_monitor_tick';

	/** WP-Cron: a one-off look at a running scan. */
	const CHECK = 'airworthy_pro_monitor_check';

	/** How long a plugin update counts as the reason for a re-scan, in seconds. */
	const UPDATE_WINDOW = 1200;

	/** How many two-minute looks at a running scan before giving up. */
	const MAX_CHECKS = 60;

	/** Days between scheduled scans. */
	const INTERVALS = array(
		'daily'   => 1,
		'weekly'  => 7,
		'monthly' => 30,
	);

	/** Days before the end of security support that the PHP reminders go out. */
	const SUPPORT_REMINDERS = array( 90, 30, 7 );

	/**
	 * Hooks monitoring.
	 */
	public static function register() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::CHECK, array( __CLASS__, 'check' ) );
		add_action( 'airworthy_scan_finished', array( __CLASS__, 'on_finished' ) );
		add_action( 'airworthy_scan_failed', array( __CLASS__, 'on_failed' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 30, 2 ); // After Airworthy's own re-check is queued.
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * The settings, with defaults.
	 *
	 * @return array{enabled:bool,frequency:string,target:string,recipients:string,alert_blockers:bool,alert_warnings:bool,alert_support:bool,alert_failed:bool}
	 */
	public static function get() {
		$saved = get_site_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	/**
	 * The defaults: off until the site owner turns it on.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => false,
			'frequency'      => 'weekly',
			'target'         => 'auto',
			'recipients'     => '',
			'alert_blockers' => true,
			'alert_warnings' => false,
			'alert_support'  => true,
			'alert_failed'   => true,
		);
	}

	/**
	 * Cleans the settings form.
	 *
	 * @param array $input Raw form values.
	 * @return array
	 */
	public static function clean( array $input ) {
		$out = self::defaults();

		$out['enabled']        = ! empty( $input['enabled'] );
		$out['alert_blockers'] = ! empty( $input['alert_blockers'] );
		$out['alert_warnings'] = ! empty( $input['alert_warnings'] );
		$out['alert_support']  = ! empty( $input['alert_support'] );
		$out['alert_failed']   = ! empty( $input['alert_failed'] );

		$frequency        = isset( $input['frequency'] ) ? (string) $input['frequency'] : 'weekly';
		$out['frequency'] = isset( self::INTERVALS[ $frequency ] ) ? $frequency : 'weekly';

		$target        = isset( $input['target'] ) ? (string) $input['target'] : 'auto';
		$out['target'] = ( 'auto' === $target || Targets::is_valid( $target ) ) ? $target : 'auto';

		$list = array();
		foreach ( preg_split( '/[\s,;]+/', isset( $input['recipients'] ) ? (string) $input['recipients'] : '' ) as $address ) {
			$address = strtolower( sanitize_email( $address ) );
			if ( $address && is_email( $address ) && ! in_array( $address, $list, true ) ) {
				$list[] = $address;
			}
		}
		$out['recipients'] = implode( ', ', array_slice( $list, 0, 5 ) );
		return $out;
	}

	/**
	 * Saves the settings and starts or stops the schedule.
	 *
	 * @param array $settings Cleaned settings.
	 */
	public static function save( array $settings ) {
		update_site_option( self::OPTION, $settings );
		self::sync_schedule();
	}

	/**
	 * Whether monitoring is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = self::get();
		return ! empty( $s['enabled'] );
	}

	/**
	 * The PHP version a monitoring scan checks against.
	 *
	 * @return string
	 */
	public static function target() {
		$s = self::get();
		if ( 'auto' !== $s['target'] && Targets::is_valid( $s['target'] ) ) {
			return $s['target'];
		}
		return Targets::default_for( Env::php_version() );
	}

	/**
	 * Seconds between scheduled scans.
	 *
	 * @return int
	 */
	public static function interval() {
		$s = self::get();
		return self::INTERVALS[ $s['frequency'] ] * DAY_IN_SECONDS;
	}

	/**
	 * What monitoring is doing.
	 *
	 * @return array{last_run:int,pending:?array,update_at:int,support:array,last_alert:int,last_error:string}
	 */
	public static function state() {
		$state = get_site_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return array_merge(
			array(
				'last_run'   => 0,
				'pending'    => null,
				'update_at'  => 0,
				'support'    => array(),
				'last_alert' => 0,
				'last_error' => '',
			),
			$state
		);
	}

	/**
	 * Saves part of the state.
	 *
	 * @param array $changes Keys to change.
	 */
	public static function set_state( array $changes ) {
		$state = array_merge( self::state(), $changes );
		if ( is_multisite() ) {
			update_site_option( self::STATE, $state );
		} else {
			update_option( self::STATE, $state, false );
		}
	}

	/**
	 * When the next scheduled scan is due (Unix time), or 0 when monitoring is off.
	 *
	 * @return int
	 */
	public static function next_run() {
		if ( ! self::enabled() ) {
			return 0;
		}
		$last = self::state()['last_run'];
		return $last ? $last + self::interval() : time();
	}

	/**
	 * Keeps the daily WP-Cron tick in step with the setting.
	 */
	public static function sync_schedule() {
		$next = wp_next_scheduled( self::TICK );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::TICK );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::TICK );
			wp_clear_scheduled_hook( self::CHECK );
		}
	}

	/**
	 * Removes the scheduled jobs (deactivation).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::CHECK );
	}

	/**
	 * The daily tick: a scan when one is due, and the PHP support reminders.
	 */
	public static function tick() {
		if ( ! self::enabled() || ! Licence::alerts_allowed() ) {
			return; // Off, or the licence has ended: scheduled checks and emails stop.
		}
		self::support_reminders();
		// An hour of slack so a daily schedule doesn't slip a day when the tick runs a little late.
		if ( time() >= self::state()['last_run'] + self::interval() - HOUR_IN_SECONDS ) {
			self::run( 'scheduled' );
		}
	}

	/**
	 * Starts a scan against the monitoring target.
	 *
	 * @param string $trigger scheduled | manual.
	 * @return int|\WP_Error Scan ID.
	 */
	public static function run( $trigger = 'scheduled' ) {
		$scan_id = Queue::start( self::target(), 0, null, null );
		if ( is_wp_error( $scan_id ) ) {
			self::set_state( array( 'last_error' => $scan_id->get_error_message() ) );
			return $scan_id;
		}
		self::set_state(
			array(
				'last_run'   => time(),
				'last_error' => '',
				'pending'    => array(
					'scan_id' => (int) $scan_id,
					'trigger' => 'scheduled' === $trigger ? 'scheduled' : 'manual',
					'started' => time(),
					'checks'  => 0,
				),
			)
		);
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CHECK );
		return (int) $scan_id;
	}

	/**
	 * A scan finished (the free plugin's action): compare it with the last snapshot.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function on_finished( $scan_id ) {
		self::process( (int) $scan_id );
	}

	/**
	 * A scan failed for good.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function on_failed( $scan_id ) {
		$pending = self::state()['pending'];
		if ( ! $pending || (int) $pending['scan_id'] !== (int) $scan_id ) {
			return;
		}
		self::set_state( array( 'pending' => null ) );
		$s = self::get();
		if ( self::enabled() && $s['alert_failed'] && 'scheduled' === $pending['trigger'] ) {
			Alerts::send_failed();
		}
	}

	/**
	 * Looks at a scan monitoring started: done, failed or still running.
	 */
	public static function check() {
		$pending = self::state()['pending'];
		if ( ! $pending ) {
			return;
		}
		global $wpdb;
		$t = \Airworthy\Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own table, read-only.
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $t['scans'], $pending['scan_id'] ) );
		if ( 'complete' === $status ) {
			self::process( (int) $pending['scan_id'] );
			return;
		}
		if ( in_array( $status, array( 'failed', 'cancelled', '' ), true ) ) {
			self::on_failed( (int) $pending['scan_id'] );
			return;
		}
		++$pending['checks'];
		if ( $pending['checks'] >= self::MAX_CHECKS ) {
			self::on_failed( (int) $pending['scan_id'] ); // Two hours and still not done.
			return;
		}
		self::set_state( array( 'pending' => $pending ) );
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CHECK );
	}

	/**
	 * Notes that a plugin or theme was just updated, so the re-check that follows counts as
	 * "after an update".
	 *
	 * @param \WP_Upgrader $upgrader Upgrader (unused).
	 * @param array        $extra    Hook data.
	 */
	public static function after_upgrade( $upgrader, $extra ) {
		unset( $upgrader );
		if ( ! self::enabled() || empty( $extra['type'] ) || empty( $extra['action'] ) ) {
			return;
		}
		if ( in_array( $extra['type'], array( 'plugin', 'theme' ), true ) && in_array( $extra['action'], array( 'update', 'install' ), true ) ) {
			self::set_state( array( 'update_at' => time() ) );
		}
	}

	/**
	 * Saves the finished scan in the history and, when something new would break, emails.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function process( $scan_id ) {
		$state   = self::state();
		$pending = $state['pending'];
		$trigger = 'manual';
		if ( $pending && (int) $pending['scan_id'] === $scan_id ) {
			$trigger = $pending['trigger'];
			self::set_state( array( 'pending' => null ) );
		} elseif ( $state['update_at'] && time() - $state['update_at'] <= self::UPDATE_WINDOW ) {
			$trigger = 'update';
		}
		$record = History::record( $scan_id, $trigger );
		if ( ! $record || ! self::enabled() || 'manual' === $trigger || ! Licence::alerts_allowed() ) {
			return; // Someone ran it by hand: they're looking at the screen, no email.
		}
		$s    = self::get();
		$diff = $record['diff'];
		$send = ( $s['alert_blockers'] && $diff['blockers'] );
		if ( ! $send && $s['alert_warnings'] ) {
			foreach ( array_merge( $diff['worse'], $diff['added'] ) as $row ) {
				if ( in_array( $row['to'], array( 'warnings', 'unknown' ), true ) ) {
					$send = true;
					break;
				}
			}
		}
		if ( $send && Alerts::send_changes( $record, $trigger ) ) {
			self::set_state( array( 'last_alert' => time() ) );
		}
	}

	/**
	 * Emails once when the server's PHP version is close to the end of security support, or is
	 * missing the latest PHP security release.
	 */
	public static function support_reminders() {
		$s = self::get();
		if ( ! $s['alert_support'] ) {
			return;
		}
		$version = Env::php_version();
		$state   = self::state();
		$told    = is_array( $state['support'] ) ? $state['support'] : array();

		$until = Targets::security_until( $version );
		if ( $until ) {
			$days = (int) floor( ( strtotime( $until . ' 12:00:00 UTC' ) - time() ) / DAY_IN_SECONDS );
			// The nearest threshold not yet told (so a site turned on at 20 days gets the 30-day mail, not three).
			$due = null;
			foreach ( self::SUPPORT_REMINDERS as $threshold ) {
				if ( $days <= $threshold ) {
					$due = $threshold;
				}
			}
			$key = Targets::minor( $version ) . ':' . $due;
			if ( null !== $due && empty( $told[ $key ] ) ) {
				if ( Alerts::send_support_ending( $version, $days, $until ) ) {
					$told[ $key ] = time();
				}
			}
		}

		$missing = Targets::missing_patch( $version );
		if ( $missing ) {
			$key = 'patch:' . Targets::SECURITY_RELEASE['date'];
			if ( empty( $told[ $key ] ) && Alerts::send_patch_missing( $version, $missing ) ) {
				$told[ $key ] = time();
			}
		}
		self::set_state( array( 'support' => $told ) );
	}
}
