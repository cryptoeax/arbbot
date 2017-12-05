<?php

require_once 'utils.php';
require_once 'Config.php';
require_once 'Database.php';
require_once 'Exchange.php';
require_once 'Arbitrator.php';

date_default_timezone_set( "UTC" );

try {
  Config::refresh();
}
catch ( Exception $ex ) {
  echo "Error loading config: " . $ex->getMessage() . "\n";
  return;
}

logg( "ARBITRATOR V2.0 launching..." );
sendmail( "Startup mail service test", "This is a test message to confirm that the mail service is working properly!" );
logg( "Loading config..." );

// Configure exchanges...
$exchanges = [ ];
$msg = '';

foreach ( glob( 'xchange/*.php' ) as $filename ) {
  $name = basename( $filename, '.php' );
  logg( "Enabling $name..." );
  require_once $filename;
  try {
    $exchanges[] = new $name;
  }
  catch ( Exception $ex ) {
    logg( "$name not configured" );
  }
}

logg( "Configured " . count( $exchanges ) . " exchanges!" );

if ( count( $exchanges ) < 2 ) {
  logg( "ERROR: At least two exchanges are required!" );
  return;
}

logg( "Testing exchange access..." );

foreach ( $exchanges as $exchange ) {

  try {
    $exchange->testAccess();
    logg( $exchange->getName() . " [OK]" );
  }
  catch ( Exception $ex ) {
    logg( $exchange->getName() . " [ERROR]\n" . $ex->getMessage() );
    return;
  }
}

$arbitrator = new Arbitrator( $exchanges );
$arbitrator->run();

function sendmail( $title, $message ) {
  //
  $mailRecipient = Config::get( Config::MAIL_RECIPIENT, null );

  if ( is_null( $mailRecipient ) ) {
    $mailRecipient = 'mail@example.com';
  }
  mail( $mailRecipient, "[ARB] " . $title, $message );


}

function logg( $message, $mail = false ) {

  Database::log( $message );

  echo date( "H:i:s" ) . ": " . $message . "\n";

}
