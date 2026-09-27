<?php
/**
 * Runs the scoped engine (scan.php --engine=build) over paths, then classifies every finding
 * with the plugin's Classifier. Standalone: no WordPress needed.
 *
 * Usage: php classify.php --target=8.4 [--summary] [--show=plain|guarded|suppressed] <path>...
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/plugin/src/Scan/Classifier.php';
require dirname( __DIR__ ) . '/plugin/src/Scan/FileLister.php';
require dirname( __DIR__ ) . '/plugin/src/Scan/LoadGraph.php';

$opts    = getopt( '', array( 'target:', 'summary', 'show:', 'json:' ), $rest );
$target  = $opts['target'] ?? '8.4';
$paths   = array_slice( $argv, $rest );
$cmd     = 'SCAN_CLEAR=1 php -d memory_limit=2G ' . escapeshellarg( __DIR__ . '/scan.php' ) . ' --engine=build --target=' . escapeshellarg( $target ) . ' --max-bytes=99999999 ' . implode( ' ', array_map( 'escapeshellarg', $paths ) );
$scan    = json_decode( shell_exec( $cmd ), true );
$rows    = array();

foreach ( $scan['files'] as $f ) {
	if ( ! $f['issues'] ) {
		continue;
	}
	// Path relative to the component: strip ".../<plugins-dir>/<slug>/".
	$parts    = explode( '/', $f['path'] );
	$idx      = max( array_search( 'real-plugins', $parts, true ), array_search( 'plugins', $parts, true ) );
	$slug     = $parts[ $idx + 1 ];
	$relative = implode( '/', array_slice( $parts, $idx + 2 ) );
	// Same file skips as the plugin's FileLister.
	if ( preg_match( Airworthy\Scan\FileLister::DATA_FILES, $relative ) || preg_match( Airworthy\Scan\FileLister::LIBRARY_TESTS, $relative ) ) {
		continue;
	}
	// Same pre-pass as the plugin (own functions with removed-extension prefixes), once per component.
	static $prepass = array();
	if ( ! isset( $prepass[ $slug ] ) ) {
		$croot            = implode( '/', array_slice( $parts, 0, $idx + 2 ) );
		$listed           = Airworthy\Scan\FileLister::list_files( $croot )['files'];
		$prepass[ $slug ] = Airworthy\Scan\FileLister::prepass( $croot, $listed );
		$prepass[ $slug ]['graph'] = Airworthy\Scan\LoadGraph::for_component( $croot, $listed, $target, $prepass[ $slug ]['functions'] );
	}
	$cls      = new Airworthy\Scan\Classifier( file_get_contents( $f['path'] ), $relative, $target, $prepass[ $slug ]['functions'], $prepass[ $slug ]['graph'] );
	foreach ( $f['issues'] as $i ) {
		$i['rule'] = $i['source']; // scan.php calls it "source"; the plugin stores "rule".
		if ( '.Changed' === substr( $i['rule'], -8 ) ) {
			$i['severity'] = 'warning'; // Behaviour changes are not removals (as the plugin stores them).
		}
		$context   = $cls->classify( $i );
		if ( 'own' === $context ) {
			$i['severity'] = 'notice';
			$context       = 'plain';
		}
		$rows[]  = array( 'slug' => $slug, 'file' => $relative, 'line' => $i['line'], 'severity' => $i['severity'], 'context' => $context, 'rule' => $i['source'] );
	}
}

if ( isset( $opts['json'] ) ) {
	file_put_contents( $opts['json'], json_encode( $rows ) );
}

if ( isset( $opts['summary'] ) ) {
	$by = array();
	foreach ( $rows as $r ) {
		$by[ $r['slug'] ][ $r['severity'] . ':' . $r['context'] ] = ( $by[ $r['slug'] ][ $r['severity'] . ':' . $r['context'] ] ?? 0 ) + 1;
	}
	ksort( $by );
	foreach ( $by as $slug => $counts ) {
		ksort( $counts );
		printf( "%-38s %s\n", $slug, json_encode( $counts ) );
	}
	$errors = array_filter( $rows, function ( $r ) { return 'error' === $r['severity']; } );
	$tally  = array_count_values( array_column( $errors, 'context' ) );
	printf( "\nerror-level findings: %d  => %s\n", count( $errors ), json_encode( $tally ) );
}

foreach ( $rows as $r ) {
	if ( ! isset( $opts['summary'] ) || ( isset( $opts['show'] ) && $opts['show'] === $r['context'] && 'error' === $r['severity'] ) ) {
		printf( "%-10s %-8s %-15s %s:%d  %s\n", $r['context'], $r['severity'], $r['slug'], $r['file'], $r['line'], $r['rule'] );
	}
}
