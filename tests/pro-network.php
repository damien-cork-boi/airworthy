<?php
/**
 * Airworthy Pro "Network" overview checks.
 *
 * The logic checks run anywhere. The checks against a real network run only on multisite, and
 * expect the test network from the release notes (shop and events with fx-implode, a finished scan).
 * Run: wp eval-file tests/pro-network.php
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\Network;

if ( ! class_exists( 'AirworthyPro\\Network' ) ) {
	WP_CLI::error( 'Airworthy Pro (0.8.0) is not active.' );
}
$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};

// ── The logic, with made-up sites ────────────────────────────────────────────────────────────
$check( 'Key: a plugin in a folder and a single-file plugin are named the way a scan names them', 'plugin|acme' === Network::plugin_key( 'acme/acme.php' ) && 'plugin|hello' === Network::plugin_key( 'hello.php' ) );
$components = array(
	'plugin|acme'   => array( 'name' => 'Acme Forms', 'version' => '2.1', 'verdict' => 'blocker' ),
	'plugin|hello'  => array( 'name' => 'Hello', 'version' => '1.0', 'verdict' => 'ready' ),
	'plugin|slider' => array( 'name' => 'Slider', 'version' => '3.0', 'verdict' => 'warnings' ),
	'plugin|seo'    => array( 'name' => 'Tidy SEO', 'version' => '4.0', 'verdict' => 'unknown' ),
	'theme|twenty'  => array( 'name' => 'Twenty', 'version' => '1.0', 'verdict' => 'ready' ),
);
$sites = array(
	array( 'id' => 1, 'name' => 'Alpha', 'url' => 'https://a.example', 'plugins' => array( 'acme/acme.php', 'hello.php', 'other/other.php' ), 'stylesheet' => 'twenty', 'template' => 'twenty' ),
	array( 'id' => 2, 'name' => 'Beta', 'url' => 'https://b.example', 'plugins' => array( 'acme/acme.php', 'slider/slider.php' ), 'stylesheet' => '', 'template' => '' ),
	array( 'id' => 3, 'name' => '=cmd()', 'url' => 'https://c.example', 'plugins' => array(), 'stylesheet' => '', 'template' => '' ),
);
$view  = Network::build( $sites, array( 'seo/seo.php' ), $components );
$byname = array();
foreach ( $view['sites'] as $s ) {
	$byname[ $s['name'] ] = $s;
}
$check( 'Sites: ordered least ready first', array( '=cmd()', 'Beta', 'Alpha' ) === array_column( $view['sites'], 'name' ), wp_json_encode( array_column( $view['sites'], 'name' ) ) );
$check( 'Sites: a network-activated plugin counts on every site, a theme once even when it is its own parent', 4 === $byname['Alpha']['total'] && 3 === $byname['Beta']['total'] && 1 === $byname['=cmd()']['total'], wp_json_encode( array_column( $view['sites'], 'total' ) ) );
$check( 'Sites: readiness is the share with no Blocker and nothing unchecked, rounded down', 50 === $byname['Alpha']['readiness'] && 33 === $byname['Beta']['readiness'] && 0 === $byname['=cmd()']['readiness'], wp_json_encode( array_column( $view['sites'], 'readiness' ) ) );
$check( 'Sites: a plugin that is not in the last scan is left out', 4 === $byname['Alpha']['total'] );
$check( 'Sites: each lists what needs attention, worst first', array( 'Acme Forms', 'Tidy SEO' ) === array_column( $byname['Alpha']['issues'], 'name' ) );
$check( 'Totals: three sites, two with a Blocker', 3 === $view['totals']['sites'] && 2 === $view['totals']['with_blocker'] && 1 === $view['totals']['ready_sites'] );
$p = $view['plugins'];
$check( 'Fix first: a Blocker before an Unknown before warnings', array( 'Acme Forms', 'Tidy SEO', 'Slider' ) === array_column( $p, 'name' ), wp_json_encode( array_column( $p, 'name' ) ) );
$check( 'Fix first: how many sites each is on, and which', 2 === $p[0]['sites'] && array( 'Alpha', 'Beta' ) === $p[0]['site_names'] && 3 === $p[1]['sites'] && $p[1]['network'] && ! $p[0]['network'] );
$empty = Network::build( array(), array(), array() );
$check( 'Empty: no sites and no scan give an empty overview, not an error', array() === $empty['sites'] && array() === $empty['plugins'] && 0 === $empty['totals']['sites'] );
$csv = Network::csv_rows( $view );
$check( 'CSV: a header and one row per site', 4 === count( $csv ) && 'Site' === $csv[0][0] );
$check( 'CSV: a site name that a spreadsheet would run as a formula is neutralised', "'=cmd()" === $csv[1][0] && "'=cmd()" === Network::safe_cell( '=cmd()' ) && 'plain' === Network::safe_cell( 'plain' ) );

// ── The tab exists on a network only ─────────────────────────────────────────────────────────
$tabs = apply_filters( 'airworthy_admin_tabs', array() );
$check( is_multisite() ? 'Tab: shown on a multisite network' : 'Tab: not shown on a single site', is_multisite() === isset( $tabs['network'] ) );

// ── A real network ───────────────────────────────────────────────────────────────────────────
if ( is_multisite() ) {
	$read = Network::read_sites();
	$ids  = wp_list_pluck( $read['sites'], 'id' );
	$check( 'Network: every site is read', count( $read['sites'] ) >= 4 && ! $read['truncated'], wp_json_encode( $ids ) );
	$by = array();
	foreach ( $read['sites'] as $s ) {
		$by[ untrailingslashit( preg_replace( '#^https?://[^/]+#', '', $s['url'] ) ) ?: '/' ] = $s;
	}
	$check( 'Network: each site\'s own active plugins are read', isset( $by['/shop'] ) && in_array( 'fx-implode/fx-implode.php', $by['/shop']['plugins'], true ) && in_array( 'fx-implode/fx-implode.php', $by['/events']['plugins'], true ) && array() === $by['/news']['plugins'], wp_json_encode( array_map( static function ( $s ) { return $s['plugins']; }, $by ) ) );
	$check( 'Network: network-activated plugins are read', in_array( 'airworthy/airworthy.php', Network::network_files(), true ) && in_array( 'fx-ready/fx-ready.php', Network::network_files(), true ) );

	$over = Network::overview();
	$check( 'Overview: built from the last scan', null !== $over['scan'] && $over['view']['totals']['sites'] >= 4 );
	$names = array();
	foreach ( $over['view']['sites'] as $s ) {
		$names[ untrailingslashit( preg_replace( '#^https?://[^/]+#', '', $s['url'] ) ) ?: '/' ] = $s;
	}
	$blockers = static function ( $s ) {
		return wp_list_pluck( array_filter( $s['issues'], static function ( $i ) { return 'blocker' === $i['verdict']; } ), 'name' );
	};
	$check( 'Overview: the sites that use the broken plugin have a Blocker, the others do not', in_array( 'FX Implode', $blockers( $names['/shop'] ), true ) && in_array( 'FX Implode', $blockers( $names['/events'] ), true ) && array() === $blockers( $names['/news'] ) && array() === $blockers( $names['/'] ), wp_json_encode( array_map( $blockers, $names ) ) );
	$check( 'Overview: a site with no problem plugin is fully ready', 100 === $names['/news']['readiness'] && 100 === $names['/']['readiness'] || null !== $names['/news']['readiness'], wp_json_encode( $names['/news']['readiness'] ?? null ) );
	$fix = wp_list_pluck( $over['view']['plugins'], 'sites', 'name' );
	$check( 'Overview: the broken plugin is listed as on 2 sites', 2 === ( $fix['FX Implode'] ?? 0 ), wp_json_encode( $fix ) );

	wp_set_current_user( get_super_admins() ? get_user_by( 'login', get_super_admins()[0] )->ID : 1 );
	$_GET['page'] = 'airworthy';
	$_GET['tab']  = 'network';
	ob_start();
	Network::render();
	$html = ob_get_clean();
	$check( 'Tab: shows the summary, the fix-first list and the sites, with a CSV button that has a nonce', false !== strpos( $html, 'Fix these first' ) && false !== strpos( $html, 'FX Implode' ) && false !== strpos( $html, 'Sites, least ready first' ) && false !== strpos( $html, 'Download as CSV' ) && false !== strpos( $html, 'name="_wpnonce"' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
}

echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
