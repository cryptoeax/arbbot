<?php

require_once __DIR__ . '/CoinManager.php';

class Arbitrator {

  private $exchanges;
  private $exchangePairs = [ ];
  //
  private $coinManager;
  //
  private $nextCoinUpdate = 0;
  private $walletsRefreshed = false;
  private $tradeHappened = false;

  function __construct( $exchanges ) {
    $this->exchanges = $exchanges;

    // Create a list containing the exchange pairs:
    for ( $i = 0; $i < count( $exchanges ); $i++ ) {
      for ( $j = $i + 1; $j < count( $exchanges ); $j++ ) {
        $this->exchangePairs[] = [$exchanges[ $i ], $exchanges[ $j ] ];
      }
    }

    $this->coinManager = new CoinManager( $exchanges );

  }

  private function loop() {

    Config::refresh();

    if ( time() > $this->nextCoinUpdate ) {
      if (!$this->walletsRefreshed) {
        $this->refreshWallets();
      }
      $this->refreshCoinPairs();
      $this->nextCoinUpdate = time() + 3600;
    }

    $this->refreshWallets();

    if ( $this->coinManager->doManage() ) {
      return;
    }

    $this->cancelStrayOrders();

    $this->checkOpportunities();

  }

  private function checkOpportunities() {

    logg( "Checking for opportunities..." );

    // Shuffle exchanges to create some randomness
    shuffle( $this->exchangePairs );

    foreach ( $this->exchangePairs as $exchangePair ) {

      if ( $this->checkOpportunitiesAt( $exchangePair[ 0 ], $exchangePair[ 1 ] ) ) {
        // Trade happened, restart...
        $this->tradeHappened = true;
        return;
      }
    }

  }

  private function checkOpportunitiesAt( $x1, $x2 ) {

    $pairs = array_intersect( $x1->getTradeablePairs(), $x2->getTradeablePairs() );
    logg( "Checking " . $x1->getName() . " vs " . $x2->getName() . " (" . count( $pairs ) . " common pairs)" );

    // Create even more randomness
    shuffle( $pairs );
    $slicedPairs = array_slice( $pairs, 0, Config::get( Config::MAX_PAIRS_PER_RUN, Config::DEFAULT_MAX_PAIRS_PER_RUN ) );

    logg( "Checking " . count( $slicedPairs ) . " random pairs..." );

    foreach ( $slicedPairs as $pair ) {

      // A bit of sleep to stay within the exchanges rate limits
      sleep( Config::get( Config::QUERY_DELAY, Config::DEFAULT_QUERY_DELAY ) );

      if ( $this->checkPairAt( $pair, $x1, $x2 ) ) {
        return true;
      }
      //
    }

    return false;

  }

  private function checkPairAt( $pair, $x1, $x2 ) {

    // Split pair into its coins
    $split = explode( "_", $pair );
    $tradeable = $split[ 0 ];
    $currency = $split[ 1 ];

    if ( Config::isBlocked( $tradeable ) ) {
      logg( "Skipping $pair (BLOCKED)" );
      return;
    }

    logg( "Checking $pair..." );

    // Measure time for orderbook checks. Sometimes it takes 20+ seconds and could result in a failed trade
    $timeout = time() + 10;

    $orderbook1 = $x1->getOrderbook( $tradeable, $currency );
    $orderbook2 = $x2->getOrderbook( $tradeable, $currency );

    if ( time() >= $timeout ) {
      logg( "TIMEOUT" );
      return;
    }

    return $this->testOrderbooks( $orderbook1, $orderbook2 );

  }

  private function testOrderbooks( $o1, $o2 ) {
    if ( is_null( $o1 ) || is_null( $o2 ) ) {
      logg( "Received invalid orderbook. Skipping..." );
      return false;
    }

    return ( $this->checkAndTrade( $o1, $o2 ) ) || $this->checkAndTrade( $o2, $o1 );

  }

  private $priceDiffNotifications = [ ];

