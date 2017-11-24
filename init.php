<?php
require_once 'utils.php';
require_once 'Config.php';
require_once 'Exchange.php';
require_once 'Arbitrator.php';

date_default_timezone_set( "UTC" );

try {
  Config::refresh();
}
catch ( Exception $ex ) {
  return;
}
$exchanges = [ ];

foreach ( glob( 'xchange/*.php' ) as $filename ) {
  $name = basename( $filename, '.php' );

  require_once $filename;
  try {
    $exchanges[] = new $name;
  }
  catch ( Exception $ex ) {
  }
}
