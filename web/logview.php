<?php

require_once './header.inc.php';

$age = filter_input( INPUT_GET, 'age' );

$time = 0;
if ( !is_null( $age ) && $age !== false && strlen( $age ) > 0 ) {
  $time = time() - $age;
}

foreach ( WebDB::getLog( $time ) as $log ) {
  echo date( 'H:i:s', $log[ 'time' ] ) . ': ' . $log[ 'message' ] . "\n";
}
