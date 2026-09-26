<?php
// Cases introduced in PHP 8.0. Format: "// CASE <E|D> <version> <label>" opens a case; it runs to the next CASE.
// E = removed / fatal (expect error), D = deprecated (expect warning).

// CASE E 8.0 each() removed
while ( list( $k, $v ) = each( $arr ) ) {}

// CASE E 8.0 create_function() removed
$cb = create_function( '$a', 'return $a * 2;' );

// CASE E 8.0 money_format() removed
echo money_format( '%i', 12.5 );

// CASE E 8.0 get_magic_quotes_gpc() removed
if ( get_magic_quotes_gpc() ) {}

// CASE E 8.0 fgetss() removed
$line = fgetss( $fh );

// CASE E 8.0 convert_cyr_string() removed
$s = convert_cyr_string( $s, 'w', 'k' );

// CASE E 8.0 hebrevc() removed
$s = hebrevc( $s );

// CASE E 8.0 restore_include_path() removed
restore_include_path();

// CASE E 8.0 image2wbmp() removed
image2wbmp( $img );

// CASE E 8.0 __autoload() declaration removed
function __autoload( $class ) {}

// CASE E 8.0 (unset) cast removed
$x = (unset) $y;

// CASE E 8.0 (real) cast removed
$f = (real) $y;

// CASE E 8.0 curly brace string offset removed
$c = $str{0};

// CASE E 8.0 unparenthesized nested ternary is fatal
$r = $a ? 1 : $b ? 2 : 3;

// CASE E 8.0 "match" reserved keyword as function name
function match( $a ) {}

// CASE E 8.0 FILTER_FLAG_SCHEME_REQUIRED removed
filter_var( $u, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED );

// CASE D 8.0 required parameter after optional
function req_after_opt( $a = 1, $b ) {}

// CASE D 8.0 libxml_disable_entity_loader() deprecated
libxml_disable_entity_loader( true );

// CASE D 8.0 zip_open() procedural API deprecated
$z = zip_open( 'a.zip' );

// CASE OK 8.0 control: modern code, no issues expected
$ok = str_contains( 'abc', 'b' ) ? array_map( fn( $v ) => $v * 2, [ 1, 2 ] ) : null;
