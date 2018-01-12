<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../CCXTAdapter.php';

define( 'BINANCE_ID', 9 );

class BinanceExchange extends \ccxt\binance {

  public function __construct( $options = array( ) ) {
    parent::__construct( $options );
  }

  public function nonce() {
    return generateNonce( BINANCE_ID );
  }
};

class Binance extends CCXTAdapter {

  public function __construct() {
    parent::__construct( BINANCE_ID, 'Binance', 'BinanceExchange' );
  }

  public function isMarketActive( $market ) {
    return $market[ 'info' ][ 'status' ] == 'TRADING';
  }

  public function checkAPIReturnValue( $result ) {
    if ( isset( $result[ 'info' ][ 'code' ] ) ) {
      return false;
    }
    return $result[ 'info' ][ 'success' ] === true;
  }

  private $lastStuckReportTime = [ ];

  public function detectStuckTransfers() {

    $history = $this->exchange->wapiGetDepositHistory();

    $this->detectStuckTransfersInternal( $history, 'deposit' );

    $history = $this->exchange->wapiGetWithdrawHistory();

    $this->detectStuckTransfersInternal( $history, 'withdraw' );

  }

  private function detectStuckTransfersInternal( $history, $key ) {

    foreach ( $history[ $key . 'List' ] as $entry ) {

      foreach ( $block as $entry ) {
        $timestamp = floor( $entry[ 'applyTime' ] / 1000 ); // in milliseconds
        if ( key_exists( $key, $this->lastStuckReportTime ) && $timestamp < $this->lastStuckReportTime[ $key ] ) {
          continue;
        }
        $status = strtoupper( $entry[ 'status' ] );

        if ( $timestamp < time() - 12 * 3600 && ($status == 2 /* awaiting approval */ || $status == 4 /* processing */) ) {
          alert( 'stuck-transfer', $this->prefix() . "Stuck $key! Please investigate and open support ticket if neccessary!\n\n" . print_r( $entry, true ), true );
          $this->lastStuckReportTime[ $key ] = $timestamp;
        }
      }
    }

  }

  public function getDepositHistory() {

    return array(

      'history' => $this->exchange->wapiGetDepositHistory()[ 'depositList' ],
      'statusKey' => 'status',
      'coinKey' => 'asset',
      'amountKey' => 'amount',

    );

  }

};
