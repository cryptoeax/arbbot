<?php

require_once __DIR__ . '/CoinManager.php';

class Arbitrator {

  private $eventLoop;
  private $exchanges;
  private $exchangePairs = [ ];
  private $profitablePairsOfTheDay = [ ];
  //
  private $coinManager;
  private $tradeMatcher;
  //
  private $nextCoinUpdate = 0;
  private $tradeHappened = false;

  function __construct( $loop, $exchanges, &$tradeMatcher ) {
    $this->eventLoop = $loop;
    $self = $this;
    $this->eventLoop->addPeriodicTimer( 1, function() use($self) {
      $self->innerRun();
    } );

    $this->exchanges = &$exchanges;
    $this->tradeMatcher = &$tradeMatcher;

    // Create a list containing the exchange pairs:
    for ( $i = 0; $i < count( $exchanges ); $i++ ) {
      for ( $j = $i + 1; $j < count( $exchanges ); $j++ ) {
        $this->exchangePairs[] = [$exchanges[ $i ], $exchanges[ $j ] ];
      }
    }

    $this->coinManager = new CoinManager( $exchanges );

  }

  public function getEventLoop() {

    return $this->eventLoop;

  }

  private function loop() {

    Config::refresh();

    if ( time() > $this->nextCoinUpdate ) {
      $this->refreshCoinPairs();
      $this->nextCoinUpdate = time() + 3600;
    }

    $this->refreshWallets();

    if ( !$this->coinManager->doManage( $this ) ) {
      $this->cancelStrayOrders();

      $this->checkOpportunities();
    }

    $this->updateRunTimestamp();

  }

  private function updateRunTimestamp() {
    $stats = Database::getStats();
    $stats[ "last_run" ] = time();

    $first = true;
    while (in_array( "paused", array_keys( $stats ) )) {
      if ($first) {
        logg( "Noticing that we're paused now, waiting to be resumed..." );
        $first = false;
      }
      sleep( 3 );
      $stats = Database::getStats();
    }
    Database::saveStats( $stats );
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

    $pairs = array_intersect( $x1->getTradeablePairs(), $x2->getAllPairs() );
    logg( "Checking " . $x1->getName() . " vs " . $x2->getName() . " (" . count( $pairs ) . " common pairs)" );

    // Create even more randomness
    shuffle( $pairs );
    $slicedPairs = array_slice( $pairs, 0, Config::get( Config::MAX_PAIRS_PER_RUN, Config::DEFAULT_MAX_PAIRS_PER_RUN ) );

    $this->beGreedyOnProfitablePairsOfTheDay( $x1, $x2, $slicedPairs );

    logg( "Checking " . count( $slicedPairs ) . " random pairs..." );

    foreach ( $slicedPairs as $pair ) {

      if ( $this->checkPairAt( $pair, $x1, $x2 ) ||
           $this->checkPairAt( $pair, $x2, $x1 ) ) {
        return true;
      }
    }

    return false;

  }

  private function refreshProfitablePairsOfTheDay() {

    $results = Database::getTop5ProfitableCoinsOfTheDay();
    foreach ( $results as $row ) {
      $arr = explode( '-', $row[ 'exchange' ] );
      $src = $arr[ 0 ];
      $dest = $arr[ 1 ];
      if ( !isset( $results[ $src ] ) ) {
        $results[ $src ] = [ ];
      }
      if ( !isset( $results[ $src ][ $dest ] ) ) {
        $results[ $src ][ $dest ] = [ ];
      }
      $results[ $src ][ $dest ][] = array(
        'currency' => $row[ 'currency' ],
        'tradeable' => $row[ 'coin' ],
      );
    }
    $this->profitablePairsOfTheDay = $results;

  }

  private function beGreedyOnProfitablePairsOfTheDay( $x1, $x2, &$pairs ) {

    if ( isset( $this->profitablePairsOfTheDay[ $x1->getID() ][ $x2->getID() ] ) ) {
      // If we have any profitable coins on this exchange pair today, make sure to
      // greedily check them every time.
      $index = 0;
      foreach ( $this->profitablePairsOfTheDay[ $x1->getID() ][ $x2->getID() ] as $arr ) {
        $candidate = $arr[ 'tradeable' ] . '_' . $arr[ 'currency' ];
        if ( !in_array( $candidate, $pairs ) ) {
          $pairs[ $index++ ] = $candidate;
        }
      }
    }

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

    logg( "Checking $pair at " . $x1->getName() . " -> " . $x2->getName() . "..." );

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
    if ( is_null( $o1 ) ) {
      logg( "Received invalid orderbook from source exchange. Skipping..." );
      return false;
    }
    if ( is_null( $o2 ) ) {
      logg( "Received invalid orderbook from target exchange. Skipping..." );
      return false;
    }

    return ( $this->checkAndTrade( $o1, $o2 ) ) || $this->checkAndTrade( $o2, $o1 );

  }

