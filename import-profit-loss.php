<?php

require_once 'bot/utils.php';
require_once 'bot/CoinManager.php';
require_once 'bot/Database.php';
require_once 'bot/Exchange.php';
require_once 'bot/xchange/Bittrex.php';
require_once 'bot/xchange/Poloniex.php';

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
    $tradeableBought = $ex->deductFeeFromAmountSell( $row[ 'tradeable_bought' ], $coin, $currency  );
    $currencyBought = $ex->deductFeeFromAmountSell( $row[ 'currency_bought' ], $coin, $currency  );
    $boughtAmount = $ex->deductFeeFromAmountSell( $row[ 'amount_bought_tradeable' ], $coin, $currency  );
    $buyFee = $currencyBought - $ex->addFeeToPrice( $currencyBought, $coin, $currency );
    $rateBuy = ($tradeableBought > 0.0e-9) ? ($rateTimesAmountBuy / $tradeableBought) : 0;
  
    $ex = $exchanges[ $row[ 'target_exchange' ] ];
    $rawTradeIDsSell = '';
    $tradeIDsSell = '';
    $rateTimesAmountSell = $row[ 'currency_sold' ];
    $tradeableSold = $row[ 'tradeable_sold' ];
    $currencySold = $row[ 'currency_sold' ];
    $soldAmount = $row[ 'amount_sold_tradeable' ];
    $sellFee = $row[ 'currency_sold' ] - $ex->addFeeToPrice( $row[ 'currency_sold' ], $coin, $currency );
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

