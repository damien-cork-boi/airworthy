<?php
// CASE D 8.2 utf8_encode() deprecated
$s = utf8_encode( $s );

// CASE D 8.2 utf8_decode() deprecated
$s = utf8_decode( $s );

// CASE D 8.2 "${var}" string interpolation deprecated
echo "Hello ${name}";

// CASE D 8.2 "${expr}" string interpolation deprecated
echo "Hello ${'na' . 'me'}";

// CASE D 8.2 mbstring HTML-ENTITIES encoding deprecated
$html = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );

// CASE D 8.2 partially supported callable "self::method"
class Callable_User {
	public function run() { return call_user_func( 'self::helper' ); }
	public static function helper() {}
}

// CASE D 8.2 dynamic property creation (hard to detect statically)
class Dyn_Props {
	public function __construct() { $this->undeclared = 1; }
}

// CASE OK 8.2 control
readonly class Point { public function __construct( public int $x ) {} }
