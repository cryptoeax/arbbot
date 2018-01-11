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

function startsWith( $haystack, $needle ) {

  return $needle === "" || strpos( $haystack, $needle ) === 0;

}

function endsWith( $haystack, $needle ) {

  return $needle === "" || substr( $haystack, -strlen( $needle ) ) === $needle;

}

function alert( $type, $message ) {

  database::insertAlert( $type, $message );

}

function logg( $message ) {

  database::log( $message );

  global $gVerbose;

  if ( @$gVerbose ) {
    echo date( "H:i:s" ) . ": " . $message . "\n";
  }

}

function compareByTime( $row1, $row2 ) {
  return $row1[ 'time' ] - $row2[ 'time' ];
}

function sumOfAmount( $carry, $item ) {
  return $carry + $item[ 'amount' ];
}


function sumOfAmountTimesRate( $carry, $item ) {
  return $carry + $item[ 'amount' ] * $item[ 'rate' ];
}

function getCurrency( $item ) {
  return $item[ 'Currency' ];
}

function generateNonce( $id ) {
  static $previousNonce = array( );
  if ( !isset( $previousNonce[ $id ] ) ) {
    $previousNonce[ $id ] = 0;
  }

  // Try the current time, if we're getting called too fast, step up one by one.
  $nonce = floor( microtime( true ) * 1000000);
  if ( $nonce <= $previousNonce[ $id ] ) {
    $nonce = $previousNonce[ $id ] + 1;
  }

  $previousNonce[ $id ] = $nonce;
  return $nonce;
}

function installDirectoryDirty() {

  $installDir = __DIR__ . '/../';
  $result = array( );
  foreach ( array_merge( glob( "$installDir/bot/*.php" ),
                         glob( "$installDir/bot/xchange/*.php" ) ) as $file ) {
    $name = basename( $file );
    if ( is_readable( "$installDir/$name" ) ) {
      $result[] = $name;
    } else if ( is_readable( "$installDir/xchange/$name" ) ) {
      $result[] = "xchange/$name";
    }
  }
  if ( !count( $result ) ) {
    $result = null;
  }
  return $result;

}

