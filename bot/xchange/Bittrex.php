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

  public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
    $results = array( );

    $type_map = array(
      'LIMIT_BUY' => 'buy',
      'LIMIT_SELL' => 'sell',
    );

    if (!$recentOnly && $this->fullOrderHistory !== null) {
      $results = $this->fullOrderHistory;
    } else if (!$recentOnly && !$this->fullOrderHistory &&
               file_exists( __DIR__ . '/../../bittrex-fullOrders.csv' )) {
      $file = file_get_contents( __DIR__ . '/../../bittrex-fullOrders.csv' );
      $file = iconv( 'utf-16', 'utf-8', $file );
      $lines = explode( "\r\n", $file );
      $first = true;
      foreach ($lines as $line) {
        if ($first) {
          // Ignore the first line.
          $first = false;
          continue;
        }
        $data = str_getcsv( $line );
        if (count( $data ) != 9) {
          continue;
        }
	$market = $data[ 1 ];
	$arr = explode( '-', $market );
	$currency = $arr[ 0 ];
	$tradeable = $arr[ 1 ];
	$market = "${currency}_${tradeable}";
	$amount = $data[ 3 ];
	$feeFactor = ($data[ 2 ] == 'LIMIT_SELL') ? -1 : 1;
	$results[ $market ][] = array(
	  'rawID' => $data[ 0 ],
	  'id' => $data[ 0 ],
	  'currency' => $currency,
	  'tradeable' => $tradeable,
	  'type' => $type_map[ $data[ 2 ] ],
	  'time' => strtotime( $data[ 7 ] ),
	  'rate' => $data[ 6 ] / $amount,
	  'amount' => $amount,
	  'fee' => $feeFactor * $data[ 5 ],
	  'total' => $data[ 6 ],
	);
      }
      $this->fullOrderHistory = $results;
    }

    $result = $this->queryAPI( 'account/getorderhistory' );

    $checkArray = !empty( $results );

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

      if ($checkArray) {
        $seen = false;
        foreach ($results[ $market ] as $item) {
          if ($item[ 'rawID' ] == $row[ 'OrderUuid' ]) {
            // We have already recorder this ID.
            $seen = true;
            break;
          }
        }
        if ($seen) {
          continue;
        }
      }

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

    // TODO: Use MinTradeSize (see refreshExchangeData)
    return '0.00100000';

  }

  public function getID() {

    return Bittrex::ID;

  }

  public function getName() {

    return "BITTREX";

  }

  public function getTradeHistoryCSVName() {

    return "bittrex-fullOrders.csv";

  }

  protected function getPublicURL() {

    return Bittrex::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return Bittrex::PRIVATE_URL;

  }

}
