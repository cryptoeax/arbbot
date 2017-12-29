<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../BittrexLikeExchange.php';

class Bleutrade extends BittrexLikeExchange {
  
  const ID = 2;
  //
  const PUBLIC_URL = 'https://bleutrade.com/api/v2/public/';
  const PRIVATE_URL = 'https://bleutrade.com/api/v2/';
  
  private $fullOrderHistory = null;
  
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

    $type_map = array(
      'LIMIT_BUY' => 'buy',
      'LIMIT_SELL' => 'sell',
    );

    if (!$recentOnly && $this->fullOrderHistory !== null) {
      $results = $this->fullOrderHistory;
    } else if (!$recentOnly && !$this->fullOrderHistory &&
               file_exists( __DIR__ . '/../../bleutrade-fullOrders.csv' )) {
      $file = file_get_contents( __DIR__ . '/../../bleutrade-fullOrders.csv' );
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

///// TODO: Check "OrderUuid", it's probably OrderId...

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

///// TODO: Check "OrderUuid", it's probably OrderId...

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

  public function queryRecentDeposits( $currency = null ) {

    $history = $this->queryAPI( 'account/getdeposithistory',
                                $currency ? array ( 'currency' => $currency ) : array( ) );

    $result = array();
    foreach ( $history as $row ) {
      $result[] = array(
        'currency' => $row[ 'Currency' ],
        'amount' => $row[ 'Amount' ],
        'txid' => $row[ 'TxId' ],
        'address' => $row[ 'CryptoAddress' ],
        'time' => strtotime( $row[ 'LastUpdated' ] ),
        'pending' => ( $row[ 'Confirmations' ] < $this->getConfirmationTime( $row[ 'Currency' ] ) ),
      );
    }

    usort( $result, 'compareByTime' );

    return $result;

  }

  public function queryRecentWithdrawals( $currency = null ) {

    $history = $this->queryAPI( 'account/getwithdrawalhistory',
                                $currency ? array ( 'currency' => $currency ) : array( ) );

    $result = array();
    foreach ( $history as $row ) {
      $result[] = array(
        'currency' => $row[ 'Currency' ],
        'amount' => $row[ 'Amount' ],
        'txid' => $row[ 'TxId' ],
        'address' => $row[ 'Address' ],
        'time' => strtotime( $row[ 'Opened' ] ),
        'pending' => $row[ 'PendingPayment' ] === true,
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

    return null;

  }

  protected function getPublicURL() {

    return Bleutrade::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return BleuTrade::PRIVATE_URL;

  }

}
