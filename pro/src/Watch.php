<?php
/**
 * The after-the-switch watch: PHP errors and page checks around a PHP version change.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Env;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A scan says what might break. This says what did. The owner starts a watch before asking the
 * host to switch PHP; Airworthy notes how the site's key pages answer, records the PHP errors the
 * site raises (each tied to the plugin or theme whose file raised it), notices the switch by
 * itself, and for 24 to 72 hours afterwards compares: what is new on the new PHP, what was
 * already there, and whether a page stopped answering.
 *
 * It records only while a watch runs. Nothing leaves the site. Error messages are stored with the
 * server's folder names removed and any query string cut off.
 */
final class Watch {

	/** Option (not autoloaded): the watch's state. */
	const STATE = 'airworthy_pro_watch';

	/** Option (not autoloaded): the recorded errors. */
	const ERRORS = 'airworthy_pro_watch_errors';

	/** Option (autoloaded, tiny): whether to record, and on which PHP the watch started. */
	const FLAG = 'airworthy_pro_watch_on';

	/** Hourly cron hook while a watch runs. */
	const TICK = 'airworthy_pro_watch_tick';

	/** One-off cron hook: take a page check. */
	const ROUND = 'airworthy_pro_watch_round';

	/** Most distinct errors kept. Beyond this, only a count of the rest. */
	const MAX_ERRORS = 200;

	/** Seconds between writes of the error table when nothing new turned up. */
	const FLUSH_EVERY = 30;

	/** Days to wait for a switch before giving up. */
	const GIVE_UP_DAYS = 14;

	/** Hours to watch after the switch. */
	const WINDOWS = array( 24, 48, 72 );

	/** Fatal error types. */
	const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

	/**
	 * Whether the recorder is installed in this request.
	 *
	 * @var bool
	 */
	private static $installed = false;

	/**
	 * The error reporting level the site had before the recorder widened it.
	 *
	 * @var int
	 */
	private static $site_level = 0;

	/**
	 * The error handler that was in place before ours.
	 *
	 * @var callable|null
	 */
	private static $prev = null;

	/**
	 * Errors seen in this request, waiting to be saved.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Fatal errors already recorded in this request.
	 *
	 * @var array
	 */
	private static $fatal_done = array();

	/**
	 * The flag, read once per request.
	 *
	 * @var array|null
	 */
	private static $flag_cache = null;

