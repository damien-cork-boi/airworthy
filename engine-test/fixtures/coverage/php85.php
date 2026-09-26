<?php
// CASE D 8.5 backtick operator deprecated
$out = `ls -la`;

// CASE D 8.5 (boolean) non-canonical cast deprecated
$b = (boolean) $x;

// CASE D 8.5 (integer) non-canonical cast deprecated
$i = (integer) $x;

// CASE D 8.5 (double) non-canonical cast deprecated
$d = (double) $x;

// CASE D 8.5 (binary) non-canonical cast deprecated
$s = (binary) $x;

// CASE D 8.5 curl_close() deprecated
curl_close( $ch );

// CASE D 8.5 imagedestroy() deprecated
imagedestroy( $img );

// CASE D 8.5 finfo_close() deprecated
finfo_close( $finfo );

// CASE D 8.5 xml_parser_free() deprecated
xml_parser_free( $parser );

// CASE D 8.5 socket_set_timeout() alias deprecated
socket_set_timeout( $sock, 5 );

// CASE D 8.5 DATE_RFC7231 constant deprecated
echo date( DATE_RFC7231 );

// CASE D 8.5 case terminated with semicolon deprecated
switch ( $x ) {
	case 1;
		break;
}

// CASE D 8.5 null used as array offset deprecated
$v = $arr[ null ];

// CASE D 8.5 PDO::MYSQL_ATTR_* driver constants deprecated
$pdo = new PDO( $dsn, $u, $p, array( PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8' ) );

// CASE OK 8.5 control
$r = [ 1, 2, 3 ] |> array_sum( ... );
