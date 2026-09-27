<?php
/**
 * TEST SITE ONLY (not part of the plugin). Drives Airworthy's queue from web requests with
 * shared-hosting limits, the way WP-Cron would. Localhost only.
 *
 * GET /?airworthy_test=run  → runs Action Scheduler's queue once (as WP-Cron does), returns scan status JSON.
 *
 * Knobs, set with WP-CLI: wp option update airworthy_test_knobs '{"nobudget":1,"seconds":10,"recovery":5}'  (memory cap: echo 64M > .aw-mem)
 */
if ( ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) {
	return;
}

$airworthy_knobs = (array) json_decode( (string) get_option( 'airworthy_test_knobs', '{}' ), true );

// Memory cap: see the .aw-mem switch in wp-config.php (it must apply before WordPress loads).
if ( ! empty( $airworthy_knobs['nobudget'] ) ) {
	add_filter( 'airworthy_memory_estimate', '__return_zero' ); // Force crashes on big files.
}
if ( ! empty( $airworthy_knobs['seconds'] ) ) {
	add_filter( 'airworthy_batch_seconds', static function () use ( $airworthy_knobs ) {
		return (float) $airworthy_knobs['seconds'];
	} );
}
if ( ! empty( $airworthy_knobs['recovery'] ) ) {
	add_filter( 'airworthy_recovery_delay', static function () use ( $airworthy_knobs ) {
		return (int) $airworthy_knobs['recovery'];
	} );
}

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
			)
		);
	},
	999
);
