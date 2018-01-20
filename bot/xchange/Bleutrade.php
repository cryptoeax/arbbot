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

  public function addFeeToPrice( $price, $tradeable, $currency ) {
    return $price * 1.0025;

  }

  public function deductFeeFromAmountBuy( $amount, $tradeable, $currency ) {
    return $amount * 0.9975;

  }

  public function deductFeeFromAmountSell( $amount, $tradeable, $currency ) {
    return $amount * 0.9975;

  }

  public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {

    $order = $this->queryAPI( 'account/getorder', array( 'orderid' => $id ) );
    if ( !$order ) {
      logg( $this->prefix() . "Order " . $id . " asssumed to be filled but not found on exchange" );
      return null;
    }

    if ( $order[ 'OrderId' ] != $id ) {
      logg( $this->prefix() . "Exchange is drunk(), returned " . $order[ 'OrderId' ] . " when we asked for " . $id );
      return null;
    }

    $market = $this->parseMarketName( $order[ 'Exchange' ] );

    if ( $market[ 'tradeable' ] != $tradeable ||
         $market[ 'currency' ] != $currency ) {
      logg( $this->prefix() . "Exchange is drunk(), returned " . $order[ 'Exchange' ] . " when we asked for " . $this->makeMarketName( $currency, $tradeable ) );
      return null;
    }

    $feeFactor = ($order[ 'Type' ] == 'SELL') ? -1 : 1;
    $fee = $feeFactor * ( $this->addFeeToPrice( $order[ 'QuantityBaseTraded' ], $tradeable, $currency ) - $order[ 'QuantityBaseTraded' ] );
    $total = $order[ 'QuantityBaseTraded' ];
    return $total + $fee;

  }

  public function queryTradeHistory( $options = array( ) ) {
    $results = array( );

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
        'fee' => $feeFactor * ( $this->addFeeToPrice( $row[ 'QuantityBaseTraded' ], $tradeable, $currency ) - $row[ 'QuantityBaseTraded' ] ),
        'total' => $row[ 'QuantityBaseTraded' ],
      );
    }

    foreach ( array_keys( $results ) as $market ) {
      usort( $results[ $market ], 'compareByTime' );
    }

    return $results;
  }

  public function cancelOrder( $orderID ) {

    try {
      $this->queryCancelOrder( $orderID );

      $options = array(
	'market' => 'ALL',
	'orderstatus' => 'CANCELED',
	'ordertype' => 'ALL',
	'depth' => 20000, // max!
      );
      $result = $this->queryAPI( 'account/getorders', $options );

      foreach ( $result as $row ) {
        if ( $row[ 'OrderId' ] == $orderID ) {
          return true; // Cancellation succeeded.
        }
      }

      // Cancellation failed.
      return false;
    }
    catch ( Exception $ex ) {
      return false;
    }

  }

  public function getSmallestOrderSize( $tradeable, $currency, $type ) {

    return '0.00050000';

  }
  public function getID() {

    return Bleutrade::ID;

  }
  public function getName() {

    return "BLEUTRADE";

  }

  protected function getPublicURL() {

    return Bleutrade::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return BleuTrade::PRIVATE_URL;

  }

}
