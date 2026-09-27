<?php
/**
 * REST endpoint checks, run inside WordPress: wp eval-file support/rest-test.php
 * Calls every route as an administrator, an editor and a logged-out visitor.
 */
$pass = 0;
$fail = 0;

$call = static function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/airworthy/v1' . $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data(), $response->get_headers() );
};
$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	$ok ? $pass++ : $fail++;
	printf( "%s %-62s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail );
};

global $wpdb;
$editor = get_user_by( 'login', 'sua_editor' );
$latest = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->base_prefix}airworthy_scans" );
$comp   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}airworthy_components WHERE scan_id = %d AND slug = 'fx-fake-guard'", $latest ) );
if ( ! $comp ) {
	$comp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}airworthy_components WHERE scan_id = %d AND slug = 'updraftplus'", $latest ) );
}

// --- Permissions: every route refuses editors and visitors.
$routes = array(
	array( 'GET', '/scans/current', array() ),
	array( 'GET', "/scans/$latest", array() ),
	array( 'GET', "/scans/$latest/components/$comp/issues", array() ),
	array( 'POST', '/scans', array( 'target' => '8.4' ) ),
	array( 'POST', "/scans/$latest/cancel", array() ),
	array( 'POST', "/scans/$latest/nudge", array() ),
	array( 'POST', "/scans/$latest/components/$comp/rescan", array() ),
	array( 'GET', '/estimate', array() ),
);
foreach ( array( 'editor' => $editor ? $editor->ID : 0, 'logged out' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	foreach ( $routes as $r ) {
		list( $status ) = $call( $r[0], $r[1], $r[2] );
		$check( "$who: {$r[0]} {$r[1]} refused", in_array( $status, array( 401, 403 ), true ), "HTTP $status" );
	}
}

// --- Administrator.
wp_set_current_user( 1 );

list( $status, $data ) = $call( 'GET', "/scans/$latest" );
$check( 'admin: GET /scans/{id} returns summary + components', 200 === $status && isset( $data['components'][0]['verdict'], $data['counts']['blocker'] ), "HTTP $status, " . count( (array) ( $data['components'] ?? array() ) ) . ' components' );
$first = $data['components'][0]['verdict'] ?? '';
$check( 'admin: components sorted most serious first', in_array( $first, array( 'blocker', 'unknown' ), true ), "first verdict: $first" );
$json = wp_json_encode( $data );
$check( 'admin: no absolute server paths in scan response', false === strpos( $json, WP_CONTENT_DIR ) && false === strpos( $json, ABSPATH ) );

list( $status, $items, $headers ) = $call( 'GET', "/scans/$latest/components/$comp/issues", array( 'per_page' => 2 ) );
$check( 'admin: issues paged (per_page=2) with totals', 200 === $status && count( $items ) <= 2 && isset( $headers['X-WP-Total'] ), "HTTP $status, total " . ( $headers['X-WP-Total'] ?? '?' ) );
$check( 'admin: issue has file, line, severity, context, rule, message', isset( $items[0]['file'], $items[0]['line'], $items[0]['severity'], $items[0]['context'], $items[0]['rule'], $items[0]['message'] ) );
$check( 'admin: issue file paths are relative', isset( $items[0]['file'] ) && '/' !== substr( $items[0]['file'], 0, 1 ), $items[0]['file'] ?? '' );

list( $status, $items ) = $call( 'GET', "/scans/$latest/components/$comp/issues", array( 'severity' => array( 'error' ), 'context' => array( 'plain' ) ) );
$only = array_unique( array_map( static function ( $i ) { return $i['severity'] . '/' . $i['context']; }, (array) $items ) );
$check( 'admin: filter severity=error&context=plain', 200 === $status && array( 'error/plain' ) === array_values( $only ), implode( ',', $only ) );

list( $status ) = $call( 'GET', "/scans/$latest/components/999999/issues" );
$check( 'admin: unknown component -> 404', 404 === $status, "HTTP $status" );
list( $status ) = $call( 'GET', '/scans/999999' );
$check( 'admin: unknown scan -> 404', 404 === $status, "HTTP $status" );
list( $status ) = $call( 'POST', '/scans', array( 'target' => '7.4' ) );
$check( 'admin: POST /scans with unsupported target -> 400', 400 === $status, "HTTP $status" );

// Estimate and single-plugin rescan (on the latest finished scan, with a small plugin).
list( $status, $data ) = $call( 'GET', '/estimate' );
$check( 'admin: GET /estimate returns files and minutes', 200 === $status && $data['files'] > 0 && $data['minutes_high'] >= $data['minutes_low'], "HTTP $status, " . ( $data['files'] ?? 0 ) . ' files, ' . ( $data['minutes_low'] ?? '?' ) . '-' . ( $data['minutes_high'] ?? '?' ) . ' min' );
$done = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->base_prefix}airworthy_scans WHERE status = 'complete'" );
$ak   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}airworthy_components WHERE scan_id = %d AND slug = 'akismet'", $done ) );
list( $status, $data ) = $call( 'POST', "/scans/$done/components/$ak/rescan" );
$check( 'admin: rescan one plugin re-opens the finished scan', 200 === $status && 'running' === $data['status'], "HTTP $status, scan $done " . ( $data['status'] ?? '' ) );
list( $status ) = $call( 'POST', "/scans/$done/components/$ak/rescan" );
$check( 'admin: rescan while a scan runs -> 409', 409 === $status, "HTTP $status" );
for ( $i = 0; $i < 5 && 'complete' !== $wpdb->get_var( "SELECT status FROM {$wpdb->base_prefix}airworthy_scans WHERE id = $done" ); $i++ ) {
	Airworthy\Scan\Queue::run_batch( $done );
	Airworthy\Wporg\Fetcher::run( $done );
}
$row = $wpdb->get_row( "SELECT status, verdict, files_done, files_total FROM {$wpdb->base_prefix}airworthy_components WHERE id = $ak" );
$check( 'admin: rescanned plugin finishes with a verdict', 'done' === $row->status && $row->verdict && (int) $row->files_done === (int) $row->files_total, "$row->status, $row->verdict, $row->files_done/$row->files_total files" );
list( $status ) = $call( 'POST', "/scans/$done/components/999999/rescan" );
$check( 'admin: rescan unknown component -> 404', 404 === $status, "HTTP $status" );

