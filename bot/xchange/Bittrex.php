<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../BittrexLikeExchange.php';

class Bittrex extends BittrexLikeExchange {

  const ID = 3;
  //
  const PUBLIC_URL = 'https://bittrex.com/api/v1.1/public/';
  const PRIVATE_URL = 'https://bittrex.com/api/v1.1/';

  private $fullOrderHistory = null;

  function __construct() {
    parent::__construct( Config::get( "bittrex.key" ), Config::get( "bittrex.secret" ),
                         array(
      'separator' => '-',
      'offsetCurrency' => 0,
      'offsetTradeable' => 1,
    ), 'OrderUuid', 'uuid', 'both' );

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
    $market = $currency . '-' . $tradeable;
    $result = $this->queryAPI( 'account/getorderhistory', [ 'market' => $market ] );
    $id = trim( $id, '{}' );

    foreach ($result as $order) {
      if ($order[ 'OrderUuid' ] == $id) {
        if ($order[ 'QuantityRemaining' ] != 0) {
          logg( $this->prefix() . "Order " . $id . " assumed to be filled but " . $order[ 'QuantityRemaining' ] . " still remaining" );
        }
        $factor = ($type == 'sell') ? -1 : 1;
        return $order[ 'Price' ] + $factor * $order[ 'Commission' ];
      }
    }
    return null;
  }

  public function queryTradeHistory( $options = array( ) ) {
    $results = array( );

    $type_map = array(
      'LIMIT_BUY' => 'buy',
      'LIMIT_SELL' => 'sell',
    );

    $result = $this->queryAPI( 'account/getorderhistory' );

    foreach ($result as $row) {
      $market = $row[ 'Exchange' ];
      $arr = explode( '-', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      $market = "${currency}_${tradeable}";
      if (!in_array( $market, array_keys( $results ) )) {
        $results[ $market ] = array();
      }
      $amount = $row[ 'Quantity' ] - $row[ 'QuantityRemaining' ];
      $feeFactor = ($row[ 'OrderType' ] == 'LIMIT_SELL') ? -1 : 1;

      $results[ $market ][] = array(
        'rawID' => $row[ 'OrderUuid' ],
        'id' => $row[ 'OrderUuid' ],
        'currency' => $currency,
        'tradeable' => $tradeable,
        'type' => $type_map[ $row[ 'OrderType' ] ],
        'time' => strtotime( $row[ 'TimeStamp' ] ),
        'rate' => $row[ 'PricePerUnit' ],
        'amount' => $amount,
        'fee' => $feeFactor * $row[ 'Commission' ],
        'total' => $row[ 'Price' ],
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
      return true;
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'ORDER_NOT_OPEN' ) === false ) {
	logg( $this->prefix() . "Got an exception in cancelOrder(): " . $ex->getMessage() );
	return true;
      }
      return false;
    }

  }

  public function getSmallestOrderSize( $tradeable, $currency, $type ) {

    return '0.00100000';

  }

  public function getID() {

    return Bittrex::ID;

  }

  public function getName() {

    return "BITTREX";

  }

  protected function getPublicURL() {

    return Bittrex::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return Bittrex::PRIVATE_URL;

  }

}
