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

function getSmoothedResultsForGraph( $result ) {

  $ma = [ ];

  $data = [ ];
  while ( $row = mysql_fetch_assoc( $result ) ) {

    $value = floatval( $row[ 'data' ] );
    $ex = $row[ 'ID_exchange' ];

    if (!in_array( $ex, array_keys( $ma ) )) {
      $ma[$ex] = [ ];
    }
    $ma[$ex][] = $value;
    while ( count( $ma[$ex] ) > 4 ) {
      array_shift( $ma[$ex] );
    }

    $sma = array_sum( $ma[$ex] ) / count( $ma[$ex] );
    $data[] = ['time' => $row[ 'created' ], 'value' => $sma , 'raw' => $value,
               'exchange' => $ex ];
  }

  return $data;

}

