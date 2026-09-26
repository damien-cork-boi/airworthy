<?php
// CASE D 8.1 strftime() deprecated
echo strftime( '%Y' );

// CASE D 8.1 gmstrftime() deprecated
echo gmstrftime( '%Y' );

// CASE D 8.1 date_sunrise() deprecated
echo date_sunrise( time() );

// CASE D 8.1 FILTER_SANITIZE_STRING deprecated
$v = filter_input( INPUT_GET, 'q', FILTER_SANITIZE_STRING );

// CASE D 8.1 mhash() deprecated
$h = mhash( MHASH_MD5, 'x' );

// CASE D 8.1 FILE_BINARY constant deprecated
file_put_contents( 'f', 'x', FILE_BINARY );

// CASE D 8.1 odbc_result_all() deprecated
odbc_result_all( $res );

// CASE D 8.1 auto_detect_line_endings ini deprecated
ini_set( 'auto_detect_line_endings', '1' );

// CASE D 8.1 Serializable without __serialize/__unserialize
class Legacy_Serial implements Serializable {
	public function serialize() { return ''; }
	public function unserialize( $data ) {}
}

// CASE D 8.1 ArrayAccess method missing return type (needs #[ReturnTypeWillChange])
class Legacy_Collection implements ArrayAccess {
	public function offsetExists( $o ) { return false; }
	public function offsetGet( $o ) { return null; }
	public function offsetSet( $o, $v ) {}
	public function offsetUnset( $o ) {}
}

// CASE E 8.1 write to entire $GLOBALS array is fatal
$GLOBALS = array();

// CASE E 8.1 "never" reserved as class name
class never {}

// CASE OK 8.1 control
enum Suit: string { case Hearts = 'H'; }
