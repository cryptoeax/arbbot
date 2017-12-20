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
                                                          $tradeableDifference ) {

    $pendingInitialDeposits = array();
    foreach ( $depositsBefore as $dep ) {
      if ( $dep[ 'currency' ] != $tradeable || !$dep[ 'pending' ] ) {
        continue;
      }
      $pendingInitialDeposits[] = $dep;
    }

    $pendingInitialWithdrawals = array();
    foreach ( $withdrawalsBefore as $dep ) {
      if ( $dep[ 'currency' ] != $tradeable || !$dep[ 'pending' ] ) {
        continue;
      }
      $pendingInitialWithdrawals[] = $dep;
    }

    foreach ( $pendingInitialDeposits as $dep ) {
      logg( sprintf( "Initial pending deposit while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    foreach ( $pendingInitialWithdrawals as $dep ) {
      logg( sprintf( "Initial pending withdrawal while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    $finishedDepositsAtEnd = array();
    foreach ( $depositsAfter as $dep ) {
      if ( $dep[ 'currency' ] != $tradeable || $dep[ 'pending' ] ) {
        continue;
      }
      $finishedDepositsAtEnd[] = $dep;
    }

    $finishedWithdrawalsAtEnd = array();
    foreach ( $withdrawalsAfter as $dep ) {
      if ( $dep[ 'currency' ] != $tradeable || $dep[ 'pending' ] ) {
        continue;
      }
      $finishedWithdrawalsAtEnd[] = $dep;
    }

    foreach ( $finishedDepositsAtEnd as $dep ) {
      logg( sprintf( "Finished deposits at end while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    foreach ( $finishedWithdrawalsAtEnd as $dep ) {
      logg( sprintf( "Finished withdrawals at end while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    $finishedDeposits = array();
    foreach ( $pendingInitialDeposits as $dep1 ) {
      foreach ( $finishedDepositsAtEnd as $dep2 ) {
        if ( $dep1[ 'txid' ] != $dep2[ 'txid' ] ) {
          continue;
        }
        $finishedDeposits[] = $dep2;
      }
    }

    $finishedWithdrawals = array();
    foreach ( $pendingInitialWithdrawals as $dep1 ) {
      foreach ( $finishedWithdrawalsAtEnd as $dep2 ) {
        if ( $dep1[ 'txid' ] != $dep2[ 'txid' ] ) {
          continue;
        }
        $finishedWithdrawals[] = $dep2;
      }
    }

    foreach ( $finishedDeposits as $dep ) {
      logg( sprintf( "Finished deposits while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    foreach ( $finishedWithdrawals as $dep ) {
      logg( sprintf( "Finished withdrawal while matching trades: %.8f %s (%s)",
                     formatBTC( $dep[ 'amount' ] ), $dep[ 'currency' ],
                     $dep[ 'txid' ] ) );
    }

    $tradeableDifference = abs( $tradeableDifference );
    $finishedDepositSum = array_reduce( $finishedDeposits, 'sumOfAmount', 0 );
    $finishedWithdrawalSum = array_reduce( $finishedWithdrawals, 'sumOfAmount', 0 );
    $tradesSum = array_reduce( $trades, 'sumOfAmount', 0 );
    $netBalanceDiff = $tradeableDifference - $finishedDepositSum + $finishedWithdrawalSum;

    logg( sprintf( "Received %.8f %s from the exchange while our wallets show a " .
                   "balance difference of %.8f (%.8f - %.8f finished deposits " .
                   "%.8f finished withdrawals)",
                   formatBTC( $tradesSum ), $tradeable, formatBTC( $netBalanceDiff ),
                   formatBTC( $tradeableDifference ), formatBTC( $finishedDepositSum ),
                   formatBTC( $finishedWithdrawals )
    ) );

    return $tradesSum == 0 ||
           abs( abs( $tradesSum / $netBalanceDiff ) - 1 ) <= $exchange->addFeeToPrice( 1 );

  }

  function handlePostTradeTasks( &$arbitrator, &$exchange, $coin, $type, $tradeableBefore ) {

    while (true) {
      $exchange->refreshWallets();

      $newPendingDeposits = $exchange->queryRecentDeposits( $coin );
      $tradeableAfter = $exchange->getWallets()[ $coin ];
      $tradeableDifference = $tradeableAfter - $tradeableBefore;
      $tradeMatcher = &$arbitrator->getTradeMatcher();

      $trades = $tradeMatcher->getExchangeNewTrades( $exchange->getID() );
      $trades = array_filter( $trades, function( $trade ) use ( $coin, $type ) {
        if ( $trade[ 'tradeable' ] != $coin ) {
          logg( sprintf( "WARNING: Got an unrelated trade while trying to perfrom post-trade tasks: %s of %.8f %s at %.8f, saved but will ignore",
                         $type, formatBTC( $trade[ 'amount' ] ),
                         $trade[ 'tradeable' ], $trade[ 'rate' ] ) );
          return false;
        }
        return true;
      } );
      $matched = $tradeMatcher->matchTradesConsideringPendingTransfers( $trades, $coin, $type, $exchange,
                                                                        $arbitrator->getLastRecentDeposits()[ $exchange->getID() ],
                                                                        $arbitrator->getLastRecentWithdrawals()[ $exchange->getID() ],
                                                                        $newPendingDeposits,
                                                                        $newPendingWithdrawals,
                                                                        $tradeableDifference );
      if ( $matched ) {
        break;
      }
      logg( "WARNING: not reciving all $type trades from the exchange in time, waiting a bit and retrying..." );
      usleep( 500000 );
    }

    foreach ( $trades as $trade ) {
      $tradeMatcher->saveTrade( $exchange->getID(), $type, $trade[ 'tradeable' ],
                                $trade[ 'currency' ], $trade );
    }

    return $trades;

  }

}

