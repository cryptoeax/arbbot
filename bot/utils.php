<?php

require_once __DIR__ . '/Database.php';

function formatCoin( $coin ) {

  return str_pad( $coin, 5, " ", STR_PAD_LEFT );

}

function formatBalance( $value ) {

  $value = sprintf( '%.8f', $value );
  return str_pad( $value, 14, " ", STR_PAD_LEFT );

}

function formatBTC( $value ) {

  return sprintf( '%.8f', $value );

}

function endsWith( $haystack, $needle ) {

  return $needle === "" || substr( $haystack, -strlen( $needle ) ) === $needle;

}

function logg( $message, $mail = false ) {

  Database::log( $message );

  global $gVerbose;

  if ( @$gVerbose ) {
    echo date( "H:i:s" ) . ": " . $message . "\n";
  }

}

function quoteStr( $str ) {
  return "\"$str\"";
}

