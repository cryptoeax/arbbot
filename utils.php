<?php

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

function startsWith( $haystack, $needle ) {

  return $needle === "" || strpos( $haystack, $needle ) === 0;

}

function endsWith( $haystack, $needle ) {

  return $needle === "" || substr( $haystack, -strlen( $needle ) ) === $needle;

}

/*function logg( $message, $mail = false ) {

  Database::log( $message );

  echo date( "H:i:s" ) . ": " . $message . "\n";

  if ( $mail ) {
    sendmail( "LOG MESSAGE", date( "H:i:s" ) . ": " . $message );
  }

}
*/
