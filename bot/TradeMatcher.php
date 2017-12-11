<?php

require_once 'bot/utils.php';
require_once 'bot/Config.php';
require_once 'bot/Exchange.php';

class TradeMatcher {

  private $exchanges;

  function __construct( &$exchanges ) {

    $this->exchanges = array( );
    foreach ( $exchanges as $ex ) {
      $this->exchanges[ $ex->getID() ] = $ex;
    }

  }

  public function getExchangeNewTrades( $id ) {
    if ( !isset( $this->exchanges[ $id ] ) ) {
      logg( "WARNING: Invalid exchange ID passed: $id" );
      return array( );
    }
    $ex = &$this->exchanges[ $id ];
    $hist = $ex->queryTradeHistory( array( ), true );
    $tradeIDs = array( );
    $map = array( );
    foreach ( $hist as $market => &$data ) {
      $arr = explode( '_', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      foreach ( $data as $row ) {
        $tradeIDs[] = $row[ 'rawID' ];
        $map[ $row[ 'rawID' ] ] = $row;
      }
    }

    $newIDs = Database::getNewTrades( $tradeIDs );
    $result = array( );
    foreach ( $newIDs as $id ) {
      $result[] = $map[ $id ];
    }
    return $result;
  }

  public function hasExchangeNewTrades( $id ) {
    return count( $this->getExchangeNewTrades( $id ) ) > 0;
  }

  public function saveTrade( $exchangeID, $tradeable, $currency, $trade ) {

    Database::saveExchangeTrade( $exchangeID, $tradeable, $currency, $trade[ 'time' ],
                                 $trade[ 'rawID' ], $trade[ 'id' ], $trade[ 'rate' ],
                                 $trade[ 'amount' ], $trade[ 'fee' ], $trade[ 'total' ] );

  }

}

