<?php
/**
 * Airworthy Pro monitoring checks (history, what changed, alerts, schedule, the tab).
 *
 * Needs a site with Airworthy and Airworthy Pro active and a licence that allows Pro features
 * (the local test site). Run: wp eval-file tests/pro-monitor.php
 * It saves and restores the monitoring options, and sends no real email.
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\Alerts;
use AirworthyPro\History;
use AirworthyPro\Monitor;
use AirworthyPro\MonitorTab;

if ( ! class_exists( 'AirworthyPro\\Monitor' ) ) {
	WP_CLI::error( 'Airworthy Pro is not active.' );
}

$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};

$saved = array();
foreach ( array( History::OPTION, Monitor::OPTION, Monitor::STATE ) as $name ) {
	$saved[ $name ] = get_site_option( $name, null );
	delete_site_option( $name );
	delete_option( $name );
}

// Capture mail instead of sending it.
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

// ── The diff, with made-up snapshots ──────────────────────────────────────────────────────────
$comp = static function ( array $rows ) {
	$out = array();
	foreach ( $rows as $slug => $r ) {
		$out[ 'plugin|' . $slug ] = array( 'name' => ucfirst( $slug ), 'version' => $r[0], 'verdict' => $r[1] );
	}
	return $out;
};
$snap = static function ( $target, array $rows ) use ( $comp ) {
	$c = $comp( $rows );
	return array( 'target' => $target, 'components' => $c, 'fp' => History::fingerprint( $target, $c ) );
};
$old = $snap( '8.4', array( 'a' => array( '1.0', 'ready' ), 'b' => array( '1.0', 'warnings' ), 'c' => array( '1.0', 'blocker' ), 'd' => array( '1.0', 'ready' ), 'gone' => array( '1.0', 'ready' ) ) );
$new = $snap( '8.4', array( 'a' => array( '1.1', 'blocker' ), 'b' => array( '1.0', 'ready' ), 'c' => array( '1.0', 'ready' ), 'd' => array( '1.0', 'warnings' ), 'fresh' => array( '1.0', 'blocker' ) ) );
$diff = History::diff( $old, $new );
$names = static function ( $rows ) {
	$n = wp_list_pluck( $rows, 'name' );
	sort( $n );
	return implode( ',', $n );
};
$check( 'Diff: new Blockers are the plugin that broke and the new one', 'A,Fresh' === $names( $diff['blockers'] ), $names( $diff['blockers'] ) );
$check( 'Diff: a Blocker that cleared is "fixed"', 'C' === $names( $diff['fixed'] ), $names( $diff['fixed'] ) );
$check( 'Diff: ready to warnings is "worse"', 'D' === $names( $diff['worse'] ), $names( $diff['worse'] ) );
$check( 'Diff: warnings to ready is "better"', 'B' === $names( $diff['better'] ), $names( $diff['better'] ) );
$check( 'Diff: added and removed', 'Fresh' === $names( $diff['added'] ) && 'Gone' === $names( $diff['removed'] ) );
$check( 'Diff: a version bump alone is noted as updated, not as a change', 'A' === $names( $diff['updated'] ) );
$same = History::diff( $old, $snap( '8.4', array( 'a' => array( '1.0', 'ready' ), 'b' => array( '1.1', 'warnings' ), 'c' => array( '1.0', 'blocker' ), 'd' => array( '1.0', 'ready' ), 'gone' => array( '1.0', 'ready' ) ) ) );
$check( 'Diff: only version numbers changed means nothing worth knowing', ! History::has_changes( $same ) );
$other = History::diff( $old, $snap( '8.5', array( 'a' => array( '1.0', 'blocker' ) ) ) );
$check( 'Diff: a different target PHP is flagged and not compared', $other['target_changed'] && ! History::has_changes( $other ) );
$check( 'Diff: the first snapshot has nothing to compare', ! History::has_changes( History::diff( null, $new ) ) );

// ── Settings ─────────────────────────────────────────────────────────────────────────────────
$clean = Monitor::clean( array( 'enabled' => '1', 'frequency' => 'hourly', 'target' => '7.4', 'recipients' => "a@example.com, bad, A@example.com; b@example.com\nc@example.com d@example.com e@example.com f@example.com" ) );
$check( 'Settings: an unknown frequency falls back to weekly', 'weekly' === $clean['frequency'] );
$check( 'Settings: an unknown target falls back to auto', 'auto' === $clean['target'] );
$check( 'Settings: recipients are cleaned, de-duplicated and capped at five', 'a@example.com, b@example.com, c@example.com, d@example.com, e@example.com' === $clean['recipients'], $clean['recipients'] );
$check( 'Settings: alerts for Blockers are on by default, Warnings off', Monitor::defaults()['alert_blockers'] && ! Monitor::defaults()['alert_warnings'] );

Monitor::save( Monitor::clean( array( 'enabled' => '1', 'frequency' => 'daily', 'target' => '8.4', 'alert_blockers' => '1', 'recipients' => 'owner@example.com' ) ) );
$check( 'Schedule: turning monitoring on schedules the daily tick', (bool) wp_next_scheduled( Monitor::TICK ) );

// ── A real scan, then history and the email ───────────────────────────────────────────────────
$finish = static function ( $scan_id ) {
	for ( $i = 0; $i < 400; $i++ ) {
		global $wpdb;
		$t      = \Airworthy\Installer::tables();
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		if ( in_array( $status, array( 'complete', 'failed', 'cancelled' ), true ) ) {
			return $status;
		}
		\Airworthy\Scan\Queue::run_batch( $scan_id );
	}
	return 'timeout';
};

$scan_id = Monitor::run( 'scheduled' );
$check( 'Run: a check starts a scan', is_int( $scan_id ) && $scan_id > 0, is_wp_error( $scan_id ) ? $scan_id->get_error_message() : '' );
$check( 'Run: it remembers the scan it is waiting for', Monitor::state()['pending']['scan_id'] === $scan_id );
$check( 'Run: a follow-up check is scheduled in case the finish signal is missed', (bool) wp_next_scheduled( Monitor::CHECK ) );
$status = $finish( $scan_id );
$check( 'Run: the scan completes', 'complete' === $status, $status );
$hist = History::all();
$check( 'History: the finished scan became the first snapshot, marked scheduled', 1 === count( $hist ) && 'scheduled' === $hist[0]['trigger'] && $hist[0]['scan_id'] === $scan_id, wp_json_encode( array_column( $hist, 'trigger' ) ) );
$check( 'History: a snapshot lists every plugin and theme with a verdict', ! empty( $hist[0]['components'] ) && count( $hist[0]['components'] ) === array_sum( array_intersect_key( $hist[0]['counts'], array_flip( array( 'blocker', 'unknown', 'suppressed', 'guarded', 'warnings', 'ready' ) ) ) ), wp_json_encode( $hist[0]['counts'] ) . ' vs ' . count( $hist[0]['components'] ) );
$check( 'Run: pending is cleared once processed', null === Monitor::state()['pending'] );
$check( 'Alert: the first scan has nothing to compare, so no email', 0 === count( $mails ), (string) count( $mails ) );

// Same results again: no new snapshot, no email.
$again = Monitor::run( 'scheduled' );
$finish( $again );
$check( 'History: an identical scan adds no new line', 1 === count( History::all() ) );
$check( 'Alert: identical results send no email', 0 === count( $mails ) );

// Turn one component of the last scan into a Blocker in the table, then process that scan as if
// a scheduled check had just finished: the email path, with a real scan's data.
global $wpdb;
$tables = \Airworthy\Installer::tables();
$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, verdict FROM %i WHERE scan_id = %d AND status = 'done' AND verdict <> 'blocker' ORDER BY id", $tables['components'], $again ) );
$check( 'Email test setup: the scan has components to flip', count( $rows ) >= 2 );
if ( count( $rows ) >= 2 ) {
	$flip = static function ( $row, $trigger ) use ( $wpdb, $tables, $again ) {
		$wpdb->update( $tables['components'], array( 'verdict' => 'blocker' ), array( 'id' => $row->id ) );
		Monitor::set_state( array( 'pending' => array( 'scan_id' => $again, 'trigger' => $trigger, 'started' => time(), 'checks' => 0 ) ) );
		Monitor::process( $again );
	};
	$flip( $rows[0], 'scheduled' );
	$latest = History::latest();
	$check( 'History: the changed result is added with what changed', 2 === count( History::all() ) && 1 === count( $latest['changes']['blockers'] ) && $rows[0]->name === $latest['changes']['blockers'][0]['name'], wp_json_encode( $latest['changes']['blockers'] ?? null ) );
	$check( 'Alert: a new Blocker on a scheduled check sends one email to the owner', 1 === count( $mails ) && array( 'owner@example.com' ) === (array) $mails[0]['to'], (string) count( $mails ) );
	$m = $mails ? $mails[0] : array( 'subject' => '', 'message' => '' );
	$check( 'Alert: the subject says what happened, with the PHP version', false !== strpos( $m['subject'], 'Blocker' ) && false !== strpos( $m['subject'], $latest['target'] ), $m['subject'] );
	$check( 'Alert: the email names the plugin and links to the Monitoring tab', false !== strpos( $m['message'], esc_html( $rows[0]->name ) ) && false !== strpos( $m['message'], 'tab=monitor' ) );
	$check( 'Alert: the state remembers when the last alert went', Monitor::state()['last_alert'] > 0 );

	$mails = array();
	$flip( $rows[1], 'manual' );
	$check( 'Alert: a check run by hand is saved but not emailed', 3 === count( History::all() ) && 0 === count( $mails ) && 'manual' === History::latest()['trigger'], count( History::all() ) . '/' . count( $mails ) );

	// Alerts off: a new Blocker is recorded but not emailed.
	Monitor::save( Monitor::clean( array( 'enabled' => '1', 'alert_blockers' => '', 'recipients' => 'owner@example.com' ) ) );
	if ( count( $rows ) >= 3 ) {
		$mails = array();
		$flip( $rows[2], 'scheduled' );
		$check( 'Alert: with Blocker alerts switched off nothing is sent', 4 === count( History::all() ) && 0 === count( $mails ), count( History::all() ) . '/' . count( $mails ) );
	}
	Monitor::save( Monitor::clean( array( 'enabled' => '1', 'alert_blockers' => '1', 'recipients' => 'owner@example.com' ) ) );
	// Put the table back.
	foreach ( $rows as $row ) {
		$wpdb->update( $tables['components'], array( 'verdict' => $row->verdict ), array( 'id' => $row->id ) );
	}
}

// ── The test email ───────────────────────────────────────────────────────────────────────────
$mails = array();
$ok = Alerts::send_test();
$check( 'Test email: sends one message to the chosen address', $ok && 1 === count( $mails ) );
$check( 'Test email: has a plain-text version', ! empty( $mails ) );

// ── Support reminders ────────────────────────────────────────────────────────────────────────
$check( 'Support: the reminder thresholds are 90, 30 and 7 days', array( 90, 30, 7 ) === Monitor::SUPPORT_REMINDERS );

// ── The tab ──────────────────────────────────────────────────────────────────────────────────
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$_GET['page'] = 'airworthy';
$_GET['tab']  = 'monitor';
ob_start();
MonitorTab::render();
$html = ob_get_clean();
$check( 'Tab: renders the status, settings, try-it and history sections', false !== strpos( $html, 'Monitoring' ) && false !== strpos( $html, 'name="airworthy_pro_monitor[frequency]"' ) && false !== strpos( $html, 'Send a test email' ) && false !== strpos( $html, 'airworthy-pro-history' ) );
$check( 'Tab: the history list shows the snapshots', false !== strpos( $html, 'airworthy-pro-history-list' ) );
$check( 'Tab: every form carries a nonce', 3 === substr_count( $html, 'name="_wpnonce"' ), (string) substr_count( $html, 'name="_wpnonce"' ) );
$tabs = apply_filters( 'airworthy_admin_tabs', array() );
$check( 'Tab: it appears in the Airworthy tabs', isset( $tabs['monitor'] ) );

// ── Turning off and cleaning up ──────────────────────────────────────────────────────────────
Monitor::save( Monitor::clean( array( 'enabled' => '' ) ) );
$check( 'Schedule: turning monitoring off removes the tick', ! wp_next_scheduled( Monitor::TICK ) );
Monitor::deactivate();
$check( 'Deactivate: no jobs left', ! wp_next_scheduled( Monitor::TICK ) && ! wp_next_scheduled( Monitor::CHECK ) );

foreach ( $saved as $name => $value ) {
	delete_site_option( $name );
	delete_option( $name );
	if ( null !== $value ) {
		update_site_option( $name, $value );
	}
}

echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
