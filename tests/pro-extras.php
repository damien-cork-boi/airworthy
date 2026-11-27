<?php
/**
 * Airworthy Pro 0.6.0 checks: chat alerts (webhook) and the Fix brief.
 *
 * Needs Airworthy and Airworthy Pro active, and the fx-implode fixture plugin installed (the
 * local test site has it after tests/run.sh). Adds a finished scan of its own and removes it.
 * Run: wp eval-file tests/pro-extras.php
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\Alerts;
use AirworthyPro\Brief;
use AirworthyPro\Monitor;

if ( ! class_exists( 'AirworthyPro\\Brief' ) ) {
	WP_CLI::error( 'Airworthy Pro (0.6.0) is not active.' );
}
$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};

$saved = array( Monitor::OPTION => get_site_option( Monitor::OPTION, null ) );
delete_site_option( Monitor::OPTION );
$mails = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
}, 10, 2 );
$posts = array();
$reply = array( 'code' => 200, 'error' => false );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$posts, &$reply ) {
	$posts[] = array( 'url' => $url, 'args' => $args );
	if ( $reply['error'] ) {
		return new WP_Error( 'http_request_failed', 'refused' );
	}
	return array( 'headers' => array(), 'body' => 'ok', 'response' => array( 'code' => $reply['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

// ── Chat alerts ──────────────────────────────────────────────────────────────────────────────
$hook = 'https://hooks.slack.com/services/T000/B000/abc123';
$check( 'Webhook: an https address is kept', $hook === Monitor::clean( array( 'webhook' => $hook ) )['webhook'] );
$check( 'Webhook: http is refused', '' === Monitor::clean( array( 'webhook' => 'http://hooks.example.com/x' ) )['webhook'] );
$check( 'Webhook: junk and private-network addresses are refused', '' === Monitor::clean( array( 'webhook' => 'not a url' ) )['webhook'] && '' === Monitor::clean( array( 'webhook' => 'https://127.0.0.1/x' ) )['webhook'] && '' === Monitor::clean( array( 'webhook' => 'https://192.168.1.10/x' ) )['webhook'] && '' === Monitor::clean( array( 'webhook' => 'https://10.0.0.5/x' ) )['webhook'] );
$check( 'Webhook: off by default', '' === Monitor::defaults()['webhook'] );

Monitor::save( Monitor::clean( array( 'enabled' => '1', 'recipients' => 'owner@example.com', 'webhook' => $hook ) ) );
$mails = $posts = array();
$ok    = Alerts::send_failed();
$check( 'Webhook: an alert is emailed and posted to the address', $ok && 1 === count( $mails ) && 1 === count( $posts ) && $hook === $posts[0]['url'], count( $mails ) . '/' . count( $posts ) );
$body = json_decode( (string) $posts[0]['args']['body'], true );
$check( 'Webhook: the post is JSON with "text" and "content" (Slack and Discord)', is_array( $body ) && ! empty( $body['text'] ) && $body['text'] === $body['content'] && false !== strpos( $body['text'], 'could not finish' ) && 'application/json' === $posts[0]['args']['headers']['Content-Type'] );
$check( 'Webhook: the post names Airworthy, not the site, as its user agent', 'Airworthy Pro' === $posts[0]['args']['user-agent'] );
$check( 'Webhook: the text is short enough for chat tools', mb_strlen( $body['text'] ) <= 1900 );

$mails = $posts = array();
$reply['error'] = true;
$ok = Alerts::send_failed();
$check( 'Webhook: a failed post is reported, and the email still goes out', false === $ok && 1 === count( $mails ) );
$reply['error'] = false;
$reply['code']  = 404;
$check( 'Webhook: an error answer counts as a failure', false === Alerts::send_failed() );
$reply['code'] = 200;

$mails = $posts = array();
add_filter( 'airworthy_pro_alerts_allowed', '__return_false' );
Alerts::send_failed();
$check( 'Webhook: nothing is posted once the licence has ended', 0 === count( $posts ) && 0 === count( $mails ) );
$test = Alerts::send_test();
$check( 'Webhook: the test message still works then (email and chat)', $test && 1 === count( $mails ) && 1 === count( $posts ) );
remove_filter( 'airworthy_pro_alerts_allowed', '__return_false' );

Monitor::save( Monitor::clean( array( 'enabled' => '1', 'recipients' => 'owner@example.com' ) ) );
$mails = $posts = array();
Alerts::send_failed();
$check( 'Webhook: with no address set, nothing is posted', 1 === count( $mails ) && 0 === count( $posts ) );

wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$_GET['page'] = 'airworthy';
$_GET['tab']  = 'monitor';
ob_start();
AirworthyPro\MonitorTab::render();
$html = ob_get_clean();
$check( 'Webhook: the Monitoring tab has the field, with a warning about what is sent', false !== strpos( $html, 'name="airworthy_pro_monitor[webhook]"' ) && false !== strpos( $html, 'only use one you trust' ) );

// ── Fix brief ────────────────────────────────────────────────────────────────────────────────
global $wpdb;
$t   = \Airworthy\Installer::tables();
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( $t['scans'], array( 'target_php' => '8.4', 'from_php' => '7.4', 'host_php' => '7.4.33', 'status' => 'complete', 'created_at' => $now, 'finished_at' => $now ) );
$scan = (int) $wpdb->insert_id;
$wpdb->insert( $t['components'], array( 'scan_id' => $scan, 'type' => 'plugin', 'slug' => 'fx-implode', 'name' => 'FX Implode', 'version' => '1.0', 'rel_path' => 'fx-implode', 'status' => 'done', 'verdict' => 'blocker', 'errors' => 1, 'warnings' => 1 ) );
$comp = (int) $wpdb->insert_id;
$wpdb->insert( $t['components'], array( 'scan_id' => $scan, 'type' => 'plugin', 'slug' => 'fx-ready', 'name' => 'FX Ready', 'version' => '1.0', 'rel_path' => 'fx-ready', 'status' => 'done', 'verdict' => 'ready' ) );
$rows = array(
	array( 'fx-implode.php', 8, 'error', 'plain', 'PHPCompatibility.ParameterValues.RemovedImplodeFlexibleParamOrder.Removed', 'Passing the separator after the array is no longer supported in implode() since PHP 8.0 in ' . WP_PLUGIN_DIR . '/fx-implode/fx-implode.php' ),
	array( '../../wp-config.php', 3, 'warning', 'plain', 'Some.Rule', 'A deprecation' ),
	array( 'fx-implode.php', 1, 'error', 'guarded', 'Guarded.Rule', 'Never runs' ),
	array( 'fx-implode.php', 2, 'error', 'existing', 'Existing.Rule', 'Already fails today' ),
);
foreach ( $rows as $r ) {
	$wpdb->insert( $t['issues'], array( 'scan_id' => $scan, 'component_id' => $comp, 'file' => $r[0], 'line' => $r[1], 'severity' => $r[2], 'context' => $r[3], 'rule' => $r[4], 'message' => $r[5] ) );
}

$cands = Brief::candidates();
$names = wp_list_pluck( $cands['rows'], 'name' );
$check( 'Brief: lists the plugins that have problems, not the ready ones', in_array( 'FX Implode', $names, true ) && ! in_array( 'FX Ready', $names, true ), implode( ',', $names ) );
$text = (string) Brief::build( $comp );
$check( 'Brief: starts with the task, the plugin, its version and the PHP version', 0 === strpos( $text, 'Task: fix the PHP compatibility problems in the WordPress plugin "FX Implode" 1.0 so that it runs on PHP 8.4' ), substr( $text, 0, 120 ) );
$check( 'Brief: says what the site runs now', false !== strpos( $text, 'The site runs PHP 7.4.33 now' ) );
$check( 'Brief: lists the problem with its file and line', false !== strpos( $text, '1. fx-implode.php, line 8' ) );
$check( 'Brief: quotes the real line from the file', false !== strpos( $text, 'implode( (array) $values' ), $text );
$check( 'Brief: leaves out guarded code and problems that already apply today', false === strpos( $text, 'Never runs' ) && false === strpos( $text, 'Already fails today' ) );
$check( 'Brief: removes the server\'s folder names', false === strpos( $text, WP_PLUGIN_DIR ) && false === strpos( $text, ABSPATH ) );
$check( 'Brief: a file path that climbs out of the plugin is listed but never read', false !== strpos( $text, '../../wp-config.php, line 3' ) && false === strpos( $text, 'DB_PASSWORD' ) && false === strpos( $text, 'DB_NAME' ) );
$check( 'Brief: ends with rules that keep the fix small and a staging reminder', false !== strpos( $text, 'Change only what is needed' ) && false !== strpos( $text, 'diff' ) && false !== strpos( $text, 'staging copy' ) );
$check( 'Brief: a component that is not in the latest scan gets no brief', null === Brief::build( 999999 ) && null === Brief::build( $comp + 1 ) );

$_GET['tab'] = 'brief';
unset( $_GET['component'] );
ob_start();
Brief::render();
$html = ob_get_clean();
$check( 'Tab (no pick): shows the list to choose from', false !== strpos( $html, 'name="component"' ) && false !== strpos( $html, 'FX Implode: 2 problems' ) && false === strpos( $html, 'airworthy-pro-brief-text' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
$_GET['component'] = (string) $comp;
ob_start();
Brief::render();
$html = ob_get_clean();
$check( 'Tab (picked): shows the brief in a read-only box with a copy button', false !== strpos( $html, 'readonly="readonly"' ) && false !== strpos( $html, 'Task: fix the PHP compatibility problems' ) && false !== strpos( $html, 'airworthy-pro-copy-brief' ) );
$_GET['component'] = '999999';
ob_start();
Brief::render();
$html = ob_get_clean();
$check( 'Tab (unknown pick): says it is not in the last scan', false !== strpos( $html, 'not in your last scan' ) );
$tabs = apply_filters( 'airworthy_admin_tabs', array() );
$check( 'Tab: it appears in the Airworthy tabs', isset( $tabs['brief'] ) );
$check( 'Tab: the copy script ships with the plugin', is_readable( AIRWORTHY_PRO_DIR . 'assets/brief.js' ) );

$wpdb->delete( $t['issues'], array( 'scan_id' => $scan ) );
$wpdb->delete( $t['components'], array( 'scan_id' => $scan ) );
$wpdb->delete( $t['scans'], array( 'id' => $scan ) );
delete_site_option( Monitor::OPTION );
if ( null !== $saved[ Monitor::OPTION ] ) {
	update_site_option( Monitor::OPTION, $saved[ Monitor::OPTION ] );
}
echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
