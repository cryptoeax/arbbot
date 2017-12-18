<?php

require_once 'bot/utils.php';
require_once 'bot/Config.php';
require_once 'bot/Exchange.php';
require_once 'bot/Arbitrator.php';

$gVerbose = true;

date_default_timezone_set( "UTC" );

if ( $files = installDirectoryDirty() ) {
  echo "Error launching due to the following files being found in the installation directory.\n";
  echo "Please remove them before proceeding.\n";
  foreach ( $files as $file ) {
    echo "\t* $file\n";
  }
  return;
}

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

Database::handleAddressUpgrade();

// Configure exchanges...
$exchanges = [ ];
$msg = '';

foreach ( glob( 'bot/xchange/*.php' ) as $filename ) {
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
