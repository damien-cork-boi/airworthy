<?php
/**
 * False-positive probe: for every error-level finding in a scan JSON, check three cheap signals
 * that the code is deliberately guarded legacy code that won't run on the target PHP:
 *   A) author annotation: a phpcs:ignore / phpcs:disable naming PHPCompatibility on or just above the line
 *   B) runtime guard within the previous 12 lines: function_exists / extension_loaded / class_exists /
 *      PHP_VERSION(_ID) / version_compare / is_callable / engine constant checks
 *   C) known bundled library that selects an engine at runtime (phpseclib, symfony polyfills)
 *
 * Usage: php guards.php bench.json
 */
$d     = json_decode( file_get_contents( $argv[1] ), true );
$guard = '#\b(function_exists|extension_loaded|class_exists|is_callable|version_compare|PHP_VERSION_ID|PHP_VERSION|PHP_MAJOR_VERSION|ENGINE_MCRYPT|mysql link)\b#i';
$libs  = '#/(phpseclib|symfony/polyfill-[^/]+)/#';
$tot   = 0;
$sig   = array( 'A' => 0, 'B' => 0, 'C' => 0 );
$none  = array();

foreach ( $d['files'] as $f ) {
	$errs = array_filter( $f['issues'], fn( $i ) => 'error' === $i['severity'] && 0 !== strpos( $i['source'], 'Internal.' ) );
	if ( ! $errs ) {
		continue;
	}
	$src = file( $f['path'] );
	foreach ( $errs as $i ) {
		$tot++;
		$ln   = $i['line'] - 1;
		$near = implode( '', array_slice( $src, max( 0, $ln - 2 ), 3 ) );
		$ctx  = implode( '', array_slice( $src, max( 0, $ln - 12 ), 13 ) );
		$hit  = false;
		if ( preg_match( '#phpcs:(ignore|disable)[^\n]*PHPCompatibility#', $near ) || preg_match( '#phpcs:disable[^\n]*PHPCompatibility#', implode( '', array_slice( $src, 0, $ln ) ) ) ) {
			$sig['A']++;
			$hit = true;
		}
		if ( preg_match( $guard, $ctx ) ) {
			$sig['B']++;
			$hit = true;
		}
		if ( preg_match( $libs, $f['path'] ) ) {
			$sig['C']++;
			$hit = true;
		}
		if ( ! $hit ) {
			$none[] = $f['path'] . ':' . $i['line'] . '  ' . $i['source'];
		}
	}
}
printf( "error-level findings: %d\n  A author annotation: %d\n  B guard in previous 12 lines: %d\n  C known engine-switching library: %d\n  no signal (would stay Blocker): %d\n", $tot, $sig['A'], $sig['B'], $sig['C'], count( $none ) );
foreach ( $none as $n ) {
	echo '    ', $n, "\n";
}
