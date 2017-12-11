<?php

require_once 'bot/utils.php';
require_once 'bot/Config.php';
require_once 'bot/Exchange.php';
require_once 'bot/Arbitrator.php';
require_once 'bot/TradeMatcher.php';

$gVerbose = true;

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

if ( !Database::profitLossTableExists() ) {
  require_once __DIR__ . '/import-profit-loss.php';
  importProfitLoss();
}

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

$tradeMatcher = new TradeMatcher( $exchanges );
foreach ( $exchanges as $exchange ) {

  if ( $tradeMatcher->hasExchangeNewTrades( $exchange->getID() ) ) {

    logg( "Noticed new trades on " . $exchange->getName() . " that we haven't seen before, importing them now..." );

    $csvPath = __DIR__ . '/' . $exchange->getTradeHistoryCSVName();
    if ( ! is_readable( $csvPath ) ) {
      $prompt = file_get_contents( __DIR__ . '/bot/xchange/' . $exchange->getName() . '-csv-missing.txt' );
      logg( $prompt );
      readline();
      if ( !is_readable( $csvPath ) ) {
        die( "Still can't find the file, refusing to continue\n" );
      }
    }

    // Now read the full history
    $hist = $exchange->queryTradeHistory();

    // Insert what we don't have into the database
    foreach ( $hist as $market => $data ) {
      $arr = explode( '_', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      foreach ( $data as $row ) {
        $tradeMatcher->saveTrade( $exchange->getID(), $tradeable, $currency, $row );
      }
    }

  }

}

$arbitrator = new Arbitrator( $exchanges, $tradeMatcher );
$arbitrator->run();

function sendmail( $title, $message ) {
  //
  $mailRecipient = Config::get( Config::MAIL_RECIPIENT, null );

  if ( is_null( $mailRecipient ) ) {
    $mailRecipient = 'mail@example.com';
  }
  mail( $mailRecipient, "[ARB] " . $title, $message );


}
