<?php

require_once 'Math/Combinatorics.php';

require_once 'bot/utils.php';
require_once 'bot/Config.php';
require_once 'bot/CoinManager.php';
require_once 'bot/Database.php';
require_once 'bot/Exchange.php';
require_once 'bot/xchange/Bittrex.php';
require_once 'bot/xchange/Poloniex.php';

function checkItems(&$matches, &$indices, &$fractions, $amount) {
  $sum = 0;

  foreach ( $indices as $index ) {
    $item = $fractions[ $index ];
    $sum += $item[ 'amount' ];
  }

  return ($sum / $amount);
}

function checkPermutations(&$matches, &$fractions, $amount) {
  $combinatorics = new Math_Combinatorics();

  $minDifference = 0xffffffff;
  $minFractions = null;

  $lowestHigher = 0xffffffff;
  $lowestHigherFraction = null;
  $highestLower = 0;
  $highestLowerFraction = null;

  // Iterate over all possible trade combinations.  For each combination, compute the diff
  // of the sum and the amount of the trades we're trying to match.  Try to minimize number
  // inside the loop.
  // After we're done, determine success based on how large the difference is.
  // If the sum is greater than 1% of the amount, then we consider the result as failure.
  // If the sum is less than the amount, then we order wasn't completely filled, so we just
  // return the largest sum that we have.
  for ( $count = 1; $count <= count( $fractions ); ++$count ) {
    $perms = $combinatorics->combinations( range( 0, count( $fractions ) - 1 ), $count );
    foreach ( $perms as $indices ) {
      $diff = checkItems( $matches, $indices, $fractions, $amount );
      if ($diff > 1 && $diff < $lowestHigher) {
        $lowestHigher = $diff;
        $lowestHigherFraction = array( );
	foreach ( $indices as $index ) {
	  $lowestHigherFraction[] = $fractions[ $index ];
	}
      } else if ($diff < 1 && $diff > $highestLower) {
        $highestLower = $diff;
        $highestLowerFraction = array( );
	foreach ( $indices as $index ) {
	  $highestLowerFraction[] = $fractions[ $index ];
	}
      } else if ($diff == 1) {
	$matches = array( );
	foreach ( $indices as $index ) {
	  $matches[] = $fractions[ $index ];
	}
        return true;
      }
    }
  }
  if ($lowestHigher < 1.01) {
    $matches = $lowestHigherFraction;
    return true;
  }
  if ($highestLower > 0) {
    $matches = $highestLowerFraction;
    return true;
  }
  return false;
}

function checkTradeSide(&$matches, &$histories, &$row, $name ) {

  foreach ( $histories as $item ) {
    if ($item[ 'amount' ] == $row[ $name ] &&
        // Look in a 5-minute window
        abs( $item[ 'time' ] - $row[ 'time' ] ) < 5 * 60) {
      $matches[] = $item;
    }
  }

  if (count( $matches ) == 1) {
    return true;
  }

  if (count( $matches ) == 0) {
    // The trade may have happened in a few iterations
    $fractions = array( );
    foreach ( $histories as $item ) {
      if (abs( $item[ 'time' ] - $row[ 'time' ] ) < 5 * 60) {
        $fractions[] = $item;
      }
    }

    if (count( $fractions ) > 0) {
      if ( checkPermutations( $matches, $fractions, $row[ $name ] ) ) {
        return true;
      }

      // Now we have a situation where the amount of tradeables trades according
      // to the exchange doesn't match what's recorded in our log.
      // Our log may be inaccurate due to issues such as in-flight transfers
      // arriving while a trade is in progress, so trust the exchange side.
      // At this point our best guess is to pick everything is fractions.
      $matches = $fractions;
      foreach ( $fractions as $item ) {
        foreach ( $histories as $key => $value ) {
          if ( $item[ 'rawID' ] == $value[ 'rawID' ] ) {
            unset( $histories[ $key ] );
            break;
          }
        }
      }
      return true;
    }
  }

  return false;
}

function importProfitLoss() {
  $prompt = file_get_contents( __DIR__ . '/import-profit-loss-prompt.txt' );
  logg( $prompt );
  readline();

  if (!Database::createProfitLossTable()) {
    throw new Exception( 'import process failed' );
  }
  
  doImportFromDB();

  if (!Database::profitLossTableExists()) {
    throw new Exception( 'import process failed' );
  }
  logg( "The import process finished successfully, resuming" );
}

