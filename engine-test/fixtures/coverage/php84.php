<?php
// CASE D 8.4 implicitly nullable parameter type
function implicit_null( WP_Post $post = null ) {}

// CASE D 8.4 E_STRICT constant deprecated
error_reporting( E_ALL & ~E_STRICT );

// CASE D 8.4 mysqli_ping() deprecated
mysqli_ping( $link );

// CASE D 8.4 mysqli_refresh() deprecated
mysqli_refresh( $link, MYSQLI_REFRESH_LOG );

// CASE D 8.4 lcg_value() deprecated
$r = lcg_value();

// CASE D 8.4 trigger_error() with E_USER_ERROR deprecated
trigger_error( 'Bad', E_USER_ERROR );

// CASE D 8.4 CURLOPT_BINARYTRANSFER deprecated
curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );

// CASE D 8.4 xml_set_object() deprecated
xml_set_object( $parser, $obj );

// CASE D 8.4 str_getcsv() relying on default $escape deprecated
$row = str_getcsv( $line );

// CASE D 8.4 "_" as class name deprecated
class _ {}

// CASE E 8.4 imap extension removed from core (moved to PECL)
$mbox = imap_open( '{localhost:993}', 'u', 'p' );

// CASE E 8.4 pspell extension removed from core (moved to PECL)
$d = pspell_new( 'en' );

// CASE OK 8.4 control
function explicit_null( ?WP_Post $post = null ) {}