  private function checkAndTrade( $sourceOrderbook, $targetOrderbook ) {

    $currency = $sourceOrderbook->getCurrency();
    $tradeable = $sourceOrderbook->getTradeable();
    if ( ! in_array( $tradeable,
                     array_map( 'getCurrency',
                                $targetOrderbook->getSource()->getTradeables()
                              ) ) ) {
      return false;
    }

    $sourceAsk = $sourceOrderbook->getBestAsk();
    $targetBid = $targetOrderbook->getBestBid();

    if ( $targetBid->getPrice() <= $sourceAsk->getPrice() ) {
      return false;
    }

    /*
      Check for arbitrage opportunities. If the bid is higher than the ask
     * on one exchange we do a quick simulation to estimate the profit. If
     * the profit is above the minimum limit, the opportunity is recorded in
     * the database. Then a trade is triggered, which performs some more
     * checks and finally executes the orders.
     */


    $amount = formatBTC( min( $sourceAsk->getAmount(), $targetBid->getAmount() ) );
    $profit = $this->simulateTrade( $sourceOrderbook, $targetOrderbook, $amount, $currency );
    logg( "SPREAD : " . formatBTC( $targetBid->getPrice() - $sourceAsk->getPrice() ) . " $currency = " . formatBTC( max( 0, $profit ) ) . " $currency PROFIT" );

    if ( $profit < Config::get( Config::MIN_PROFIT, Config::DEFAULT_MIN_PROFIT ) ) {
      return false;
    }

    Database::saveTrack( $tradeable, $currency, $amount, $profit,
                         $sourceOrderbook->getSource(),
                         $targetOrderbook->getSource() );

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

    $buyPrice = $source->addFeeToPrice( $tradeAmount * $bestBuyRate, $tradeable, $currency );
    $boughtAmount = $source->deductFeeFromAmountBuy( $tradeAmount, $tradeable, $currency );

    $txFee = $this->coinManager->getSafeTxFee( $source, $tradeable, $boughtAmount );
    $sellAmount = formatBTC( $boughtAmount - $txFee );
    $sellPrice = $target->deductFeeFromAmountSell( $sellAmount * $bestSellRate, $tradeable, $currency );
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
    $orderInfo .= " BOUGHT AMT : " . formatBalance( $boughtAmount ) . "\n";
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

    if ( $profit < Config::get( Config::MIN_PROFIT, Config::DEFAULT_MIN_PROFIT ) ) {
      logg( $orderInfo . "NOT ENTERING TRADE: REQUIRING MORE $tradeable\n" );
      return false;
    }

    if ( $buyPrice < $source->getSmallestOrderSize( $tradeable, $currency, 'buy' ) ) {
      logg( $orderInfo . "NOT ENTERING TRADE: BUY PRICE IS BELOW ACCEPTABLE THRESHOLD\n" );
      return false;
    }
    if ( $sellPrice < $target->getSmallestOrderSize( $tradeable, $currency, 'sell' ) ) {
      logg( $orderInfo . "NOT ENTERING TRADE: SELL PRICE IS BELOW ACCEPTABLE THRESHOLD\n" );
      return false;
    }

    $increasedBuyRate = formatBTC( $bestBuyRate * Config::get( Config::BUY_RATE_FACTOR, Config::DEFAULT_BUY_RATE_FACTOR ) );
    $reducedSellRate = formatBTC( $bestSellRate * Config::get( Config::SELL_RATE_FACTOR, Config::DEFAULT_SELL_RATE_FACTOR ) );

    if ( $reducedSellRate * $sellAmount < $target->getSmallestOrderSize( $tradeable, $currency, 'sell' ) ) {
      $reducedSellRate = formatBTC( $target->getSmallestOrderSize( $tradeable, $currency, 'sell' ) / $sellAmount + 0.00000001 );
    }

    if ( $reducedSellRate <= $increasedBuyRate ) {
      logg( $orderInfo . sprintf( "NOT ENTERING TRADE: REDUCED SELL RATE %s IS BELOW INCREASED BUY RATE %s",
                                  formatBTC( $reducedSellRate ), formatBTC( $increasedBuyRate ) ) );
      return false;
    }

    $orderInfo .= "= TRADE ============================================\n";
    $orderInfo .= " SELL ORDER : $sellAmount $tradeable @ $reducedSellRate $currency\n";
    $orderInfo .= "  BUY ORDER : $tradeAmount $tradeable @ $increasedBuyRate $currency\n";
    $orderInfo .= "\n";
    logg( $orderInfo );

    $undersellProtection = Config::get( Config::UNDERSELL_PROTECTION, Config::DEFAULT_UNDERSELL_PROTECTION );

    $sellOrderID = $target->sell( $tradeable, $currency, $reducedSellRate, $sellAmount );
    $buyOrderID = null;
    if ( is_null( $sellOrderID ) ) {
      logg( "Sell order failed, we will not attempt a buy order to avoid incurring a loss." );
    } else {
      logg( "Placed sell order (" . $target->getName() . " ID: $sellOrderID)" );

      $tradesSum = 0;
      $averageSellRate = 0;
      $priceAdjustment = 0;
      if ( $undersellProtection ) {
        logg( "Waiting for order execution..." );
        sleep( 0.1 * Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) );
        if ( !is_null( $sellOrderID ) &&
             $target->cancelOrder( $sellOrderID ) ) {
          logg( "A sell order hasn't been filled. If this happens regulary you should increase the " . Config::ORDER_CHECK_DELAY . " setting!", true );
        }

        $target->refreshWallets();

        $sellTrades = $this->tradeMatcher->handlePostTradeTasks( $this, $target, $tradeable, $currency, 'sell',
                                                                 $sellOrderID, $sellAmount );
        $tradesSum = array_reduce( $sellTrades, 'sumOfAmount', 0 );
        $averageSellRate = $tradesSum == 0 ? $reducedSellRate :
                             array_reduce( $sellTrades, 'sumOfAmountTimesRate', 0 ) / $tradesSum;
        // Price adjustment is the difference of the sale price to the price that we intended to sell at.
        $priceAdjustment = formatBTC( $averageSellRate ) - $reducedSellRate;
      }

      if ( $undersellProtection && floatval( $tradesSum ) == 0 ) {
        logg( "Sell order not fullfilled, we will not attempt a buy order to avoid incurring a loss." );
        $sellOrderID = null;
      } else {
        if ( $undersellProtection && $tradesSum != $sellAmount ) {
          logg( sprintf( "Warning: Meant to sell %s but managed to only sell %s",
                         formatBTC( $sellAmount ), formatBTC( $tradesSum ) ) );

          // Adjust $tradeAmount according to how much we managed to sell.
          $tradeAmount = $tradesSum + $txFee;
        }
        // Ignore non-negative price adjustments, since if we manage to sell at a higher price than we
        // expected, we can still attempt to buy at the price that we intended to buy at while making
        // potentially more profits!
        if ( $undersellProtection && $priceAdjustment < 0 ) {
          if ( $increasedBuyRate + $priceAdjustment >= $bestBuyRate ) {
	    logg( sprintf( "Warning: Meant to sell at %s but instead sold at %s, so adjusting the buy rate by %s.",
			   formatBTC( $reducedSellRate ), formatBTC( $averageSellRate ),
			   formatBTC( $priceAdjustment ) ) );

            $increasedBuyRate += $priceAdjustment;
          }
        }

        for ( $i = 0; $i < 5; ++ $i ) {
          $buyOrderID = $source->buy( $tradeable, $currency, $increasedBuyRate, $tradeAmount );
          if ( is_null( $buyOrderID ) ) {
            if ( $i < 4 ) {
              logg( "Buy order failed, we will probably incur a profit (but we have really sold off our altcoin), so let's retry..." );
            }
            continue;
          }
          logg( "Placed buy order (" . $source->getName() .  " ID: $buyOrderID)" );

          logg( "Waiting for order execution..." );
          sleep( Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) );
          break;
        }

        if ( !$undersellProtection ) {
          if ( !is_null( $sellOrderID ) &&
               $target->cancelOrder( $sellOrderID ) ) {
            logg( "A sell order hasn't been filled. If this happens regulary you should increase the " . Config::ORDER_CHECK_DELAY . " setting!", true );
          }
        }
        if ( !is_null( $buyOrderID ) &&
             $source->cancelOrder( $buyOrderID ) ) {
          logg( "A buy order hasn't been filled. If this happens regulary you should increase the " . Config::ORDER_CHECK_DELAY . " setting!", true );
        }
      }
    }

    if ( is_null( $buyOrderID ) && is_null( $sellOrderID ) ) {
      // Sell order failed, we're bailing out!
      return false;
    }

    for ( $i = 1; $i <= 8; $i *= 2 ) {

      logg( "Checking trade results ($i)..." );

      $source->refreshWallets();
      if ( !$undersellProtection || $i != 1 ) {
        $target->refreshWallets();
      }

      $buyTrades = $this->tradeMatcher->handlePostTradeTasks( $this, $source, $tradeable, $currency, 'buy',
                                                              $buyOrderID, $tradeAmount );
      if ( !$undersellProtection || $i != 1 ) {
        $sellTrades = $this->tradeMatcher->handlePostTradeTasks( $this, $target, $tradeable, $currency, 'sell',
                                                                 $sellOrderID, $sellAmount );
      }

      $totalCost = is_null( $buyOrderID ) ? 0 :
                     $source->getFilledOrderPrice( 'buy', $tradeable, $currency, $buyOrderID );
      $totalRevenue = is_null( $sellOrderID ) ? 0 :
                        $target->getFilledOrderPrice( 'sell', $tradeable, $currency, $sellOrderID );

      $currencyProfitLoss = $this->tradeMatcher->saveProfitLoss( $source, $target,
                                                                 $buyTrades, $sellTrades,
                                                                 $this->coinManager );

      $sourceCurrencyAfter = $sourceCurrencyBefore - $totalCost;
      $targetCurrencyAfter = $targetCurrencyBefore + $totalRevenue;

      $sourceTradeableAfter = $source->getWallets()[ $tradeable ];
      $targetTradeableAfter = $target->getWallets()[ $tradeable ];

      $sourceTradeableDifference = $sourceTradeableAfter - $sourceTradeableBefore;
      $targetTradeableDifference = $targetTradeableAfter - $targetTradeableBefore;

      $message = "TRADE SUMMARY:\n";
      $message .= "PAIR: $tradeable vs $currency\n";
      $message .= "DIRECTION: " . $source->getName() . " TO " . $target->getName() . "\n";
      $message .= "BALANCES BEFORE / AFTER\n";
      $message .= "\n" . $source->getName() . ":\n";
      $message .= formatCoin( $tradeable ) . ": " . formatBalance( $sourceTradeableBefore ) . " => " . formatBalance( $sourceTradeableAfter ) . " = " . formatBalance( $sourceTradeableDifference ) . "\n";

      $sourceCurrencyDifference = $sourceCurrencyAfter - $sourceCurrencyBefore;
      $message .= formatCoin( $currency ) . ": " . formatBalance( $sourceCurrencyBefore ) . " => " . formatBalance( $sourceCurrencyAfter ) . " = " . formatBalance( $sourceCurrencyDifference ) . "\n";

      $message .= "\n" . $target->getName() . ":\n";
      $message .= formatCoin( $tradeable ) . ": " . formatBalance( $targetTradeableBefore ) . " => " . formatBalance( $targetTradeableAfter ) . " = " . formatBalance( $targetTradeableDifference ) . "\n";

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

      logg( sprintf( "Calculated P&L: %.8f", formatBTC( $currencyProfitLoss) ) );

      Database::saveTrade( $tradeable, $currency, $sellAmount, $source->getID(), $target->getID() );

      $this->coinManager->withdraw( $source, $target, $tradeable, $boughtAmount );
      break;
    }
    return true;

  }

  function simulateTrade( $source, $target, $amount, $currency ) {

    // A quick simulation to check the outcome of the trade

    $tradeable = $source->getTradeable();
    $sourceX = $source->getSource();
    $targetX = $target->getSource();

    $sourceAsk = $source->getBestAsk();
    $targetBid = $target->getBestBid();

    $price = $sourceX->addFeeToPrice( $amount * $sourceAsk->getPrice(), $tradeable, $currency );
    $receivedAmount = $sourceX->deductFeeFromAmountBuy( $amount, $tradeable, $currency );
    if ( $price < $sourceX->getSmallestOrderSize( $tradeable, $currency, 'buy' ) ) {
      return 0;
    }

    $txFee = $this->coinManager->getSafeTxFee( $sourceX, $tradeable, $receivedAmount );

    $arrivedAmount = $receivedAmount - $txFee;

    $receivedPrice = $targetX->deductFeeFromAmountSell( $arrivedAmount * $targetBid->getPrice(),
                                                        $tradeable, $currency );
    if ( $receivedPrice < $targetX->getSmallestOrderSize( $tradeable, $currency, 'sell' ) ) {
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

      logg( "tradeable pairs: " . count( $exchange->getTradeablePairs() ) . " of " .
            count( $exchange->getAllPairs() ) . " @ " . $exchange->getName() );
    }

    $this->refreshProfitablePairsOfTheDay();

  }

  private function refreshWallets() {

    logg( "Refreshing wallets..." );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->refreshWallets();
    }

  }

  public function run() {

    $this->eventLoop->run();

  }

  private function innerRun() {

    try {
      $this->loop();
    }
    catch ( Exception $ex ) {
      logg( "Error during main loop: " . $ex->getMessage() . "\n" . $ex->getTraceAsString() );
    }

  }

  public function getTradeMatcher() {

    return $this->tradeMatcher;

  }

}
