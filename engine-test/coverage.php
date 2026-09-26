<?php
/**
 * Coverage matrix: for each engine, scan each fixtures/coverage/phpXY.php against its own
 * target version and check every "// CASE" block was (or, for OK blocks, was not) flagged.
 *
 * Usage: php coverage.php [--json]
 */
$json    = in_array( '--json', $argv, true );
$engines = array( 'v9', 'v10' );
$results = array();

foreach ( glob( __DIR__ . '/fixtures/coverage/php*.php' ) as $fixture ) {
	// Parse CASE markers into line ranges.
	$lines = file( $fixture );
	$cases = array();
	foreach ( $lines as $i => $l ) {
		if ( preg_match( '#//\s*CASE\s+(E|D|OK)\s+(8\.\d)\s+(.*)$#', $l, $m ) ) {
			if ( $cases ) {
				$cases[ count( $cases ) - 1 ]['end'] = $i; // Previous case ends before this marker.
			}
			$cases[] = array( 'kind' => $m[1], 'ver' => $m[2], 'label' => trim( $m[3] ), 'start' => $i + 1, 'end' => count( $lines ) );
		}
	}
	$target = $cases[0]['ver'];

	foreach ( $engines as $engine ) {
		$cmd  = sprintf( 'php %s --engine=%s --target=%s %s', escapeshellarg( __DIR__ . '/scan.php' ), $engine, $target, escapeshellarg( $fixture ) );
		$scan = json_decode( shell_exec( $cmd ), true );
		$iss  = $scan['files'][0]['issues'] ?? array();
		foreach ( $cases as $c ) {
			$hits = array_values( array_filter( $iss, fn( $x ) => $x['line'] > $c['start'] && $x['line'] <= $c['end'] ) );
			$sev  = $hits ? ( in_array( 'error', array_column( $hits, 'severity' ), true ) ? 'error' : 'warning' ) : null;
			if ( 'OK' === $c['kind'] ) {
				$result = $hits ? 'FALSE+' : 'ok';
			} elseif ( ! $hits ) {
				$result = 'MISS';
			} else {
				$want   = 'E' === $c['kind'] ? 'error' : 'warning';
				$result = $sev === $want ? 'hit' : "hit({$sev})";
			}
			$results[ $c['ver'] ][ $c['label'] ]['kind']      = $c['kind'];
			$results[ $c['ver'] ][ $c['label'] ][ $engine ]   = $result;
			$results[ $c['ver'] ][ $c['label'] ][ "{$engine}_src" ] = implode( ', ', array_unique( array_map( fn( $x ) => preg_replace( '/^PHPCompatibility\./', '', $x['source'] ), $hits ) ) );
		}
	}
}

if ( $json ) {
	echo json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit;
}
foreach ( $results as $ver => $cases ) {
	$tot = array( 'v9' => 0, 'v10' => 0 );
	$n   = 0;
	printf( "\n== PHP %s ==\n%-4s %-8s %-8s %s\n", $ver, 'kind', 'v9', 'v10', 'case' );
	foreach ( $cases as $label => $r ) {
		printf( "%-4s %-8s %-8s %s\n", $r['kind'], $r['v9'], $r['v10'], $label );
		if ( 'OK' !== $r['kind'] ) {
			$n++;
			foreach ( $tot as $e => $_ ) {
				$tot[ $e ] += 0 === strpos( $r[ $e ], 'hit' ) ? 1 : 0;
			}
		}
	}
	printf( "detected: v9 %d/%d, v10 %d/%d\n", $tot['v9'], $n, $tot['v10'], $n );
}