  private function checkAndTrade( $sourceOrderbook, $targetOrderbook ) {

    $sourceAsk = $sourceOrderbook->getBestAsk();
    $targetBid = $targetOrderbook->getBestBid();

    if ( $targetBid->getPrice() <= $sourceAsk->getPrice() ) {
      return false;
    }

    $currency = $sourceOrderbook->getCurrency();
    $tradeable = $sourceOrderbook->getTradeable();

    $targetPrice = $targetBid->getPrice();
    $sourcePrice = $targetBid->getPrice();

    if ( array_search( $tradeable, $this->priceDiffNotifications ) === false && abs( $targetPrice - $sourcePrice ) > 0.00000002 && $targetPrice > $sourcePrice + $sourcePrice() * (Config::get( Config::SUSPICIOUS_PRICE_DIFFERENCE, Config::DEFAULT_SUSPICIOUS_PRICE_DIFFERENCE ) / 100) ) {

      $sourceXname = $sourceOrderbook->getSource()->getName();
      $targetXname = $targetOrderbook->getSource()->getName();

      logg( "Detected suspiciously large price difference (over " . Config::get( Config::SUSPICIOUS_PRICE_DIFFERENCE, Config::DEFAULT_SUSPICIOUS_PRICE_DIFFERENCE ) . "%) - please check if this is legitimate (Check if coins are the same / markets aren't down / exchange hacked / etc)!\n\nMarket: $tradeable vs $currency\nExchange 1: $sourceXname\nExchange 2: $targetXname\n", true );

      $this->priceDiffNotifications[] = $tradeable;
    }

    /*
      Check for arbitrage opportunities. If the bid is higher than the ask
     * on one exchange we do a quick simulation to estimate the profit. If
     * the profit is above the minimum limit, the opportunity is recorded in
     * the database. Then a trade is triggered, which performs some more
     * checks and finally executes the orders.
     */


    $amount = formatBTC( min( $sourceAsk->getAmount(), $targetBid->getAmount() ) );
    $profit = $this->simulateTrade( $sourceOrderbook, $targetOrderbook, $amount );
    logg( "SPREAD : " . formatBTC( $targetBid->getPrice() - $sourceAsk->getPrice() ) . " $currency = " . formatBTC( max( 0, $profit ) ) . " $currency PROFIT" );

    if ( $profit < Config::get( Config::MIN_PROFIT, Config::DEFAULT_MIN_PROFIT ) ) {
      return false;
    }

    Database::saveTrack( $tradeable, $amount, $profit, $targetOrderbook->getSource() );

    if ( Config::get( Config::MODULE_TRADE, Config::DEFAULT_MODULE_TRADE ) ) {
      return $this->trade( $sourceOrderbook, $targetOrderbook, $tradeable, $currency );
    }

    return false;

  }