	/**
	 * Called as the plugin loads: starts recording at once when a watch is running, so errors
	 * raised by plugins loaded after this one are caught too.
	 */
	public static function boot() {
		if ( ! self::flag() ) {
			return;
		}
		// WP-CLI and cron-by-CLI can run a different PHP than the web server: their errors would be
		// credited to the wrong version.
		if ( in_array( PHP_SAPI, array( 'cli', 'phpdbg' ), true ) && ! apply_filters( 'airworthy_pro_watch_cli', false ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Hooks the schedule.
	 */
	public static function register() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::ROUND, array( __CLASS__, 'round' ) );
	}

	/**
	 * Installs the error handler and the shutdown check.
	 */
	public static function install() {
		if ( self::$installed ) {
			return;
		}
		self::$installed  = true;
		self::$site_level = (int) error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Reads the level; changes nothing.
		// Deprecations are the main sign of an upgrade problem and a normal site hides them. Widen the
		// level so they reach the handler; the handler swallows them again (see handle()).
		error_reporting( self::$site_level | E_DEPRECATED | E_USER_DEPRECATED ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Widened so deprecations reach the handler, which hides them again.
		self::$prev = set_error_handler( array( __CLASS__, 'handle' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Records errors, then passes them on.
		register_shutdown_function( array( __CLASS__, 'shutdown' ) );
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_fatal_message' ), 10, 2 );
	}

	/**
	 * Takes the recorder out again (tests, and the end of a watch within one request).
	 */
	public static function uninstall_recorder() {
		if ( ! self::$installed ) {
			return;
		}
		restore_error_handler();
		error_reporting( self::$site_level ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Puts back what the site had.
		remove_filter( 'wp_php_error_message', array( __CLASS__, 'on_fatal_message' ), 10 );
		self::$installed  = false;
		self::$prev       = null;
		self::$pending    = array();
		self::$fatal_done = array();
	}

	// ── State ──────────────────────────────────────────────────────────────────────────────────

	/**
	 * The tiny flag the recorder reads on every request.
	 *
	 * @return array{status:string,from:string}|null Null when no watch is running.
	 */
	public static function flag() {
		if ( null === self::$flag_cache ) {
			$f                = get_site_option( self::FLAG, '' );
			self::$flag_cache = ( is_array( $f ) && isset( $f['status'], $f['from'] ) && in_array( $f['status'], array( 'before', 'after' ), true ) ) ? $f : array();
		}
		return self::$flag_cache ? self::$flag_cache : null;
	}

	/**
	 * Sets or clears the flag.
	 *
	 * @param array|null $flag status and from, or null.
	 */
	private static function set_flag( $flag ) {
		self::$flag_cache = $flag ? $flag : array();
		if ( ! $flag ) {
			delete_site_option( self::FLAG );
			return;
		}
		if ( is_multisite() ) {
			update_site_option( self::FLAG, $flag );
		} else {
			update_option( self::FLAG, $flag, true );
		}
	}

	/**
	 * Saves a non-autoloaded value.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private static function save( $name, $value ) {
		if ( is_multisite() ) {
			update_site_option( $name, $value );
		} else {
			update_option( $name, $value, false );
		}
	}

	/**
	 * What the watch is doing.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_site_option( self::STATE, array() );
		return array_merge(
			array(
				'status'     => 'idle', // idle | before | after | done.
				'php_from'   => '',
				'php_to'     => '',
				'started'    => 0,
				'switched'   => 0,
				'ends'       => 0,
				'finished'   => 0,
				'window'     => 72,
				'email'      => true,
				'baseline'   => null,
				'rounds'     => array(),
				'next_round' => 0,
				'alerted'    => false,
				'note'       => '',
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * The recorded errors.
	 *
	 * @return array{meta:array{t:int,dropped:int},rows:array}
	 */
	public static function table() {
		$t = get_site_option( self::ERRORS, array() );
		$t = is_array( $t ) ? $t : array();
		return array(
			'meta' => array_merge(
				array(
					't'       => 0,
					'dropped' => 0,
				),
				isset( $t['meta'] ) && is_array( $t['meta'] ) ? $t['meta'] : array()
			),
			'rows' => isset( $t['rows'] ) && is_array( $t['rows'] ) ? $t['rows'] : array(),
		);
	}

	// ── Start and stop ─────────────────────────────────────────────────────────────────────────

	/**
	 * Starts a watch.
	 *
	 * @param int  $window  Hours to watch after the switch (24, 48 or 72).
	 * @param bool $email   Whether to email the result.
	 * @param bool $already True when the switch has already happened: watch from now.
	 */
	public static function start( $window = 72, $email = true, $already = false ) {
		self::stop();
		$window = in_array( (int) $window, self::WINDOWS, true ) ? (int) $window : 72;
		$minor  = Targets::minor( Env::php_version() );
		$now    = time();
		$state  = array_merge(
			self::state(),
			array(
				'status'     => $already ? 'after' : 'before',
				'php_from'   => $already ? '' : $minor,
				'php_to'     => $already ? $minor : '',
				'started'    => $now,
				'switched'   => $already ? $now : 0,
				'ends'       => $already ? $now + $window * HOUR_IN_SECONDS : 0,
				'finished'   => 0,
				'window'     => $window,
				'email'      => (bool) $email,
				'baseline'   => null,
				'rounds'     => array(),
				'next_round' => $already ? $now + DAY_IN_SECONDS : 0,
				'alerted'    => false,
				'note'       => '',
			)
		);
		if ( $already ) {
			$state['rounds'][] = Pages::run();
		} else {
			$state['baseline'] = Pages::run();
		}
		self::save( self::STATE, $state );
		self::set_flag(
			array(
				'status' => $state['status'],
				'from'   => $state['php_from'],
			)
		);
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( $now + HOUR_IN_SECONDS, 'hourly', self::TICK );
		}
	}

	/**
	 * Stops recording and forgets everything.
	 */
	public static function stop() {
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::ROUND );
		self::set_flag( null );
		delete_site_option( self::STATE );
		delete_site_option( self::ERRORS );
		self::$pending = array();
	}

	/**
	 * Takes a page check now: a fresh "before" while waiting for the switch, another "after" once
	 * it has happened.
	 */
	public static function check_now() {
		$state = self::state();
		if ( 'before' === $state['status'] ) {
			$state['baseline'] = Pages::run();
			self::save( self::STATE, $state );
		} elseif ( 'after' === $state['status'] ) {
			self::round();
		}
	}

	/**
	 * Removes the scheduled jobs (deactivation). Recording stops with the plugin.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::ROUND );
	}

	// ── Recording ──────────────────────────────────────────────────────────────────────────────

	/**
	 * PHP's error handler while a watch runs.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return bool True when the error is handled (nothing more should happen to it).
	 */
	public static function handle( $errno, $errstr, $errfile = '', $errline = 0 ) {
		// Silenced with @ (or the level is off): nothing to record, behave as before.
		if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Reads the level; changes nothing.
			return self::chain( $errno, $errstr, $errfile, $errline );
		}
		try {
			self::record( (int) $errno, (string) $errstr, (string) $errfile, (int) $errline );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Recording must never break a page.
			unset( $e );
		}
		if ( self::$site_level & $errno ) {
			return self::chain( $errno, $errstr, $errfile, $errline ); // The site would have seen it: carry on as it would.
		}
		return true; // Only visible because the recorder widened the level: keep it hidden.
	}

	/**
	 * Passes an error to the handler that was there before.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return bool
	 */
	private static function chain( $errno, $errstr, $errfile, $errline ) {
		if ( self::$prev ) {
			return (bool) call_user_func( self::$prev, $errno, $errstr, $errfile, $errline );
		}
		return false; // PHP's own handling.
	}

	/**
	 * Notes one error in memory (saved at the end of the request).
	 *
	 * @param int    $errno Level.
	 * @param string $msg   Message.
	 * @param string $file  File.
	 * @param int    $line  Line.
	 */
	public static function record( $errno, $msg, $file, $line ) {
		$level = self::level_of( $errno );
		$file  = wp_normalize_path( $file );
		$text  = self::clean_message( $msg );
		$rel   = self::relative( $file );
		$key   = md5( $level . '|' . $rel . '|' . $line . '|' . $text );
		if ( isset( self::$pending[ $key ] ) ) {
			++self::$pending[ $key ]['n'];
			return;
		}
		$who = self::who( $file );
		$via = false;
		if ( in_array( $who[0], array( 'core', 'other' ), true ) ) {
			// Raised inside WordPress itself: the plugin or theme that called it is usually to blame.
			$caller = self::caller();
			if ( $caller ) {
				$who = $caller;
				$via = true;
			}
		}
		self::$pending[ $key ] = array(
			'who'   => $who[0],
			'slug'  => $who[1],
			'via'   => $via,
			'level' => $level,
			'msg'   => $text,
			'file'  => $rel,
			'line'  => $line,
			'page'  => self::request_path(),
			'n'     => 1,
		);
	}

	/**
	 * A fatal error, from WordPress's own handler.
	 *
	 * @param string $message The message WordPress will show.
	 * @param array  $error   error_get_last() style: type, message, file, line.
	 * @return string The message, unchanged.
	 */
	public static function on_fatal_message( $message, $error ) {
		if ( is_array( $error ) && isset( $error['type'], $error['message'] ) ) {
			self::record_fatal( $error );
			self::flush();
		}
		return $message;
	}

	/**
	 * Records a fatal error once per request.
	 *
	 * @param array $error type, message, file, line.
	 */
	private static function record_fatal( array $error ) {
		$file = isset( $error['file'] ) ? (string) $error['file'] : '';
		$line = isset( $error['line'] ) ? (int) $error['line'] : 0;
		$sig  = md5( $file . '|' . $line . '|' . $error['message'] );
		if ( isset( self::$fatal_done[ $sig ] ) ) {
			return;
		}
		self::$fatal_done[ $sig ] = true;
		try {
			self::record( E_ERROR, (string) $error['message'], $file, $line );
			if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::TICK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::TICK );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Never break the shutdown.
			unset( $e );
		}
	}

	/**
	 * The end of every request while a watch runs: a fatal that got past WordPress's handler, the
	 * save, and a PHP switch nobody told us about.
	 */
	public static function shutdown() {
		if ( ! self::$installed ) {
			return;
		}
		try {
			$e = error_get_last();
			if ( $e && ( $e['type'] & self::FATAL ) ) {
				self::record_fatal( $e );
			}
			self::flush();
			self::detect_switch();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Never break the shutdown.
			unset( $e );
		}
	}

	/**
	 * Saves what this request saw into the error table.
	 */
	public static function flush() {
		if ( ! self::$pending ) {
			return;
		}
		$pending       = self::$pending;
		self::$pending = array();
		$table         = self::table();
		$side          = self::side();
		$now           = time();
		$fresh         = false;
		foreach ( $pending as $key => $row ) {
			if ( isset( $table['rows'][ $key ] ) ) {
				$table['rows'][ $key ][ $side ] += $row['n'];
				$table['rows'][ $key ]['last']   = $now;
				continue;
			}
			if ( count( $table['rows'] ) >= self::MAX_ERRORS ) {
				$table['meta']['dropped'] += $row['n'];
				continue;
			}
			$fresh                 = true;
			$table['rows'][ $key ] = array(
				'who'   => $row['who'],
				'slug'  => $row['slug'],
				'via'   => $row['via'],
				'level' => $row['level'],
				'msg'   => $row['msg'],
				'file'  => $row['file'],
				'line'  => $row['line'],
				'page'  => $row['page'],
				'from'  => 'from' === $side ? $row['n'] : 0,
				'to'    => 'to' === $side ? $row['n'] : 0,
				'first' => $now,
				'last'  => $now,
			);
		}
		// A busy site can raise the same deprecation on every request: count it, but don't rewrite
		// the table more than every half minute unless something new turned up.
		if ( ! $fresh && $now - (int) $table['meta']['t'] < self::FLUSH_EVERY ) {
			return;
		}
		$table['meta']['t'] = $now;
		self::save( self::ERRORS, $table );
	}

	/**
	 * Which side of the switch an error counts on, right now.
	 *
	 * @return string from | to
	 */
	private static function side() {
		$flag = self::flag();
		$now  = Targets::minor( Env::php_version() );
		return ( $flag && '' !== $flag['from'] && $flag['from'] === $now ) ? 'from' : 'to';
	}

	/**
	 * Notices that PHP is no longer the version the watch started on.
	 */
	private static function detect_switch() {
		$flag = self::flag();
		if ( ! $flag || 'before' !== $flag['status'] ) {
			return;
		}
		if ( Targets::minor( Env::php_version() ) !== $flag['from'] ) {
			self::note_switch();
		}
	}

	/**
	 * The PHP version changed: start the "after" part.
	 */
	public static function note_switch() {
		$state = self::state();
		if ( 'before' !== $state['status'] ) {
			return;
		}
		$now                 = time();
		$state['status']     = 'after';
		$state['php_to']     = Targets::minor( Env::php_version() );
		$state['switched']   = $now;
		$state['ends']       = $now + (int) $state['window'] * HOUR_IN_SECONDS;
		$state['next_round'] = $now + DAY_IN_SECONDS;
		self::save( self::STATE, $state );
		self::set_flag(
			array(
				'status' => 'after',
				'from'   => $state['php_from'],
			)
		);
		wp_schedule_single_event( $now + 5 * MINUTE_IN_SECONDS, self::ROUND ); // Let caches and opcode caches settle first.
	}

	// ── Schedule ───────────────────────────────────────────────────────────────────────────────

	/**
	 * Takes a page check now and keeps it.
	 */
	public static function round() {
		$state = self::state();
		if ( 'after' !== $state['status'] ) {
			return;
		}
		$state['rounds'][] = Pages::run();
		$state['rounds']   = array_slice( $state['rounds'], -6 );
		self::save( self::STATE, $state );
	}

	/**
	 * The hourly job: notice a switch on a quiet site, take the day's page check, send the alert
	 * for a fatal error, and finish when the time is up.
	 */
	public static function tick() {
		$state = self::state();
		$now   = time();
		if ( ! in_array( $state['status'], array( 'before', 'after' ), true ) ) {
			wp_clear_scheduled_hook( self::TICK );
			return;
		}
		if ( 'before' === $state['status'] ) {
			if ( ! in_array( PHP_SAPI, array( 'cli', 'phpdbg' ), true ) && Targets::minor( Env::php_version() ) !== $state['php_from'] ) {
				self::note_switch();
				return;
			}
			if ( $now - (int) $state['started'] > self::GIVE_UP_DAYS * DAY_IN_SECONDS ) {
				$note = __( 'The watch stopped: PHP was not switched within 14 days.', 'airworthy-pro' );
				self::stop();
				self::save(
					self::STATE,
					array_merge(
						self::state(),
						array(
							'note' => $note,
						)
					)
				);
			}
			return;
		}

		// After the switch.
		$report = self::report();
		if ( $state['email'] && ! $state['alerted'] && $report['fatals'] > 0 ) {
			$state['alerted'] = true;
			self::save( self::STATE, $state );
			Alerts::send_watch_fatal( $report );
		}
		if ( $state['next_round'] && $now >= $state['next_round'] && $now < $state['ends'] ) {
			self::round();
			$state               = self::state();
			$state['next_round'] = $state['next_round'] + DAY_IN_SECONDS < $state['ends'] ? $state['next_round'] + DAY_IN_SECONDS : 0;
			self::save( self::STATE, $state );
		}
		if ( $now >= $state['ends'] ) {
			self::finish();
		}
	}

	/**
	 * The time is up: one last page check, stop recording, tell the owner.
	 */
	public static function finish() {
		$state = self::state();
		if ( 'after' !== $state['status'] ) {
			return;
		}
		$state['rounds'][] = Pages::run();
		$state['rounds']   = array_slice( $state['rounds'], -6 );
		$state['status']   = 'done';
		$state['finished'] = time();
		self::save( self::STATE, $state );
		self::flush();
		self::set_flag( null );
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::ROUND );
		if ( $state['email'] ) {
			Alerts::send_watch_done( self::report() );
		}
	}

