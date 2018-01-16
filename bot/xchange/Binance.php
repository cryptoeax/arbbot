<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../CCXTAdapter.php';

define( 'BINANCE_ID', 9 );

class BinanceExchange extends \ccxt\binance {

  use CCXTErrorHandler;

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

  public function getDepositHistory() {

    return array(

      'history' => $this->exchange->wapiGetDepositHistory()[ 'depositList' ],
      'statusKey' => 'status',
      'coinKey' => 'asset',
      'amountKey' => 'amount',
      'timeKey' => 'applyTime',
      'pending' => 0 /* pending */,

    );

  }

  public function getWithdrawalHistory() {

    return array(

      'history' => $this->exchange->wapiGetWithdrawHistory()[ 'withdrawList' ],
      'statusKey' => 'status',
      'coinKey' => 'asset',
      'amountKey' => 'amount',
      'timeKey' => 'applyTime',
      'pending' => [2 /* awaiting approval */, 4 /* processing */],

    );

  }

};