  function trade( $sourceOrderbook, $targetOrderbook, $tradeable, $currency ) {

    logg( "Testing if trading is profitable with available funds..." );

    $source = $sourceOrderbook->getSource();
    $target = $targetOrderbook->getSource();

    $sourceTradeableBefore = $source->getWallets()[ $tradeable ];
    $targetTradeableBefore = $target->getWallets()[ $tradeable ];

    $sourceCurrencyBefore = $source->getWallets()[ $currency ];
    $targetCurrencyBefore = $target->getWallets()[ $currency ];

    $maxTradeSize = Config::get( Config::MAX_TRADE_SIZE, Config::DEFAULT_MAX_TRADE_SIZE );

    $bestBuyRate = $sourceOrderbook->getBestAsk()->getPrice();
    $bestBuyAmount = $sourceOrderbook->getBestAsk()->getAmount();

    $bestSellRate = $targetOrderbook->getBestBid()->getPrice();
    $bestSellAmount = $targetOrderbook->getBestBid()->getAmount();

    $maxSourceAmount = min( min( $maxTradeSize, $sourceCurrencyBefore ) / $bestBuyRate, $bestBuyAmount );
    $maxTargetAmount = min( $targetTradeableBefore, $bestSellAmount );
    $tradeAmount = formatBTC( min( $maxSourceAmount, $maxTargetAmount ) );

    $buyPrice = $source->addFeeToPrice( $tradeAmount * $bestBuyRate );
    $boughtAmount = $source->deductFeeFromAmountBuy( $tradeAmount );

    $txFee = $this->coinManager->getSafeTxFee( $source, $tradeable, $boughtAmount );
    $sellAmount = formatBTC( $boughtAmount - $txFee );
    $sellPrice = $target->deductFeeFromAmountSell( $sellAmount * $bestSellRate );
    $profit = $sellPrice - $buyPrice;

    $orderInfo = "TRADING $tradeable-$currency FROM " . $source->getName() . " TO " . $target->getName() . "\n";

    $orderInfo .= "\n";
    $orderInfo .= "= FUNDS ============================================\n";
    $orderInfo .= "     SOURCE : " . formatBalance( $sourceCurrencyBefore ) . " $currency\n";
    $orderInfo .= "     TARGET : " . formatBalance( $targetTradeableBefore ) . " $tradeable\n";

    $orderInfo .= "\n";
    $orderInfo .= "= SIMULATION =======================================\n";
    $orderInfo .= " TARGET MAX : " . formatBalance( $maxTargetAmount ) . "\n";
    $orderInfo .= " SOURCE MAX : " . formatBalance( $maxSourceAmount ) . "\n";
    $orderInfo .= "   BUY RATE : " . formatBalance( $bestBuyRate ) . "\n";
    $orderInfo .= " BUY AMOUNT : " . formatBalance( $tradeAmount ) . "\n";
    $orderInfo .= "  BUY PRICE : " . formatBalance( $buyPrice ) . "\n";
    $orderInfo .= "  SELL RATE : " . formatBalance( $bestSellRate ) . "\n";
    $orderInfo .= "SELL AMOUNT : " . formatBalance( $sellAmount ) . "\n";
    $orderInfo .= " SELL PRICE : " . formatBalance( $sellPrice ) . "\n";
    $orderInfo .= "     PROFIT : " . formatBalance( $profit ) . "\n";
    $orderInfo .= "\n";

    if ( $sourceCurrencyBefore < $buyPrice * 1.01 ) {
      logg( $orderInfo . "NOT ENTERING TRADE: REQUIRING MORE $currency\n", true );
      return false;
    }

    if ( $profit < Config::get( Config::MIN_PROFIT, Config::DEFAULT_MIN_PROFIT ) * 0.5 ) {
      logg( $orderInfo . "NOT ENTERING TRADE: REQUIRING MORE $tradeable\n" );
      return false;
    }

    if ( $buyPrice < $source->getSmallestOrderSize() ) {
      logg( $orderInfo . "NOT ENTERING TRADE: BUY PRICE IS BELOW ACCEPTABLE THRESHOLD\n" );
      return false;
    }
    if ( $sellPrice < $target->getSmallestOrderSize() ) {
      logg( $orderInfo . "NOT ENTERING TRADE: SELL PRICE IS BELOW ACCEPTABLE THRESHOLD\n" );
      return false;
    }

    $increasedBuyRate = formatBTC( $bestBuyRate * Config::get( Config::BUY_RATE_FACTOR, Config::DEFAULT_BUY_RATE_FACTOR ) );
    $reducedSellRate = formatBTC( $bestSellRate * Config::get( Config::SELL_RATE_FACTOR, Config::DEFAULT_SELL_RATE_FACTOR ) );

    if ( $reducedSellRate * $sellAmount < $target->getSmallestOrderSize() ) {
      $reducedSellRate = formatBTC( $target->getSmallestOrderSize() / $sellAmount + 0.00000001 );
    }

    $orderInfo .= "= TRADE ============================================\n";
    $orderInfo .= " SELL ORDER : $sellAmount $tradeable @ $reducedSellRate $currency\n";
    $orderInfo .= "  BUY ORDER : $tradeAmount $tradeable @ $increasedBuyRate $currency\n";
    $orderInfo .= "\n";
    logg( $orderInfo );

    $sellOrderID = $target->sell( $tradeable, $currency, $reducedSellRate, $sellAmount );
    logg( "Placed sell order (" . $target->getName() . " ID: $sellOrderID)" );
    $buyOrderID = $source->buy( $tradeable, $currency, $increasedBuyRate, $tradeAmount );
    logg( "Placed buy order (" . $source->getName() . " ID: $buyOrderID)" );

    logg( "Waiting for order execution..." );
    sleep( Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) );

    if ( $target->cancelOrder( $sellOrderID ) ) {
      logg( "A sell order hasn't been filled. If this happens regulary you should increase the " . Config::ORDER_CHECK_DELAY . " setting!", true );
    }
    if ( $source->cancelOrder( $buyOrderID ) ) {
      logg( "A buy order hasn't been filled. If this happens regulary you should increase the " . Config::ORDER_CHECK_DELAY . " setting!", true );
    }

    for ( $i = 1; $i <= 8; $i *= 2 ) {

      sleep( (Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) / 2) * $i );
      logg( "Checking trade results ($i)..." );

      $target->refreshWallets();
      $source->refreshWallets();

      $sourceTradeableAfter = $source->getWallets()[ $tradeable ];
      $targetTradeableAfter = $target->getWallets()[ $tradeable ];

      $sourceCurrencyAfter = $source->getWallets()[ $currency ];
      $targetCurrencyAfter = $target->getWallets()[ $currency ];

      $message = "TRADE SUMMARY:\n";
      $message .= "PAIR: $tradeable vs $currency\n";
      $message .= "DIRECTION: " . $source->getName() . " TO " . $target->getName() . "\n";
      $message .= "BALANCES BEFORE / AFTER\n";
      $message .= "\n" . $source->getName() . ":\n";
      $sourceTradeableDifference = $sourceTradeableAfter - $sourceTradeableBefore;
      $message .= formatCoin( $tradeable ) . ": " . formatBalance( $sourceTradeableBefore ) . " => " . formatBalance( $sourceTradeableAfter ) . " = " . formatBalance( $sourceTradeableDifference ) . "\n";

