<?php
/**
 * Plugin Name: FX Guarded
 * Description: Old-PHP fallback code guarded by version / function_exists checks. Never runs on PHP 8.
 * Expect: ideally Ready, but a static scanner will likely report Blocker (the false-positive problem).
 */
function fx_guarded_iter( $arr ) {
	if ( PHP_VERSION_ID < 80000 ) {
		return each( $arr );
	}
	return array( key( $arr ), current( $arr ) );
}
if ( ! function_exists( 'fx_guarded_quotes' ) ) {
	function fx_guarded_quotes() {
		return function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc();
	}
}
if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
	$cb = create_function( '', 'return 1;' );
}
