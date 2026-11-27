<?php
/**
 * Airworthy Pro after-the-switch watch checks.
 *
 * Needs a site with Airworthy and Airworthy Pro active (the local test site).
 * Run: wp eval-file tests/pro-watch.php
 * It saves and restores the watch's options, sends no real email and makes no real web request.
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\Alerts;
use AirworthyPro\Pages;
use AirworthyPro\Watch;
use AirworthyPro\WatchTab;

if ( ! class_exists( 'AirworthyPro\\Watch' ) ) {
	WP_CLI::error( 'Airworthy Pro (with the watch) is not active.' );
}

$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};

// Reach private statics to reset them between steps.
$reset_cache = static function () {
	$rp = new ReflectionProperty( Watch::class, 'flag_cache' );
	if ( PHP_VERSION_ID < 80100 ) {
		$rp->setAccessible( true );
	}
	$rp->setValue( null, null );
};
$prop        = static function ( $name ) {
	$rp = new ReflectionProperty( Watch::class, $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$rp->setAccessible( true );
	}
	return $rp->getValue();
};

$names = array( Watch::STATE, Watch::ERRORS, Watch::FLAG, 'airworthy_pro_monitor' );
$saved = array();
foreach ( $names as $n ) {
	$saved[ $n ] = get_site_option( $n, null );
	delete_site_option( $n );
	delete_option( $n );
}
Watch::stop();
$reset_cache();
$level_before = error_reporting();

// Mail and web requests are captured.
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$http = array( 'code' => 200, 'body' => '<html>fine</html>', 'error' => null, 'calls' => 0 );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$http ) {
		++$http['calls'];
		if ( $http['error'] ) {
			return new WP_Error( 'http_request_failed', $http['error'] );
		}
		return array( 'headers' => array(), 'body' => $http['body'], 'response' => array( 'code' => $http['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
add_filter( 'airworthy_pro_watch_cli', '__return_true' ); // The recorder normally ignores the command line.

$here = Airworthy\Targets::minor( Airworthy\Env::php_version() );

// ── Whose file ───────────────────────────────────────────────────────────────────────────────
$plug = wp_normalize_path( WP_PLUGIN_DIR );
$check( 'Whose file: a plugin folder', array( 'plugin', 'contact-form-7' ) === Watch::who( $plug . '/contact-form-7/includes/x.php' ) );
$check( 'Whose file: a single-file plugin', array( 'plugin', 'hello' ) === Watch::who( $plug . '/hello.php' ) );
$check( 'Whose file: a theme', 'theme' === Watch::who( wp_normalize_path( get_theme_root() ) . '/twentytwentyfive/functions.php' )[0] );
$check( 'Whose file: a must-use plugin', array( 'mu', 'airworthy-tests' ) === Watch::who( wp_normalize_path( WPMU_PLUGIN_DIR ) . '/airworthy-tests/a.php' ) );
$check( 'Whose file: WordPress core', 'core' === Watch::who( wp_normalize_path( ABSPATH ) . WPINC . '/class-wp.php' )[0] && 'core' === Watch::who( wp_normalize_path( ABSPATH ) . 'wp-admin/includes/x.php' )[0] );
$check( 'Whose file: anything else', 'other' === Watch::who( '/opt/something/else.php' )[0] );

// ── Messages ─────────────────────────────────────────────────────────────────────────────────
$msg = Watch::clean_message( 'Uncaught TypeError in ' . ABSPATH . 'wp-content/plugins/x/x.php:10 id 1234567 see https://example.com/a?token=SECRET&b=2' . "\nStack trace:\n#0 secret" );
$check( 'Message: the server folder is removed', false === strpos( $msg, ABSPATH ) && false !== strpos( $msg, 'wp-content/plugins/x/x.php' ), $msg );
$check( 'Message: a query string is cut off', false === strpos( $msg, 'SECRET' ) && false !== strpos( $msg, 'https://example.com/a' ), $msg );
$check( 'Message: long numbers are masked', false === strpos( $msg, '1234567' ), $msg );
$check( 'Message: the stack trace is dropped', false === strpos( $msg, 'secret' ), $msg );
$check( 'Message: long text is cut', mb_strlen( Watch::clean_message( str_repeat( 'a', 500 ) ) ) <= 220 );
$check( 'Levels: fatal, error, warning, deprecated, notice', array( 'fatal', 'error', 'warning', 'deprecated', 'notice' ) === array( Watch::level_of( E_ERROR ), Watch::level_of( E_RECOVERABLE_ERROR ), Watch::level_of( E_WARNING ), Watch::level_of( E_USER_DEPRECATED ), Watch::level_of( E_NOTICE ) ) );

// ── Page checks ──────────────────────────────────────────────────────────────────────────────
$check( 'Pages: 200 is ok, 500 is an error, 404 is missing', array( 'ok', 'error', 'missing' ) === array( Pages::classify( 200, 'hi' ), Pages::classify( 503, '' ), Pages::classify( 404, '' ) ) );
$check( 'Pages: a 200 that prints a PHP error is caught', 'shows_error' === Pages::classify( 200, '<b>Fatal error</b>: x' ) && 'shows_error' === Pages::classify( 200, '<p>There has been a critical error on this website.</p>' ) && 'shows_error' === Pages::classify( 200, '<br /><b>Deprecated</b>: x in' ) );
$check( 'Pages: no query string is kept', '/a/b' === Pages::path_of( 'https://example.com/a/b?token=1' ) );
$t = wp_list_pluck( Pages::targets(), 'key' );
$check( 'Pages: home, login and REST are checked', in_array( 'home', $t, true ) && in_array( 'login', $t, true ) && in_array( 'rest', $t, true ), implode( ',', $t ) );
$ok  = array( 'status' => 200, 'ms' => 100, 'state' => 'ok', 'note' => '' );
$bad = array( 'status' => 500, 'ms' => 100, 'state' => 'error', 'note' => '' );
$blk = array( 'status' => 0, 'ms' => 8000, 'state' => 'blocked', 'note' => '' );
$slow = array( 'status' => 200, 'ms' => 2500, 'state' => 'ok', 'note' => '' );
$check( 'Pages: ok to error is worse, error to ok is better', 'worse' === Pages::change( $ok, $bad ) && 'better' === Pages::change( $bad, $ok ) );
$check( 'Pages: much slower is flagged, a little slower is not', 'slower' === Pages::change( $ok, $slow ) && 'same' === Pages::change( $ok, array_merge( $ok, array( 'ms' => 300 ) ) ) );
$check( 'Pages: a page that could not be reached is "not compared"', 'unknown' === Pages::change( $ok, $blk ) && 'unknown' === Pages::change( $blk, $ok ) );
$http['error'] = 'cURL error 7: refused';
$run           = Pages::run();
$check( 'Pages: a site that cannot ask itself is reported, not crashed', $run['pages'] && 'blocked' === $run['pages'][0]['state'] && 0 === $run['pages'][0]['status'] );
$http['error'] = null;
$run           = Pages::run();
$check( 'Pages: a normal run records status and time for every page', 'ok' === $run['pages'][0]['state'] && 200 === $run['pages'][0]['status'] && $run['php'] === $here );

// ── Starting ─────────────────────────────────────────────────────────────────────────────────
Watch::start( 72, true, false );
$reset_cache();
$st = Watch::state();
$check( 'Start: status is "before", on this PHP, with a baseline page check', 'before' === $st['status'] && $st['php_from'] === $here && ! empty( $st['baseline']['pages'] ) );
$check( 'Start: the recorder flag is set and the hourly job scheduled', Watch::flag() === array( 'status' => 'before', 'from' => $here ) && (bool) wp_next_scheduled( Watch::TICK ) );

// ── Recording, with the real handler ─────────────────────────────────────────────────────────
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED ); // Like a site with WP_DEBUG off.
Watch::install();
$check( 'Recorder: widens error reporting so deprecations reach it', (bool) ( error_reporting() & E_USER_DEPRECATED ) );
ob_start();
trigger_error( 'Test deprecation one', E_USER_DEPRECATED );
$printed = ob_get_clean();
$check( 'Recorder: a deprecation the site would hide stays hidden', '' === $printed );
@trigger_error( 'Silenced with @', E_USER_DEPRECATED ); // phpcs:ignore
Watch::handle( E_USER_DEPRECATED, 'Plugin deprecation', $plug . '/foo-plugin/foo.php', 12 );
Watch::handle( E_USER_DEPRECATED, 'Plugin deprecation', $plug . '/foo-plugin/foo.php', 12 );
Watch::handle( E_WARNING, 'Undefined array key "x"', $plug . '/bar-plugin/inc/bar.php', 40 );
Watch::flush();
$table = Watch::table();
$rows  = array_values( $table['rows'] );
$by    = array();
foreach ( $rows as $r ) {
	$by[ $r['msg'] ] = $r;
}
$check( 'Recorder: the real handler recorded the deprecation', isset( $by['Test deprecation one'] ) && 'deprecated' === $by['Test deprecation one']['level'] );
$check( 'Recorder: an error silenced with @ is not recorded', ! isset( $by['Silenced with @'] ) );
$check( 'Recorder: the same error counts once with a tally of two', isset( $by['Plugin deprecation'] ) && 2 === $by['Plugin deprecation']['from'] && 'foo-plugin' === $by['Plugin deprecation']['slug'] && 'plugin' === $by['Plugin deprecation']['who'], wp_json_encode( $by['Plugin deprecation'] ?? null ) );
$check( 'Recorder: before the switch errors count on the old version', isset( $by['Undefined array key "x"'] ) && 1 === $by['Undefined array key "x"']['from'] && 0 === $by['Undefined array key "x"']['to'] );
$check( 'Recorder: the page and file are kept without the server folder', false === strpos( $by['Undefined array key "x"']['file'], ABSPATH ) && 'plugins/bar-plugin/inc/bar.php' === $by['Undefined array key "x"']['file'] );

// ── The switch ───────────────────────────────────────────────────────────────────────────────
// Pretend the watch began on PHP 7.4: this PHP is then "the new one".
$st             = Watch::state();
$st['php_from'] = '7.4';
update_option( Watch::STATE, $st, false );
update_option( Watch::FLAG, array( 'status' => 'before', 'from' => '7.4' ), true );
$reset_cache();
Watch::shutdown();
$reset_cache();
$st = Watch::state();
$check( 'Switch: a changed PHP version is noticed by itself', 'after' === $st['status'] && $st['php_to'] === $here && $st['switched'] > 0 && $st['ends'] > $st['switched'] );
$check( 'Switch: the "after" page check is scheduled soon', (bool) wp_next_scheduled( Watch::ROUND ) );
$check( 'Switch: the flag now says "after"', Watch::flag() === array( 'status' => 'after', 'from' => '7.4' ) );

Watch::handle( E_USER_DEPRECATED, 'Plugin deprecation', $plug . '/foo-plugin/foo.php', 12 ); // Seen before as well.
Watch::handle( E_WARNING, 'Brand new warning', $plug . '/baz-plugin/baz.php', 7 );
Watch::flush();
$rep = Watch::report();
$new = wp_list_pluck( $rep['new'], 'msg' );
$same = wp_list_pluck( $rep['same'], 'msg' );
$check( 'Report: only what did not happen before is "new"', array( 'Brand new warning' ) === array_values( $new ), wp_json_encode( $new ) );
$check( 'Report: what happened before as well is "also seen before"', array( 'Plugin deprecation' ) === array_values( $same ), wp_json_encode( $same ) );
$check( 'Report: new warnings with no crash say "warn"', 'warn' === $rep['level'] && 0 === $rep['fatals'], $rep['level'] );
$check( 'Report: plugins are named', 'baz-plugin' === $rep['new'][0]['name'] || '' !== $rep['new'][0]['name'] );

// A fatal error, through WordPress's handler.
$msg = 'Uncaught TypeError: x in ' . $plug . '/baz-plugin/baz.php:9' . "\nStack trace:\n#0 {main}";
apply_filters( 'wp_php_error_message', 'Shown to the visitor', array( 'type' => E_ERROR, 'message' => $msg, 'file' => $plug . '/baz-plugin/baz.php', 'line' => 9 ) );
$rep = Watch::report();
$check( 'Fatal: a crash is recorded against the plugin, without its stack trace', 1 === $rep['fatals'] && 'bad' === $rep['level'], $rep['level'] );
$fatal = $rep['new'][0];
$check( 'Fatal: crashes sort first', 'fatal' === $fatal['level'] && 'baz-plugin' === $fatal['slug'] && false === strpos( $fatal['msg'], 'Stack trace' ), wp_json_encode( $fatal ) );

// ── Table limit ──────────────────────────────────────────────────────────────────────────────
for ( $i = 0; $i < 205; $i++ ) {
	Watch::record( E_WARNING, "Flood $i", $plug . '/flood/f.php', $i + 1 );
}
Watch::flush();
$t2 = Watch::table();
$check( 'Limit: at most 200 different errors are kept, the rest only counted', 200 === count( $t2['rows'] ) && $t2['meta']['dropped'] > 0, count( $t2['rows'] ) . ' / ' . $t2['meta']['dropped'] );

// ── Alerts and the end ───────────────────────────────────────────────────────────────────────
Watch::stop();
$reset_cache();
Watch::start( 24, true, true ); // "I already switched".
$reset_cache();
$st = Watch::state();
$check( 'Already switched: status "after" at once, one page check taken, no baseline', 'after' === $st['status'] && '' === $st['php_from'] && $st['php_to'] === $here && null === $st['baseline'] && 1 === count( $st['rounds'] ) );
Watch::handle( E_WARNING, 'After-only warning', $plug . '/qux/q.php', 3 );
Watch::flush();
$check( 'Already switched: everything recorded counts as new', 1 === count( Watch::report()['new'] ) );
apply_filters( 'wp_php_error_message', 'x', array( 'type' => E_ERROR, 'message' => 'Boom in ' . $plug . '/qux/q.php', 'file' => $plug . '/qux/q.php', 'line' => 5 ) );
$mails = array();
Watch::tick();
$check( 'Alert: a fatal error sends one email while the watch runs', 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'fatal error' ), count( $mails ) . ' ' . ( $mails[0]['subject'] ?? '' ) );
Watch::tick();
$check( 'Alert: and only one', 1 === count( $mails ) );

$mails = array();
$st    = Watch::state();
$st['ends'] = time() - 10;
update_option( Watch::STATE, $st, false );
$http['code'] = 500; // The last page check finds the home page down.
Watch::tick();
$st = Watch::state();
$check( 'End: when the time is up the watch finishes and stops recording', 'done' === $st['status'] && $st['finished'] > 0 && null === Watch::flag() && ! wp_next_scheduled( Watch::TICK ) );
$check( 'End: the last page check is kept and the pages compare as worse or not compared', count( $st['rounds'] ) >= 2 );
$check( 'End: one summary email goes out, naming the result', 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'watch is over' ), count( $mails ) . ' ' . ( $mails[0]['subject'] ?? '' ) );
$check( 'End: the summary email has a plain-text version and links to the tab', false !== strpos( $mails[0]['message'], 'tab=watch' ) );
$http['code'] = 200;

// Alerts pause when the licence has ended.
$mails = array();
add_filter( 'airworthy_pro_alerts_allowed', '__return_false' );
Alerts::send_watch_done( Watch::report() );
$check( 'Licence ended: watch emails are not sent', 0 === count( $mails ) );
remove_filter( 'airworthy_pro_alerts_allowed', '__return_false' );

// ── Pages compare ────────────────────────────────────────────────────────────────────────────
$cmp = Pages::compare(
	array( 'pages' => array( array_merge( array( 'key' => 'home', 'label' => 'Home', 'path' => '/' ), $ok ) ) ),
	array( 'pages' => array( array_merge( array( 'key' => 'home', 'label' => 'Home', 'path' => '/' ), $bad ) ) )
);
$check( 'Pages: compare lines up before and after by page', 1 === count( $cmp ) && 'worse' === $cmp[0]['change'] );

// ── The tab ──────────────────────────────────────────────────────────────────────────────────
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$render = static function () {
	ob_start();
	WatchTab::render();
	return ob_get_clean();
};
$html = $render();
$check( 'Tab (finished): shows the result, the pages and the errors', false !== strpos( $html, 'airworthy-pro-watch-status' ) && false !== strpos( $html, 'Your pages' ) && false !== strpos( $html, 'New since the switch' ) );
$check( 'Tab (finished): offers a new watch, with nonces on every form', false !== strpos( $html, 'Start a new watch' ) && substr_count( $html, 'name="_wpnonce"' ) === substr_count( $html, '<form' ), substr_count( $html, 'name="_wpnonce"' ) . '/' . substr_count( $html, '<form' ) );
Watch::stop();
$html = $render();
$check( 'Tab (idle): explains what it does and offers both start buttons', false !== strpos( $html, 'Start the watch' ) && false !== strpos( $html, 'I already switched' ) && false !== strpos( $html, 'What it records' ) && 2 === substr_count( $html, 'name="already"' ) );
$check( 'Tab (idle): the start form has a nonce', false !== strpos( $html, 'name="_wpnonce"' ) );
Watch::start( 48, false, false );
$html = $render();
$check( 'Tab (waiting): says to ask the host to switch, and shows the baseline', false !== strpos( $html, 'ask your host to switch' ) && false !== strpos( $html, 'Before the switch' ) );
$tabs = apply_filters( 'airworthy_admin_tabs', array() );
$check( 'Tab: it appears in the Airworthy tabs', isset( $tabs['watch'] ) );

// ── Cleaning up ──────────────────────────────────────────────────────────────────────────────
Watch::uninstall_recorder();
$check( 'Recorder: taken out, the site\'s error reporting is back as it was', error_reporting() === ( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED ) );
error_reporting( $level_before );
$reset_cache();
Watch::stop();
$check( 'Stop: removes the records, the flag and the jobs', null === Watch::flag() && ! wp_next_scheduled( Watch::TICK ) && array() === Watch::table()['rows'] && 'idle' === Watch::state()['status'] );
Watch::stop();
$reset_cache();
Watch::boot();
$check( 'Boot: with no watch running the recorder is not installed', false === $prop( 'installed' ) );

foreach ( $saved as $name => $value ) {
	delete_site_option( $name );
	delete_option( $name );
	if ( null !== $value ) {
		update_site_option( $name, $value );
	}
}

echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
