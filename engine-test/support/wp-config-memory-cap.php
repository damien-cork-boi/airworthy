<?php
// Paste into the test site's wp-config.php, above "That's all, stop editing!".
// TEST SITE ONLY: no WP-Cron spawning, so only the test driver's requests run scheduled jobs
// (makes per-request job logs deterministic). Delete .aw-nocron to restore normal WP-Cron.
if ( is_readable( __DIR__ . '/.aw-nocron' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

// TEST SITE ONLY: simulate a host with a hard memory cap for Airworthy's queue tests.
// The cap comes from the .aw-mem file (e.g. "64M"); delete the file to turn it off.
if ( ! empty( $_GET['airworthy_test'] ) && is_readable( __DIR__ . '/.aw-mem' ) ) {
	$airworthy_cap = trim( (string) file_get_contents( __DIR__ . '/.aw-mem' ) );
	define( 'WP_MEMORY_LIMIT', $airworthy_cap );
	define( 'WP_MAX_MEMORY_LIMIT', $airworthy_cap );
	ini_set( 'memory_limit', $airworthy_cap );
}