list( $status, $data ) = $call( 'POST', '/scans', array( 'target' => '8.3' ) );
$new = (int) ( $data['id'] ?? 0 );
$check( 'admin: POST /scans starts a scan -> 201', 201 === $status && $new > 0 && 'queued' === $data['status'], "HTTP $status, scan $new" );
list( $status ) = $call( 'POST', '/scans', array( 'target' => '8.4' ) );
$check( 'admin: second scan while one runs -> 409', 409 === $status, "HTTP $status" );
list( $status, $data ) = $call( 'GET', '/scans/current' );
$check( 'admin: GET /scans/current returns the running scan', 200 === $status && $new === (int) $data['id'], 'scan ' . ( $data['id'] ?? '?' ) . ' ' . ( $data['status'] ?? '' ) );
add_filter( 'airworthy_batch_seconds', static function () { return 3.0; } );
list( $status, $data ) = $call( 'POST', "/scans/$new/nudge" );
$check( 'admin: nudge runs a batch (progress moves)', 200 === $status && $data['progress']['files_done'] > 0, "HTTP $status, " . ( $data['progress']['files_done'] ?? 0 ) . '/' . ( $data['progress']['files_total'] ?? 0 ) . ' files' );
list( $status ) = $call( 'POST', "/scans/$new/components/$comp/rescan" );
$check( 'admin: rescan inside an unfinished scan -> refused', in_array( $status, array( 404, 409 ), true ), "HTTP $status" );
list( $status, $data ) = $call( 'POST', "/scans/$new/cancel" );
$check( 'admin: cancel -> cancelled', 200 === $status && 'cancelled' === $data['status'], "HTTP $status, " . ( $data['status'] ?? '' ) );
$check( 'admin: summary carries a CSV export link (no HTML-escaped &amp;)', isset( $data['export_url'] ) && false === strpos( $data['export_url'], '&amp;' ) && false !== strpos( $data['export_url'], '_wpnonce=' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