      $sourceCurrencyDifference = $sourceCurrencyAfter - $sourceCurrencyBefore;
      $message .= formatCoin( $currency ) . ": " . formatBalance( $sourceCurrencyBefore ) . " => " . formatBalance( $sourceCurrencyAfter ) . " = " . formatBalance( $sourceCurrencyDifference ) . "\n";

      $message .= "\n" . $target->getName() . ":\n";
      $targetTradeableDifference = $targetTradeableAfter - $targetTradeableBefore;
      $message .= formatCoin( $tradeable ) . ": " . formatBalance( $targetCurrencyBefore ) . " => " . formatBalance( $targetTradeableAfter ) . " = " . formatBalance( $targetTradeableDifference ) . "\n";

      $targetCurrencyDifference = $targetCurrencyAfter - $targetCurrencyBefore;
      $message .= formatCoin( $currency ) . ": " . formatBalance( $targetCurrencyBefore ) . " => " . formatBalance( $targetCurrencyAfter ) . " = " . formatBalance( $targetCurrencyDifference ) . "\n\n";

      $tradeableDifference = formatBTC( $sourceTradeableDifference + $targetTradeableDifference );
      $currencyDifference = formatBTC( $sourceCurrencyDifference + $targetCurrencyDifference );

      $tradeableDifferenceAfterTx = $tradeableDifference - $txFee;

      $message .= "TOTAL:\n";
      $message .= "      " . formatCoin( $currency ) . ": " . formatBalance( $currencyDifference ) . "\n";
      $message .= "      " . formatCoin( $tradeable ) . ": " . formatBalance( $tradeableDifference ) . "\n";
      $message .= "" . formatCoin( $tradeable ) . "(-tx) : " . formatBalance( $tradeableDifferenceAfterTx ) . "\n\n";
      $message .= "(Transfer fee is " . formatBTC( $txFee ) . ")\n\n";

      logg( $message );

/*
      if ( $i < 8 && ( $currencyDifference < 0 || $tradeableDifference < 0 ) ) {
        logg( "Negative result: Retesting in a few seconds..." );

        $source->dumpWallets();
        $target->dumpWallets();

        if ( $i == 8 ) {
          logg( "Negative trade. Investigate!!!", true );
          die( "HARD\n" );
        }

        continue;
      }
*/

      Database::saveTrade( $tradeable, $currency, $sellAmount, $source->getID(), $target->getID() );

      $this->coinManager->withdraw( $source, $target, $tradeable, $boughtAmount );
      break;
    }
    return true;

  }

  function simulateTrade( $source, $target, $amount ) {

    // A quick simulation to check the outcome of the trade

    $tradeable = $source->getTradeable();
    $sourceX = $source->getSource();
    $targetX = $target->getSource();

    $sourceAsk = $source->getBestAsk();
    $targetBid = $target->getBestBid();

    $price = $sourceX->addFeeToPrice( $amount * $sourceAsk->getPrice() );
    $receivedAmount = $sourceX->deductFeeFromAmountBuy( $amount );
    if ( $price < $sourceX->getSmallestOrderSize() ) {
      return 0;
    }

    $txFee = $this->coinManager->getSafeTxFee( $sourceX, $tradeable, $receivedAmount );

    $arrivedAmount = $receivedAmount - $txFee;

    $receivedPrice = $targetX->deductFeeFromAmountSell( $arrivedAmount * $targetBid->getPrice() );
    if ( $receivedPrice < $targetX->getSmallestOrderSize() ) {
      return 0;
    }

    return formatBTC( $receivedPrice - $price );

  }

  private function cancelStrayOrders() {

    if ( !$this->tradeHappened ||
         !Config::get( Config::CANCEL_STRAY_ORDERS, Config::DEFAULT_CANCEL_STRAY_ORDERS ) ) {
      return;
    }

    logg( "Cancelling stray orders..." );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->cancelAllOrders();
    }

    $this->tradeHappened = false;

  }

  private function refreshCoinPairs() {

    logg( "Refreshing trading pairs..." );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->refreshExchangeData();

      logg( count( $exchange->getTradeablePairs() ) . " tradeable pairs @ " . $exchange->getName() );
    }

  }

  private function refreshWallets() {

    logg( "Refreshing wallets..." );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->refreshWallets();
    }

    $this->walletsRefreshed = true;

  }

  public function run() {

    $errorCounter = 0;

    while ( true ) {

      try {
        $this->loop();
        $errorCounter = 0;
      }
      catch ( Exception $ex ) {
        $errorCounter++;
        logg( "Error during main loop: " . $ex->getMessage() . "\n" . $ex->getTraceAsString(), $errorCounter == 10 );
        sleep( 10 );
      }

      sleep( Config::get( Config::QUERY_DELAY, Config::DEFAULT_QUERY_DELAY ) * 3 );
    }

  }

}
