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

  public function saveTrade( $exchangeID, $type, $tradeable, $currency, $trade ) {

    Database::saveExchangeTrade( $exchangeID, $type, $tradeable, $currency, $trade[ 'time' ],
                                 $trade[ 'rawID' ], $trade[ 'id' ], $trade[ 'rate' ],
                                 $trade[ 'amount' ], $trade[ 'fee' ], $trade[ 'total' ] );

  }

  public function saveProfitLoss( &$source, &$target, &$buyTrades, &$sellTrades, &$cm ) {

    $tradeable = null;
    $currency = null;

    $rawTradeIDsBuy = array( );
    $tradeIDsBuy = array( );
    $rawTradeIDsSell = array( );
    $tradeIDsSell = array( );

    $rateTimesAmountBuy = 0;
    $tradeableBought = 0;
    $currencyBought = 0;
    $buyFee = 0;
    $rateTimesAmountSell = 0;
    $tradeableSold = 0;
    $currencySold = 0;
    $sellFee = 0;

    $tradeableTransferFee = 0;

    foreach ( $buyTrades as $trade ) {
      if ( !is_null( $tradeable ) && $tradeable != $trade[ 'tradeable' ] ) {
        logg( "WARNING: While looking through buy trades, found a ${trade['tradeable']} trade but we first saw $tradeable! Ignoring..." );
        continue;
      }

      if ( !is_null( $currency ) && $currency != $trade[ 'currency' ] ) {
        logg( "WARNING: While looking through buy trades, found a ${trade['currency']} trade but we first saw $currency! Ignoring..." );
        continue;
      }

      $tradeable = $trade[ 'tradeable' ];
      $currency = $trade[ 'currency' ];

      $rawTradeIDsBuy[] = $trade[ 'rawID' ];
      $tradeIDsBuy[] = $trade[ 'id' ];

      $rateTimesAmountBuy += $trade[ 'rate' ] * $trade[ 'amount' ];
      $tradeableBought += $trade[ 'amount' ];
      $currencyBought += $trade[ 'total' ];
      // Fee is positive for "buy" trades.
      $buyFee = -$trade[ 'fee' ];

      $boughtAmount = $source->deductFeeFromAmountBuy( $trade[ 'amount' ] );
      $txFee = $cm->getSafeTxFee( $source, $trade[ 'tradeable' ], $boughtAmount );
      $tradeableTransferFee = max( $tradeableTransferFee, $txFee );
    }
    foreach ( $sellTrades as $trade ) {
      if ( !is_null( $tradeable ) && $tradeable != $trade[ 'tradeable' ] ) {
        logg( "WARNING: While looking through sell trades, found a ${trade['tradeable']} trade but we first saw $tradeable! Ignoring..." );
        continue;
      }

      if ( !is_null( $currency ) && $currency != $trade[ 'currency' ] ) {
        logg( "WARNING: While looking through sell trades, found a ${trade['currency']} trade but we first saw $currency! Ignoring..." );
        continue;
      }

      $tradeable = $trade[ 'tradeable' ];
      $currency = $trade[ 'currency' ];

      $rawTradeIDsSell[] = $trade[ 'rawID' ];
      $tradeIDsSell[] = $trade[ 'id' ];

      $rateTimesAmountSell += $trade[ 'rate' ] * $trade[ 'amount' ];
      $tradeableSold += $trade[ 'amount' ];
      $currencySold += $trade[ 'total' ];
      // Fee is negative for "buy" trades.
      $sellFee = $trade[ 'fee' ];

      $soldAmount = $target->deductFeeFromAmountSell( $trade[ 'amount' ] );
      $txFee = $cm->getSafeTxFee( $target, $trade[ 'tradeable' ], $soldAmount );
      $tradeableTransferFee = max( $tradeableTransferFee, $txFee );
    }

    $time = time();
    $rawTradeIDsBuy = implode( ',', $rawTradeIDsBuy );
    $tradeIDsBuy = implode( ',', $tradeIDsBuy );
    $rawTradeIDsSell = implode( ',', $rawTradeIDsSell );
    $tradeIDsSell = implode( ',', $tradeIDsSell );

    $rateBuy = ($tradeableBought > 0) ? ($rateTimesAmountBuy / $tradeableBought) : 0;
    $rateSell = ($tradeableSold > 0) ? ($rateTimesAmountSell / $tradeableSold) : 0;

    $currencyTransferFee = $tradeableTransferFee * $rateSell;
    $currencyRevenue = $currencySold - $currencyBought;
    $currencyProfitLoss = $currencyRevenue - $currencyTransferFee + $sellFee + $buyFee;

    Database::saveProfitLoss( $tradeable, $currency, $time, $source->getID(), $target->getID(),
                              $rawTradeIDsBuy, $tradeIDsBuy, $rawTradeIDsSell, $tradeIDsSell,
                              $rateBuy, $rateSell, $tradeableBought, $tradeableSold,
                              $currencyBought, $currencySold, $currencyRevenue, $currencyProfitLoss,
                              $tradeableTransferFee, $currencyTransferFee, $buyFee, $sellFee );


    return $currencyProfitLoss;
  }

  public function matchTradesConsideringPendingTransfers( $trades, $tradeable, $type, $exchange,
                                                          $depositsBefore, $withdrawalsBefore,
                                                          $depositsAfter, $withdrawalsAfter,
                                                          $tradeAmount ) {

    $tradesSum = array_reduce( $trades, 'sumOfAmount', 0 );

    logg( sprintf( "Received %f %s from the exchange while waiting for a trade of %f",
                   $tradesSum, $tradeable, $tradeAmount ) );


    return $tradesSum == 0 ||
           abs( abs( $tradesSum / $tradeAmount ) - 1 ) <= $exchange->addFeeToPrice( 1 );

  }

  function handlePostTradeTasks( &$arbitrator, &$exchange, $coin, $currency, $type,
                                 $orderID, $tradeAmount ) {

    $trades = $exchange->getRecentOrderTrades( $arbitrator, $coin, $currency, $type,
                                               $orderID, $tradeAmount );
    foreach ( $trades as $trade ) {
      $arbitrator->getTradeMatcher()->saveTrade( $exchange->getID(), $type, $trade[ 'tradeable' ],
                                                 $trade[ 'currency' ], $trade );
    }

    return $trades;

  }

}

