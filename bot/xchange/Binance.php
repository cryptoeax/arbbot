<?php

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
    $extraOptions = array(
      'recvWindow' => 15 * 1000, // Increase recvWindow to 15 seconds.
    );
    parent::__construct( BINANCE_ID, 'Binance', 'BinanceExchange', $extraOptions );
  }

  public function getRateLimit() {
    $limits = $this->exchange->publicGetExchangeInfo()['rateLimits'];
    foreach ( $limits as $limit ) {
      if ( $limit[ 'rateLimitType' ] == 'ORDERS' &&
           $limit[ 'interval' ] == 'SECOND' ) {
        return 1000 / $limit[ 'limit' ];
      }
    }
    return 1000;
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
      'timeKey' => 'insertTime',
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
      'txidKey' => 'txId',
      'addressKey' => 'address',
      'pending' => [2 /* awaiting approval */, 4 /* processing */],
      'completed' => 6 /* completed */,

    );

  }

};
