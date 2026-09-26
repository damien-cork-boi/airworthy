<?php
/**
 * Prototype verdict logic, run over fixtures/plugins for every target 8.0–8.5.
 *   Unknown  — any file unreadable, skipped (too large), internal error or exception
 *   Blocker  — at least one unguarded error
 *   Warnings — only warnings (incl. errors downgraded as guarded / behaviour-changed)
 *   Ready    — nothing found
 *
 * Usage: php verdicts.php
 */
$expect = array(
	'fx-ready'      => array_fill_keys( array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), 'Ready' ),
	'fx-blocker-80' => array_fill_keys( array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), 'Blocker' ),
	'fx-warnings'   => array( '8.0' => 'Ready', '8.1' => 'Warnings', '8.2' => 'Warnings', '8.3' => 'Warnings', '8.4' => 'Warnings', '8.5' => 'Warnings' ),
	'fx-guarded'    => array_fill_keys( array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), 'Warnings' ), // Guarded code: downgraded, not Blocker.
	'fx-large'      => array_fill_keys( array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), 'Unknown' ),
	'fx-unreadable' => array_fill_keys( array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), 'Unknown' ),
);
$guard = '#\b(function_exists|extension_loaded|class_exists|is_callable|version_compare|PHP_VERSION_ID|PHP_VERSION|PHP_MAJOR_VERSION)\b#i';

function is_guarded( $path, $line, $guard ) {
	$src  = file( $path );
	$ln   = $line - 1;
	$near = implode( '', array_slice( $src, max( 0, $ln - 2 ), 3 ) );
	return preg_match( '#phpcs:(ignore|disable)[^\n]*PHPCompatibility#', $near )
		|| preg_match( $guard, implode( '', array_slice( $src, max( 0, $ln - 12 ), 13 ) ) );
}

$pass = 0;
$n    = 0;
printf( "%-15s %s\n", 'fixture', implode( '  ', array_map( fn( $v ) => str_pad( $v, 9 ), array_keys( $expect['fx-ready'] ) ) ) );
foreach ( $expect as $slug => $by_ver ) {
	$row = array();
	foreach ( $by_ver as $ver => $want ) {
		$scan = json_decode( shell_exec( sprintf( 'php %s --engine=v10 --target=%s %s', escapeshellarg( __DIR__ . '/scan.php' ), $ver, escapeshellarg( __DIR__ . "/fixtures/plugins/$slug" ) ) ), true );
		$err  = 0;
		$warn = 0;
		$unk  = false;
		foreach ( $scan['files'] as $f ) {
			if ( 'scanned' !== $f['status'] ) {
				$unk = true;
			}
			foreach ( $f['issues'] as $i ) {
				$is_err = 'error' === $i['severity'] && ! preg_match( '#\.Changed$#', $i['source'] );
				if ( $is_err && ! is_guarded( $f['path'], $i['line'], $guard ) ) {
					$err++;
				} else {
					$warn++;
				}
			}
		}
		$got = $unk ? 'Unknown' : ( $err ? 'Blocker' : ( $warn ? 'Warnings' : 'Ready' ) );
		$ok  = $got === $want;
		$pass += $ok ? 1 : 0;
		$n++;
		$row[] = str_pad( $ok ? $got : "✗$got", 9 );
	}
	printf( "%-15s %s\n", $slug, implode( '  ', $row ) );
}
printf( "\n%d/%d fixture verdicts as expected\n", $pass, $n );
