<?php

require_once 'bot/utils.php';
require_once 'bot/Config.php';
require_once 'bot/CoinManager.php';
require_once 'bot/Database.php';
require_once 'bot/Exchange.php';
require_once 'bot/xchange/Bittrex.php';
require_once 'bot/xchange/Poloniex.php';

function allPermutations($inArray, $processedArray = array()) {
  $return = array();
  foreach ( $inArray as $key => $value ) {
    $copy = $processedArray;
    $copy[ $key ] = $value;
    $tempArray = array_diff_key( $inArray, $copy );
    if (count( $tempArray ) == 0) {
      $return[] = $copy;
    } else {
      $return = array_merge( $return, allPermutations( $tempArray, $copy ) );
    }
  }
  return $return;
}

function checkPermutations(&$matches, &$fractions, $amount) {
  foreach ( allPermutations( $fractions ) as $perm ) {
    // We check all permutations since we check the sum in each iteration of the
    // loop, and we want to take all possibilities into account.
    $sum = 0;
    $arr = array( );
    foreach ( $perm as $item ) {
      $arr[] = $item;
      $amount = $item[ 'amount' ];
      $sum += $amount;
      $ratio = max( $amount, $sum ) / min( $amount, $sum );
      if ($ratio <= 1.0025) {
        $matches = $arr;
        return true;
      }
    }
  }
  return false;
}

function checkTradeSide(&$matches, &$histories, &$row, $name ) {

  foreach ( $histories as $item ) {
    if ($item[ 'amount' ] == $row[ $name ] &&
        abs( $item[ 'time' ] - $row[ 'time' ] ) < 60) {
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
      if (abs( $item[ 'time' ] - $row[ 'time' ] ) < 60) {
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
      return true;
    }
  }

  return false;
}

function importProfitLoss() {
  $prompt = file_get_contents( __DIR__ . '/import-profit-loss-prompt.txt' );
  print "$prompt\n";
  readline();

/*
  if (defined( 'HHVM_VERSION' )) {
    // The import code crashes hhvm and results in a partial import, so refuse
    // to run under hhvm.

    die("Running this import process under HHVM isn't supported, because of potential\n" .
        "crashes in HHVM during the import phase.  Please try running the bot under the\n" .
        "regular PHP interpreter for this phase.  After the import is done, you can stop\n" .
        "the bot and restart it under HHVM if you want.\n\nExiting.\n");
  }
*/

  if (!Database::createProfitLossTable()) {
    throw new Exception( 'import process failed' );
  }
  
  doImport();

  if (!Database::profitLossTableExists()) {
    throw new Exception( 'import process failed' );
  }
  print "The import process finished successfully, resuming\n";
}

function doImport() {
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
  
  $cm = new CoinManager( $exchanges );
  
  $pl = Database::getPL();
  foreach ( $pl as $row ) {
    // Go through the PL database and try to find matching trades.
    $market = $row[ 'currency' ] . '_' . $row[ 'coin' ];
    $buy_matches = array( );
    $sell_matches = array( );
    checkTradeSide( $buy_matches, $histories[ $row[ 'source_exchange' ] ][ $market ],
                    $row, 'tradeable_bought' );
    checkTradeSide( $sell_matches, $histories[ $row[ 'target_exchange' ] ][ $market ],
                    $row, 'tradeable_sold' );
  
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
    $totalSoldAmount = 0;
    $currencyBought = 0;
    $currencySold = 0;
    $currencyRevenue = 0;
    $currencyProfitLoss = 0;
    $tradeableTransferFee = 0;
    $currencyTransferFee = 0;
    $buyFee = 0;
    $sellFee = 0;
  
    foreach ($buy_matches as $match) {
      $rawTradeIDsBuy[] = $match[ 'rawID' ];
      $tradeIDsBuy[] = $match[ 'id' ];
      $rateTimesAmountBuy += $match[ 'rate' ] * $match[ 'amount' ];
      $tradeableBought += $match[ 'amount' ];
      $currencyBought += $match[ 'total' ] * (1 + $match[ 'fee' ]);
      $ex = $exchanges[ $row[ 'source_exchange' ] ];
      $boughtAmount = $ex->deductFeeFromAmountBuy( $match[ 'amount' ] );
      $txFee = $cm->getSafeTxFee( $ex, $coin, $boughtAmount );
      $tradeableTransferFee = max( $tradeableTransferFee, $txFee );
      $buyFee += $match[ 'fee' ];
    }
  
    $rawTradeIDsBuy = implode( ',', $rawTradeIDsBuy );
    $tradeIDsBuy = implode( ',', $tradeIDsBuy );
    $rateBuy = $tradeableBought ? ($rateTimesAmountBuy / $tradeableBought) : 0;
  
    foreach ($sell_matches as $match) {
      $rawTradeIDsSell[] = $match[ 'rawID' ];
      $tradeIDsSell[] = $match[ 'id' ];
      $rateTimesAmountSell += $match[ 'rate' ] * $match[ 'amount' ];
      $tradeableSold += $match[ 'amount' ];
      $currencySold += $match[ 'total' ] * (1 + $match[ 'fee' ]);
      $ex = $exchanges[ $row[ 'target_exchange' ] ];
      $soldAmount = $ex->deductFeeFromAmountSell( $match[ 'amount' ] );
      $totalSoldAmount += $soldAmount;
      $txFee = $cm->getSafeTxFee( $ex, $coin, $soldAmount );
      $sellFee += $match[ 'fee' ];
    }
  
    $rawTradeIDsSell = implode( ',', $rawTradeIDsSell );
    $tradeIDsSell = implode( ',', $tradeIDsSell );
    $rateSell = $totalSoldAmount ? ($rateTimesAmountSell / $totalSoldAmount) : 0;
  
    $currencyTransferFee = $tradeableTransferFee * $rateSell;
    $currencyRevenue = $currencySold + $sellFee - $currencyBought - $buyFee;
    $currencyProfitLoss = $currencyRevenue - $currencyTransferFee;
  
    Database::saveProfitLoss( $coin, $currency, $time, $sourceExchange, $targetExchange,
                              $rawTradeIDsBuy, $tradeIDsBuy, $rawTradeIDsSell, $tradeIDsSell,
                              $rateBuy, $rateSell, $tradeableBought, $tradeableSold, $currencyBought,
                              $currencySold, $currencyRevenue, $currencyProfitLoss, $tradeableTransferFee,
                              $currencyTransferFee, $buyFee, $sellFee );
  
  }
}
