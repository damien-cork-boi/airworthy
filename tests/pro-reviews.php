<?php
/**
 * Airworthy Pro "Reviews" checks.
 *
 * Needs Airworthy and Airworthy Pro (0.7.0) active. Adds a finished scan of its own and removes it.
 * Run: wp eval-file tests/pro-reviews.php
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\Reviews;

if ( ! class_exists( 'AirworthyPro\\Reviews' ) ) {
	WP_CLI::error( 'Airworthy Pro (0.7.0) is not active.' );
}
$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};
$saved = get_site_option( Reviews::OPTION, null );
delete_site_option( Reviews::OPTION );
delete_option( Reviews::OPTION );

global $wpdb;
$t   = \Airworthy\Installer::tables();
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( $t['scans'], array( 'target_php' => '8.4', 'from_php' => '7.4', 'status' => 'complete', 'created_at' => $now, 'finished_at' => $now ) );
$scan = (int) $wpdb->insert_id;
foreach ( array(
	array( 'acme-forms', 'Acme Forms', '2.1', 'blocker' ),
	array( 'old-slider', 'Old Slider', '1.0', 'unknown' ),
	array( 'tidy-seo', 'Tidy SEO', '4.0', 'warnings' ),
	array( 'all-good', 'All Good', '1.0', 'ready' ),
) as $c ) {
	$wpdb->insert( $t['components'], array( 'scan_id' => $scan, 'type' => 'plugin', 'slug' => $c[0], 'name' => $c[1], 'version' => $c[2], 'rel_path' => $c[0], 'status' => 'done', 'verdict' => $c[3] ) );
}

$items = Reviews::items();
$names = wp_list_pluck( $items['rows'], 'name' );
$check( 'Items: plugins with something to look at, most serious first, not the ready ones', array( 'Acme Forms', 'Old Slider', 'Tidy SEO' ) === $names, implode( ',', $names ) );
$check( 'Counts: nothing reviewed yet means everything is open', 3 === Reviews::counts( $items['rows'] )['open'] );

// Saving.
$ok = Reviews::save( array( 'type' => 'plugin', 'slug' => 'acme-forms', 'status' => 'waiting', 'note' => "  Developer says 2.2 in March.\n<script>x</script>", 'client' => '1', 'version' => '2.1', 'verdict' => 'blocker', 'target' => '8.4' ), 1 );
$r  = Reviews::get( 'plugin', 'acme-forms' );
$check( 'Save: a review is stored with its status, version and who wrote it', $ok && 'waiting' === $r['status'] && '2.1' === $r['version'] && 1 === $r['by'] && $r['at'] > 0 );
$check( 'Save: the note is cleaned of markup', false === strpos( $r['note'], '<' ) && false !== strpos( $r['note'], 'Developer says 2.2 in March.' ), $r['note'] );
Reviews::save( array( 'type' => 'plugin', 'slug' => 'tidy-seo', 'status' => 'accepted', 'note' => str_repeat( 'a', 900 ), 'version' => '4.0' ), 1 );
$check( 'Save: a long note is cut', mb_strlen( Reviews::get( 'plugin', 'tidy-seo' )['note'] ) <= Reviews::NOTE_MAX );
$check( 'Save: an unknown status removes the review instead of storing nonsense', false === Reviews::save( array( 'type' => 'plugin', 'slug' => 'old-slider', 'status' => 'bogus' ), 1 ) && null === Reviews::get( 'plugin', 'old-slider' ) );
$check( 'Save: a review with no slug is refused', false === Reviews::save( array( 'status' => 'fixing' ), 1 ) );
Reviews::save( array( 'type' => 'plugin', 'slug' => 'old-slider', 'status' => 'fixing', 'version' => '1.0' ), 1 );
Reviews::save( array( 'type' => 'plugin', 'slug' => 'old-slider', 'status' => '' ), 1 );
$check( 'Save: an empty status clears a review', null === Reviews::get( 'plugin', 'old-slider' ) );

$counts = Reviews::counts( Reviews::items()['rows'] );
$check( 'Counts: waiting, accepted and open are counted', 1 === $counts['waiting'] && 1 === $counts['accepted'] && 1 === $counts['open'] && 0 === $counts['stale'], wp_json_encode( $counts ) );

// Out of date after an update.
$wpdb->update( $t['components'], array( 'version' => '2.2' ), array( 'scan_id' => $scan, 'slug' => 'acme-forms' ) );
$counts = Reviews::counts( Reviews::items()['rows'] );
$check( 'Stale: a review for an older version counts as out of date, not as waiting', 1 === $counts['stale'] && 0 === $counts['waiting'], wp_json_encode( $counts ) );

// What the client report may say.
$wpdb->update( $t['components'], array( 'version' => '2.1' ), array( 'scan_id' => $scan, 'slug' => 'acme-forms' ) );
$note = Reviews::client_note( array( 'type' => 'plugin', 'slug' => 'acme-forms', 'version' => '2.1' ) );
$check( 'Client report: a note marked for the client shows its status and text', false !== strpos( $note, 'Waiting for the developer' ) && false !== strpos( $note, 'Developer says 2.2 in March.' ), $note );
$check( 'Client report: a note not marked for the client stays private', '' === Reviews::client_note( array( 'type' => 'plugin', 'slug' => 'tidy-seo', 'version' => '4.0' ) ) );
$check( 'Client report: nothing is shown once the plugin has been updated', '' === Reviews::client_note( array( 'type' => 'plugin', 'slug' => 'acme-forms', 'version' => '2.2' ) ) );
$check( 'Client report: nothing for a plugin with no review', '' === Reviews::client_note( array( 'type' => 'plugin', 'slug' => 'nobody', 'version' => '1' ) ) );
$check( 'Client report: the report calls it', false !== strpos( (string) file_get_contents( AIRWORTHY_PRO_DIR . 'src/Report.php' ), 'Reviews::client_note' ) );

// The tab.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$_GET['page'] = 'airworthy';
$_GET['tab']  = 'reviews';
ob_start();
Reviews::render();
$html = ob_get_clean();
$check( 'Tab: one form per plugin, each with a nonce, a status, a note and the client tick', 3 === substr_count( $html, '<form' ) && 3 === substr_count( $html, 'name="_wpnonce"' ) && 3 === substr_count( $html, 'name="client"' ) && 3 === substr_count( $html, 'name="note"' ), substr_count( $html, '<form' ) . '/' . substr_count( $html, 'name="_wpnonce"' ) );
$check( 'Tab: shows the summary and the saved note', false !== strpos( $html, '1 accepted' ) && false !== strpos( $html, 'Developer says 2.2 in March.' ) );
$wpdb->update( $t['components'], array( 'version' => '2.2' ), array( 'scan_id' => $scan, 'slug' => 'acme-forms' ) );
ob_start();
Reviews::render();
$html = ob_get_clean();
$check( 'Tab: an out-of-date review says which version it was for', false !== strpos( $html, 'Out of date: this review is for version 2.1' ) );
$check( 'Tab: it appears in the Airworthy tabs', isset( apply_filters( 'airworthy_admin_tabs', array() )['reviews'] ) );

$wpdb->delete( $t['components'], array( 'scan_id' => $scan ) );
$wpdb->delete( $t['scans'], array( 'id' => $scan ) );
delete_site_option( Reviews::OPTION );
delete_option( Reviews::OPTION );
if ( null !== $saved ) {
	update_site_option( Reviews::OPTION, $saved );
}
echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
