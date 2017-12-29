<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../BittrexLikeExchange.php';

class Bleutrade extends BittrexLikeExchange {
  
  const ID = 2;
  //
  const PUBLIC_URL = 'https://bleutrade.com/api/v2/public/';
  const PRIVATE_URL = 'https://bleutrade.com/api/v2/';
  
  function __construct() {
    parent::__construct( Config::get( "bleutrade.key" ), Config::get( "bleutrade.secret" ),
                         array(
      'separator' => '_',
      'offsetCurrency' => 1,
      'offsetTradeable' => 0,
    ), 'OrderId', 'orderid', 'ALL' );

  }

  public function addFeeToPrice( $price ) {
    return $price * 1.0025;

  }

  public function deductFeeFromAmountBuy( $amount ) {
    return $amount * 0.9975;

  }

  public function deductFeeFromAmountSell( $amount ) {
    return $amount * 0.9975;

  }

  public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {
    $market = $tradeable . '_' . $currency;
    $orderstatus = 'ALL';
    $ordertype = 'ALL';
    $result = $this->queryAPI( 'account/getorders', 
    [ 
    'market' => $market,
    'orderstatus' => $orderstatus,
    'ordertype' => $ordertype, 
    ]  );
    $id = trim( $id, '{}' );
    foreach ($result as $order) {
  if ($order[ 'OrderId' ]  == $id) {
    if ($order[ 'QuantityRemaining' ] != 0) {
              logg( $this->prefix() . "Order " . $id . " assumed to be filled but " . $order[ 'QuantityRemaining' ] . " still remaining" );
          }
  return $order[ 'Price' ];
      }
    }
    return null;
  }

  public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
    $results = array( );

    // Since this exchange was added after merging of the pl-rewrite branch, we don't
    // need the full trade history for the initial import, so we can ignore $recentOnly!

    $options = array(
      'market' => 'ALL',
      'orderstatus' => 'ALL',
      'ordertype' => 'ALL',
      'depth' => 20000, // max!
    );
    $result = $this->queryAPI( 'account/getorders', $options );

    foreach ($result as $row) {
      $market = $this->parseMarketName( $row[ 'Exchange' ] );
      $currency = $market[ 'currency' ];
      $tradeable = $market[ 'tradeable' ];
      $market = $this->makeMarketName( $currency, $tradeable );
      if (!in_array( $market, array_keys( $results ) )) {
        $results[ $market ] = array();
      }
      $amount = $row[ 'Quantity' ] - $row[ 'QuantityRemaining' ];
      $feeFactor = ($row[ 'Type' ] == 'SELL') ? -1 : 1;

      $results[ $market ][] = array(
        'rawID' => $row[ 'OrderId' ],
        'id' => $row[ 'OrderId' ],
        'currency' => $currency,
        'tradeable' => $tradeable,
        'type' => strtolower( $row[ 'Type' ] ),
        'time' => strtotime( $row[ 'Created' ] ),
        'rate' => $row[ 'Price' ],
        'amount' => $amount,
        'fee' => $feeFactor * ( $this->addFeeToPrice( $row[ 'Price' ] ) - $row[ 'Price' ] ),
        'total' => $amount * $row[ 'Price' ],
      );
    }

    foreach ( array_keys( $results ) as $market ) {
      usort( $results[ $market ], 'compareByTime' );
    }

    return $results;
  }

  public function queryRecentDeposits( $currency = null ) {

    $history = $this->queryAPI( 'account/getdeposithistory' );

    $result = array();
    foreach ( $history as $row ) {
      if ( !is_null( $currency ) && $currency != $row[ 'currency' ] ) {
        continue;
      }

      // Label is in the following format:
      // "Deposit in address Youraddress"
      if ( !preg_match( '/address ([^;]+);/', $row[ 'Label' ], $matches ) ) {
        logg( $this->prefix() . sprintf( "WARNING: Received unexpected `Label' in the deposit history: \"%s\"",
                                         $row[ 'Label' ] ) );
        continue;
      }
      $address = $matches[ 1 ];

      $result[] = array(
        'currency' => $row[ 'Coin' ],
        'amount' => $row[ 'Amount' ],
        'txid' => '', // transaction id not exposed
        'address' => $address,
        'time' => strtotime( $row[ 'TimeStamp' ] ),
        'pending' => false, // assume anything that shows up here is finished
      );
    }

    usort( $result, 'compareByTime' );

    return $result;

  }

  public function queryRecentWithdrawals( $currency = null ) {

    $history = $this->queryAPI( 'account/getwithdrawhistory' );

    $result = array();
    foreach ( $history as $row ) {
      if ( !is_null( $currency ) && $currency != $row[ 'currency' ] ) {
        continue;
      }

      // Label is in the following format:
      // "Withdraw: 0.99000000 to address Anotheraddress; fee 0.01000000"
      if ( !preg_match( '/address ([^;]+);/', $row[ 'Label' ], $matches ) ) {
        logg( $this->prefix() . sprintf( "WARNING: Received unexpected `Label' in the withdrawal history: \"%s\"",
                                         $row[ 'Label' ] ) );
        continue;
      }
      $address = $matches[ 1 ];

      $result[] = array(
        'currency' => $row[ 'Coin' ],
        'amount' => $row[ 'Amount' ],
        'txid' => $row[ 'TransactionId' ],
        'address' => $address,
        'time' => strtotime( $row[ 'TimeStamp' ] ),
        'pending' => false, // assume anything that shows up here is finished
      );
    }

    usort( $result, 'compareByTime' );

    return $result;

  }

  public function getSmallestOrderSize() {

    return '0.00050000';

  }
  public function getID() {

    return Bleutrade::ID;

  }
  public function getName() {

    return "BLEUTRADE";

  }

  public function getTradeHistoryCSVName() {

    // See the comment in queryTradeHistory().
    return null;

  }

  protected function getPublicURL() {

    return Bleutrade::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return BleuTrade::PRIVATE_URL;

  }

}