function doImportFromDB() {
  $pl = Database::getPL();
  $x1 = new Bittrex();
  $x2 = new Poloniex();
  $x1->refreshWallets();
  $x2->refreshWallets();
  $x1->refreshExchangeData();
  $x2->refreshExchangeData();
  $exchanges = array(
    '1' => $x2,
    '3' => $x1,
  );
  
  $hist1 = $x1->queryTradeHistory();
  $hist2 = $x2->queryTradeHistory(array(
    'start' => strtotime( '1/1/1970 1:1:1' ),
    'end' => time(),
  ));
  $histories = array(
    '1' => &$hist2,
    '3' => &$hist1,
  );

  foreach ( $histories as $id => &$hist ) {
    foreach ( $hist as $market => $data ) {
      $arr = explode( '_', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      foreach ( $data as $row ) {
        Database::saveExchangeTrade( $id, $tradeable, $currency, $row[ 'time' ],
                                     $row[ 'rawID' ], $row[ 'id' ], $row[ 'rate' ],
                                     $row[ 'amount' ], $row[ 'fee' ], $row[ 'total' ] );
      }
    }
  }
  
  foreach ( $pl as $row ) {
    $coin = $row[ 'coin' ];
    $currency = $row[ 'currency' ];
    $time = $row[ 'time' ];
    $sourceExchange = $row[ 'source_exchange' ];
    $targetExchange = $row[ 'target_exchange' ];
    $rawTradeIDsBuy = array( );
    $tradeIDsBuy = array( );
    $rawTradeIDsSell = array( );
    $tradeIDsSell = array( );
    $rateTimesAmountBuy = 0;
    $rateTimesAmountSell = 0;
    $tradeableBought = 0;
    $tradeableSold = 0;
    $currencyBought = 0;
    $currencySold = 0;
    $currencyRevenue = 0;
    $currencyProfitLoss = 0;
    $tradeableTransferFee = 0;
    $currencyTransferFee = 0;
    $buyFee = 0;
    $sellFee = 0;

    $ex = $exchanges[ $row[ 'source_exchange' ] ];
    $rawTradeIDsBuy = '';
    $tradeIDsBuy = '';
    $rateTimesAmountBuy = $row[ 'currency_bought' ];
    $tradeableBought = $ex->deductFeeFromAmountSell( $row[ 'tradeable_bought' ] );
    $currencyBought = $ex->deductFeeFromAmountSell( $row[ 'currency_bought' ] );
    $boughtAmount = $ex->deductFeeFromAmountSell( $row[ 'amount_bought_tradeable' ] );
    $buyFee = $currencyBought - $ex->addFeeToPrice( $currencyBought );
    $rateBuy = ($tradeableBought > 0.0e-9) ? ($rateTimesAmountBuy / $tradeableBought) : 0;
  
    $ex = $exchanges[ $row[ 'target_exchange' ] ];
    $rawTradeIDsSell = '';
    $tradeIDsSell = '';
    $rateTimesAmountSell = $row[ 'currency_sold' ];
    $tradeableSold = $row[ 'tradeable_sold' ];
    $currencySold = $row[ 'currency_sold' ];
    $soldAmount = $row[ 'amount_sold_tradeable' ];
    $sellFee = $row[ 'currency_sold' ] - $ex->addFeeToPrice( $row[ 'currency_sold' ] );
    $rateSell = ($tradeableSold > 0.0e-9) ? ($rateTimesAmountSell / $tradeableSold) : 0;
  
    $tradeableTransferFee = $row[ 'tx_fee_tradeable' ];
    $currencyTransferFee = $tradeableTransferFee * $rateSell;
    $currencyRevenue = $row[ 'currency_revenue' ];
    $currencyProfitLoss = $currencyRevenue - $currencyTransferFee;
  
    Database::saveProfitLoss( $coin, $currency, $time, $sourceExchange, $targetExchange,
                              $rawTradeIDsBuy, $tradeIDsBuy, $rawTradeIDsSell, $tradeIDsSell,
                              $rateBuy, $rateSell, $tradeableBought, $tradeableSold, $currencyBought,
                              $currencySold, $currencyRevenue, $currencyProfitLoss, $tradeableTransferFee,
                              $currencyTransferFee, $buyFee, $sellFee );
  
  }
}

