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

  return $sum - $amount;
}

function checkPermutations(&$matches, &$fractions, $amount) {
  $combinatorics = new Math_Combinatorics();

  $minDifference = 0xffffffff;
  $minFractions = null;
  // Iterate over all possible trade combinations.  For each combination, compute the diff
  // of the sum and the amount of the trades we're trying to match.  Try to minimize number
  // inside the loop.
  // After we're done, determine success based on how large the difference is.
  // If the sum is greater than 1% of the amount, then we consider the result as failure.
  // If the sum is less than the amount, then we order wasn't completely filled, so we just
  // return what we have.
  for ( $count = count( $fractions ); $count >= 1; --$count ) {
    $perms = $combinatorics->combinations( range( 0, count( $fractions ) - 1 ), $count );
    foreach ( $perms as $indices ) {
      $diff = checkItems( $matches, $indices, $fractions, $amount );
      if ($diff < $minDifference) {
        $minFractions = &$fractions;
        $minDifference = $diff;
      }
    }
  }
  if (is_null( $minFractions ) ||
      $minDifference > 1.01 * $amount) {
    return false;
  }
  $matches = $minFractions;
  return true;
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
  
  startImportJobs();

  if (!Database::profitLossTableExists()) {
    throw new Exception( 'import process failed' );
  }
  logg( "The import process finished successfully, resuming" );
}

function startImportJobs() {
  $pl = Database::getPL();
  $ser = serialize( $pl );
  $tmp = tempnam( "/tmp", "profitloss" );
  file_put_contents( $tmp, $ser );

  try {
    // The import process is very memory consuming, so we run it in subprocesses.
    // We use one subprocess per 100-PL rows.
    define( 'ROWS_PER_JOB', 100 );
    for ($i = 0; $i < count( $pl ); $i += ROWS_PER_JOB) {
      print "\rImported $i out of " . count( $pl ) . " transactions...";

      $command = $_SERVER[ '_' ] . ' "' . __FILE__ .
                 "\" import-worker \"$tmp\" $i " .
                 min( count( $pl ), $i + ROWS_PER_JOB );
      $fp = popen( $command, "r" );
      while (!feof( $fp )) {
        $buffer = fgets( $fp, 4096 );
        if (strlen( $buffer )) {
          logg( "[CHILD WORKER] " . rtrim( $buffer ) );
        }
      }
      pclose( $fp );
      sleep( 10 ); // allow some time to pass so the next process gets a different nonce
    }
  } finally {
    unlink( $tmp );
    print "\n";
  }
}

function doImport($from, $to, &$pl) {
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
  
  for ($i = $from; $i < $to; ++$i) {
    // Go through the PL database and try to find matching trades.
    $row = $pl[ $i ];
    $market = $row[ 'currency' ] . '_' . $row[ 'coin' ];
    $buy_matches = array( );
    $sell_matches = array( );
    $buy_success = checkTradeSide( $buy_matches, $histories[ $row[ 'source_exchange' ] ][ $market ],
                                   $row, 'tradeable_bought' );
    $sell_success = checkTradeSide( $sell_matches, $histories[ $row[ 'target_exchange' ] ][ $market ],
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

    if ($buy_success) {
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
      $rateBuy = ($tradeableBought > 0.0e-9) ? ($rateTimesAmountBuy / $tradeableBought) : 0;
    } else {
      // Fall back to what we have read from the DB.
      $ex = $exchanges[ $row[ 'source_exchange' ] ];
      $rawTradeIDsBuy = '';
      $tradeIDsBuy = '';
      $rateTimesAmountBuy = $row[ 'currency_bought' ];
      $tradeableBought = $row[ 'tradeable_bought' ];
      $currencyBought = $ex->addFeeToPrice( $row[ 'currency_bought' ] );
      $boughtAmount = $ex->deductFeeFromAmountBuy( $row[ 'amount_bought_tradeable' ] );
      $tradeableTransferFee = $cm->getSafeTxFee( $ex, $coin, $boughtAmount );
      $buyFee = $ex->addFeeToPrice( 1 ) - 1;
      $rateBuy = ($tradeableBought > 0.0e-9) ? ($rateTimesAmountBuy / $tradeableBought) : 0;
    }
  
    if ($sell_success) {
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
      $rateSell = ($totalSoldAmount > 0.0e-9) ? ($rateTimesAmountSell / $totalSoldAmount) : 0;
    } else {
      // Fall back to what we have read from the DB.
      $ex = $exchanges[ $row[ 'target_exchange' ] ];
      $rawTradeIDsSell = '';
      $tradeIDsSell = '';
      $rateTimesAmountSell = $row[ 'currency_sold' ];
      $tradeableSold = $row[ 'tradeable_sold' ];
      $currencySold = $ex->addFeeToPrice( $row[ 'currency_sold' ] );
      $soldAmount = $ex->deductFeeFromAmountSell( $row[ 'amount_sold_tradeable' ] );
      $totalSoldAmount += $soldAmount;
      $tradeableTransferFee = $cm->getSafeTxFee( $ex, $coin, $soldAmount );
      $buyFee = $ex->addFeeToPrice( 1 ) - 1;
      $rateSell = ($tradeableSold > 0.0e-9) ? ($rateTimesAmountSell / $tradeableSold) : 0;
    }
  
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

if ($_SERVER[ 'PHP_SELF' ] == __FILE__ &&
    $_SERVER[ 'argc' ] == 5 &&
    $_SERVER[ 'argv' ][ 1 ] == 'import-worker') {
  // Subprocess worker mode.
  try {
    Config::refresh();
  }
  catch ( Exception $ex ) {
    echo "Error loading config: " . $ex->getMessage() . "\n";
    return;
  }

  $pl_file = $_SERVER[ 'argv' ][ 2 ];
  $pl = unserialize( file_get_contents( $pl_file ) );
  $from = $_SERVER[ 'argv' ][ 3 ];
  $to = $_SERVER[ 'argv' ][ 4 ];

  doImport( $from, $to, $pl );
}