	// ── The report ─────────────────────────────────────────────────────────────────────────────

	/**
	 * What the watch has found.
	 *
	 * @return array{status:string,level:string,headline:string,fatals:int,new:array,same:array,pages:array,dropped:int,state:array}
	 */
	public static function report() {
		$state = self::state();
		$table = self::table();
		$new   = array();
		$same  = array();
		foreach ( $table['rows'] as $row ) {
			if ( 'notice' === $row['level'] || $row['to'] < 1 ) {
				continue;
			}
			$row['name'] = self::label_of( $row['who'], $row['slug'] );
			if ( $row['from'] < 1 ) {
				$new[] = $row;
			} else {
				$same[] = $row;
			}
		}
		$order = array(
			'fatal'      => 0,
			'error'      => 1,
			'warning'    => 2,
			'deprecated' => 3,
			'notice'     => 4,
		);
		$sort  = static function ( $a, $b ) use ( $order ) {
			return array( $order[ $a['level'] ], -$a['to'] ) <=> array( $order[ $b['level'] ], -$b['to'] );
		};
		usort( $new, $sort );
		usort( $same, $sort );

		$fatals = 0;
		foreach ( $new as $row ) {
			if ( in_array( $row['level'], array( 'fatal', 'error' ), true ) ) {
				++$fatals;
			}
		}

		$last   = $state['rounds'] ? end( $state['rounds'] ) : null;
		$pages  = Pages::compare( $state['baseline'], $last );
		$worse  = 0;
		$slower = 0;
		foreach ( $pages as $p ) {
			if ( 'worse' === $p['change'] ) {
				++$worse;
			} elseif ( 'slower' === $p['change'] ) {
				++$slower;
			}
		}

		$level    = 'ok';
		$headline = __( 'Nothing new has gone wrong', 'airworthy-pro' );
		if ( in_array( $state['status'], array( 'idle', 'before' ), true ) ) {
			$level    = 'wait';
			$headline = __( 'Waiting for the PHP switch', 'airworthy-pro' );
		} elseif ( $fatals > 0 || $worse > 0 ) {
			$level    = 'bad';
			$headline = __( 'Something broke after the switch', 'airworthy-pro' );
		} elseif ( $new || $slower > 0 ) {
			$level    = 'warn';
			$headline = __( 'No crashes, but there are new warnings', 'airworthy-pro' );
		}

		return array(
			'status'   => $state['status'],
			'level'    => $level,
			'headline' => $headline,
			'fatals'   => $fatals,
			'worse'    => $worse,
			'new'      => $new,
			'same'     => $same,
			'pages'    => $pages,
			'dropped'  => (int) $table['meta']['dropped'],
			'state'    => $state,
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────────────────────

	/**
	 * A word for an error level.
	 *
	 * @param int $errno PHP error level.
	 * @return string fatal | error | warning | deprecated | notice
	 */
	public static function level_of( $errno ) {
		if ( $errno & self::FATAL ) {
			return 'fatal';
		}
		if ( $errno & ( E_USER_ERROR | E_RECOVERABLE_ERROR ) ) {
			return 'error';
		}
		if ( $errno & ( E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING ) ) {
			return 'warning';
		}
		if ( $errno & ( E_DEPRECATED | E_USER_DEPRECATED ) ) {
			return 'deprecated';
		}
		return 'notice';
	}

	/**
	 * Whose file this is.
	 *
	 * @param string $file Normalised path.
	 * @return array{0:string,1:string} Kind (plugin | theme | mu | core | other) and slug.
	 */
	public static function who( $file ) {
		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin' => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu'     => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'theme'  => wp_normalize_path( get_theme_root() ),
		);
		foreach ( $roots as $kind => $root ) {
			if ( 0 === strpos( $file, $root . '/' ) ) {
				$rest = explode( '/', substr( $file, strlen( $root ) + 1 ) );
				$slug = $rest[0];
				if ( count( $rest ) < 2 ) {
					$slug = preg_replace( '/\.php$/', '', $slug ); // A single-file plugin.
				}
				return array( $kind, (string) $slug );
			}
		}
		$abs = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $abs . WPINC . '/' ) || 0 === strpos( $file, $abs . 'wp-admin/' ) ) {
			return array( 'core', '' );
		}
		return array( 'other', '' );
	}

	/**
	 * The plugin or theme further up the call stack, when an error was raised inside WordPress.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function caller() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Needed to tell which plugin called WordPress.
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );
		foreach ( $frames as $f ) {
			if ( empty( $f['file'] ) ) {
				continue;
			}
			$who = self::who( (string) $f['file'] );
			if ( in_array( $who[0], array( 'plugin', 'theme', 'mu' ), true ) && 'airworthy-pro' !== $who[1] && 'airworthy' !== $who[1] ) {
				return $who;
			}
		}
		return null;
	}

	/**
	 * A message fit to keep: no server folders, no query strings, no long numbers, not too long.
	 *
	 * @param string $msg Message.
	 * @return string
	 */
	public static function clean_message( $msg ) {
		$msg = (string) $msg;
		// Not wp_normalize_path() on the whole message: it would squash the // in a web address.
		foreach ( array( ABSPATH, wp_normalize_path( ABSPATH ), trailingslashit( WP_CONTENT_DIR ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) as $strip ) {
			$msg = str_replace( $strip, '', $msg );
		}
		$msg = preg_replace( '~(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*~i', '$1', $msg );
		$msg = preg_replace( '/\d{4,}/', '#', (string) $msg );
		$msg = trim( (string) preg_replace( '/\s+/', ' ', (string) $msg ) );
		// A fatal error's message carries its stack trace; the first line says it all.
		$cut = strpos( $msg, ' Stack trace:' );
		if ( false !== $cut ) {
			$msg = substr( $msg, 0, $cut );
		}
		return mb_strlen( $msg ) > 220 ? mb_substr( $msg, 0, 217 ) . '...' : $msg;
	}

	/**
	 * A file's path from the WordPress folder, with the folders of the server taken off.
	 *
	 * @param string $file Normalised path.
	 * @return string
	 */
	public static function relative( $file ) {
		$file = wp_normalize_path( $file );
		foreach ( array( wp_normalize_path( WP_CONTENT_DIR ) . '/', wp_normalize_path( ABSPATH ) ) as $root ) {
			if ( 0 === strpos( $file, $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}
		return basename( $file );
	}

	/**
	 * Which page a request was for (no query string, which may hold tokens).
	 *
	 * @return string
	 */
	private static function request_path() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return '(wp-cron)';
		}
		if ( in_array( PHP_SAPI, array( 'cli', 'phpdbg' ), true ) ) {
			return '(command line)';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only the path is kept, cut and sanitised below.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '' === $path ? '/' : mb_substr( sanitize_text_field( $path ), 0, 120 );
	}

	/**
	 * A plugin, theme or area in words.
	 *
	 * @param string $who  Kind.
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function label_of( $who, $slug ) {
		static $plugins = null;
		switch ( $who ) {
			case 'plugin':
				if ( null === $plugins ) {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$plugins = array();
					foreach ( get_plugins() as $file => $data ) {
						$key             = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
						$plugins[ $key ] = (string) $data['Name'];
					}
				}
				return isset( $plugins[ $slug ] ) && '' !== $plugins[ $slug ] ? $plugins[ $slug ] : $slug;
			case 'theme':
				$theme = wp_get_theme( $slug );
				/* translators: %s: theme name. */
				return $theme->exists() ? sprintf( __( '%s (theme)', 'airworthy-pro' ), $theme->get( 'Name' ) ) : $slug;
			case 'mu':
				/* translators: %s: must-use plugin file name. */
				return sprintf( __( '%s (must-use plugin)', 'airworthy-pro' ), $slug );
			case 'core':
				return __( 'WordPress itself', 'airworthy-pro' );
			default:
				return __( 'Something else on the server', 'airworthy-pro' );
		}
	}
}
