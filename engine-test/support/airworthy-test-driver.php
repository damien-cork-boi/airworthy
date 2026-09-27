<?php
/**
 * TEST SITE ONLY (not part of the plugin). Drives Airworthy's queue from web requests with
 * shared-hosting limits, the way WP-Cron would. Localhost only.
 *
 * GET /?airworthy_test=run  → runs Action Scheduler's queue once (as WP-Cron does), returns scan status JSON.
 *
 * Knobs, set with WP-CLI: wp option update airworthy_test_knobs '{"nobudget":1,"seconds":10,"recovery":5}'  (memory cap: echo 64M > .aw-mem)
 */
if ( ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1', '' ), true ) || ( '' === ( $_SERVER['REMOTE_ADDR'] ?? '' ) && ! defined( 'WP_CLI' ) ) ) {
	return;
}

$airworthy_knobs = get_option( 'airworthy_test_knobs', array() );
$airworthy_knobs = is_array( $airworthy_knobs ) ? $airworthy_knobs : (array) json_decode( (string) $airworthy_knobs, true );

// Memory cap: see the .aw-mem switch in wp-config.php (it must apply before WordPress loads).
if ( ! empty( $airworthy_knobs['nobudget'] ) ) {
	add_filter( 'airworthy_memory_estimate', '__return_zero' ); // Force crashes on big files.
}
if ( ! empty( $airworthy_knobs['seconds'] ) ) {
	add_filter( 'airworthy_batch_seconds', static function () use ( $airworthy_knobs ) {
		return (float) $airworthy_knobs['seconds'];
	} );
}
if ( ! empty( $airworthy_knobs['only'] ) ) {
	// Limit scans to components whose slug starts with this prefix (e.g. "fx-").
	add_filter( 'airworthy_scan_components', static function ( $components ) use ( $airworthy_knobs ) {
		return array_filter( $components, static function ( $c ) use ( $airworthy_knobs ) {
			return 0 === strpos( $c['slug'], $airworthy_knobs['only'] );
		} );
	} );
}
// WordPress.org lookups: log every outgoing request (URL + User-Agent), optionally simulate
// WordPress.org being unreachable, or answer canned responses for made-up slugs.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $airworthy_knobs ) {
		if ( false === strpos( $url, 'api.wordpress.org/plugins/info' ) ) {
			return $pre;
		}
		$log   = (array) get_option( 'airworthy_test_http_log', array() );
		$log[] = array( 'url' => $url, 'user-agent' => isset( $args['user-agent'] ) ? $args['user-agent'] : '', 'headers' => isset( $args['headers'] ) ? $args['headers'] : array() );
		update_option( 'airworthy_test_http_log', array_slice( $log, -200 ), false );
		if ( ! empty( $airworthy_knobs['offline'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.wordpress.org (simulated)' );
		}
		if ( ! empty( $airworthy_knobs['wporg_mock'] ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$slug = isset( $q['request']['slug'] ) ? $q['request']['slug'] : '';
			if ( isset( $airworthy_knobs['wporg_mock'][ $slug ] ) ) {
				return array( 'headers' => array(), 'body' => wp_json_encode( $airworthy_knobs['wporg_mock'][ $slug ] ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
			}
		}
		return $pre;
	},
	10,
	3
);
if ( ! empty( $airworthy_knobs['php'] ) ) {
	// Pretend the server runs another PHP version (admin screen preview).
	add_filter( 'airworthy_server_php_version', static function () use ( $airworthy_knobs ) {
		return $airworthy_knobs['php'];
	} );
}
if ( ! empty( $airworthy_knobs['recovery'] ) ) {
	add_filter( 'airworthy_recovery_delay', static function () use ( $airworthy_knobs ) {
		return (int) $airworthy_knobs['recovery'];
	} );
}

// NOTE: the 'only' knob must also apply in WP-CLI, where REMOTE_ADDR is empty; see below.
// Log every scheduled action this request runs (hook + group), in order.
$GLOBALS['airworthy_test_ran'] = array();
add_action(
	'action_scheduler_after_execute',
	static function ( $action_id, $action ) {
		$GLOBALS['airworthy_test_ran'][] = $action->get_hook() . ' [' . $action->get_group() . ']';
	},
	10,
	2
);
add_action(
	'action_scheduler_failed_execution',
	static function ( $action_id, $e ) {
		$GLOBALS['airworthy_test_ran'][] = 'FAILED #' . $action_id . ': ' . $e->getMessage();
	},
	10,
	2
);
// Test action standing in for WooCommerce background work.
add_action(
	'airworthy_test_woo_job',
	static function ( $n ) {
		update_option( 'airworthy_test_woo_done_' . (int) $n, gmdate( 'H:i:s' ), false );
	}
);

add_action(
	'init',
	static function () {
		if ( 'run' !== ( $_GET['airworthy_test'] ?? '' ) ) {
			return;
		}
		$t0 = microtime( true );
		do_action( 'action_scheduler_run_queue', 'WP Cron' ); // What WP-Cron's every-minute event does.
		global $wpdb;
		$s = $wpdb->get_row( "SELECT * FROM {$wpdb->base_prefix}airworthy_scans ORDER BY id DESC LIMIT 1" );
		wp_send_json(
			array(
				'status'       => $s ? $s->status : null,
				'files_done'   => $s ? (int) $s->files_done : 0,
				'files_total'  => $s ? (int) $s->files_total : 0,
				'req_ms'       => round( ( microtime( true ) - $t0 ) * 1000 ),
				'peak_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'memory_limit' => ini_get( 'memory_limit' ),
				'ran'          => $GLOBALS['airworthy_test_ran'],
				'as_version'   => class_exists( 'ActionScheduler_Versions' ) ? \ActionScheduler_Versions::instance()->latest_version() : null,
			)
		);
	},
	999
);
