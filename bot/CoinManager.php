<?php

class CoinEntry {

  public $amount;
  public $coin;
  public $exchange;
  public $profit;
  public $time;

}

class CoinManager {

  //
  const STAT_AUTOBUY_FUNDS = "autobuy_funds";
  //
  const STAT_NEXT_MANAGEMENT = "next_management";
  const STAT_NEXT_TAKE_PROFIT = "next_take_profit";
  const STAT_NEXT_STUCK_DETECTION = "next_stuck_detection";
  const STAT_NEXT_DUPLICATE_DETECTION = "next_duplicate_detection";
  const STAT_NEXT_UNUSED_COIN_DETECTION = "next_unused_coin_detection";
  const STAT_NEXT_DB_CLEANUP = "next_db_cleanup";
  const STAT_NEXT_CURRENCY_AGGRESSIVE_BALANCE_ALLOWED = "next_currency_aggressive_balance_allowed";
  const STAT_BOT_AGE = "bot_age";

  //
  private $stats = null;

  /* @var $exchanges Exchange */
  private $exchanges;
  private $exchangesID = [ ];

  function __construct( $exchanges ) {
    $this->exchanges = &$exchanges;

    foreach ( $exchanges as $exchange ) {
      $this->exchangesID[ $exchange->getID() ] = $exchange;
    }

  }

  public function doManage( &$arbitrator ) {

    logg( "doManage()" );
    $this->stats = Database::getStats();

    $this->checkKeys();

    $stats = &$this->stats;
    $hadAction = true;

    try {
      //
      if ( $stats[ self::STAT_NEXT_MANAGEMENT ] <= time() ) {
        //
        self::manageWallets( $arbitrator );
        $stats[ self::STAT_NEXT_MANAGEMENT ] = time() + Config::get( Config::INTERVAL_MANAGEMENT, Config::DEFAULT_INTERVAL_MANAGEMENT ) * 1800;
        //
      }
      else if ( $stats[ self::STAT_NEXT_TAKE_PROFIT ] <= time() ) {
        //
        self::takeProfit();
        $stats[ self::STAT_NEXT_TAKE_PROFIT ] = time() + Config::get( Config::INTERVAL_TAKE_PROFIT, Config::DEFAULT_INTERVAL_TAKE_PROFIT ) * 3600;
        //
      }
      else if ( $stats[ self::STAT_NEXT_STUCK_DETECTION ] <= time() ) {
        //
        self::stuckDetection();
        $stats[ self::STAT_NEXT_STUCK_DETECTION ] = time() + Config::get( Config::INTERVAL_STUCK_DETECTION, Config::DEFAULT_INTERVAL_STUCK_DETECTION ) * 3600;
        //
      }
      else if ( $stats[ self::STAT_NEXT_DUPLICATE_DETECTION ] <= time() ) {
        //
        self::duplicateDetection();
        $stats[ self::STAT_NEXT_DUPLICATE_DETECTION ] = time() + Config::get( Config::INTERVAL_DUPLICATE_DETECTION, Config::DEFAULT_INTERVAL_DUPLICATE_DETECTION ) * 3600;
        //
      }
      else if ( $stats[ self::STAT_NEXT_UNUSED_COIN_DETECTION ] <= time() ) {
        //
        self::unusedCoinsDetection();
        $stats[ self::STAT_NEXT_UNUSED_COIN_DETECTION ] = time() + Config::get( Config::INTERVAL_UNUSED_COIN_DETECTION, Config::DEFAULT_INTERVAL_UNUSED_COIN_DETECTION ) * 3600;
        //
      }
      else if ( $stats[ self::STAT_NEXT_DB_CLEANUP ] <= time() ) {
        //
        self::dbCleanup();
        $stats[ self::STAT_NEXT_DB_CLEANUP ] = time() + Config::get( Config::INTERVAL_DB_CLEANUP, Config::DEFAULT_INTERVAL_DB_CLEANUP ) * 3600;
        //
      }
      else {
        $hadAction = false;
      }
      //
    }
    catch ( Exception $ex ) {
      logg( "ERROR during management task: " . $ex->getMessage() . "\n" . $ex->getTraceAsString() );
    }

    Database::saveStats( $this->stats );

    return $hadAction;

  }

  private function checkKeys() {

    $stats = &$this->stats;

    // load sensible defaults for stats...
    if ( !key_exists( self::STAT_NEXT_MANAGEMENT, $stats ) ) {
      $stats[ self::STAT_NEXT_MANAGEMENT ] = 0;
    }
    if ( !key_exists( self::STAT_NEXT_TAKE_PROFIT, $stats ) ) {
      $stats[ self::STAT_NEXT_TAKE_PROFIT ] = time() + 48 * 3600;
    }
    if ( !key_exists( self::STAT_NEXT_STUCK_DETECTION, $stats ) ) {
      $stats[ self::STAT_NEXT_STUCK_DETECTION ] = time() + 24 * 3600;
    }
    if ( !key_exists( self::STAT_NEXT_DUPLICATE_DETECTION, $stats ) ) {
      $stats[ self::STAT_NEXT_DUPLICATE_DETECTION ] = time() + 24 * 3600;
    }
    if ( !key_exists( self::STAT_NEXT_UNUSED_COIN_DETECTION, $stats ) ) {
      $stats[ self::STAT_NEXT_UNUSED_COIN_DETECTION ] = time() + 24 * 3600;
    }
    if ( !key_exists( self::STAT_NEXT_DB_CLEANUP, $stats ) ) {
      $stats[ self::STAT_NEXT_DB_CLEANUP ] = time() + 7 * 24 * 3600;
    }
    if ( !key_exists( self::STAT_NEXT_CURRENCY_AGGRESSIVE_BALANCE_ALLOWED, $stats ) ) {
      $stats[ self::STAT_NEXT_CURRENCY_AGGRESSIVE_BALANCE_ALLOWED ] = 0;
    }
    if ( !key_exists( self::STAT_BOT_AGE, $stats ) ) {
      $stats[ self::STAT_BOT_AGE ] = time();
    }
    if ( !key_exists( self::STAT_AUTOBUY_FUNDS, $stats ) ) {
      $stats[ self::STAT_AUTOBUY_FUNDS ] = "0";
    }

  }

  private function dbCleanup() {

    logg( "dbCleanup()" );
    $rows = Database::cleanup();

    logg( "Deleted $rows records" );

  }

  private function saveSnapshot() {

    logg( "saveSnapshot()" );

    $time = time();
    $balances = array( );
    foreach ( $this->exchanges as $exchange ) {

      $exid = $exchange->getID();
      $exname = $exchange->getName();

      $wallets = $exchange->getWalletsConsideringPendingDeposits();
      $ticker = $exchange->getTickers( 'BTC' );

      foreach ( $wallets as $coin => $value ) {

        $balance = formatBTC( $value );
        if ( isset( $balances[ $coin ] ) ) {
          $balances[ $coin ] += $value;
        } else {
          $balances[ $coin ] = $value;
        }

        if ( Config::isCurrency( $coin ) ) {
          Database::saveSnapshot( $coin, $balance, $balance, 0, $exid, $time );
          continue;
        }

        if ( !key_exists( $coin, $ticker ) ) {
          logg( "$exname | Skipping $coin as it isn't traded against BTC" );
          if ( $value > 0 ) {
            logg( "You have $balance $coin at $exname which cannot be traded against BTC!" );
          }
          continue;
        }

        $rate = formatBTC( $ticker[ $coin ] );

        // Calculate desired balance:
        $desiredBalance = 0;
        $averageRate = 0;
        $uses = Database::getOpportunityCount( $coin, 'BTC', $exid );
        // Desired balance can only raise above zero if the coin is required at this exchange
        if ( $uses >= Config::get( Config::REQUIRED_OPPORTUNITIES, Config::DEFAULT_REQUIRED_OPPORTUNITIES ) ) {

          // Retrieve average exchange rates for this coin:
          $averageRate = Database::getAverageRate( $coin );

          $depositFee = abs( $exchange->getDepositFee( $coin, 1 ) * $averageRate );
          $withdrawFee = abs( $exchange->getWithdrawFee( $coin, 1 ) * $averageRate );
          $confTime = $exchange->getConfirmationTime( $coin );

          if ($depositFee < Config::get( Config::MAX_TX_FEE_ALLOWED, Config::DEFAULT_MAX_TX_FEE_ALLOWED ) &&
              $withdrawFee < Config::get( Config::MAX_TX_FEE_ALLOWED, Config::DEFAULT_MAX_TX_FEE_ALLOWED ) &&
              $confTime < Config::get( Config::MAX_MIN_CONFIRMATIONS_ALLOWED, Config::DEFAULT_MAX_MIN_CONFIRMATIONS_ALLOWED ) ) {
            $maxTradeSize = Config::get( Config::MAX_TRADE_SIZE, Config::DEFAULT_MAX_TRADE_SIZE );
            $balanceFactor = Config::get( Config::BALANCE_FACTOR, Config::DEFAULT_BALANCE_FACTOR );
  
            $desiredBalance = formatBTC( $maxTradeSize / $averageRate * $balanceFactor );
            $diff = abs( $desiredBalance - $balance );
            // Only allow a diff if the need is fulfillable:
            if ( $diff * $averageRate < $exchange->getSmallestOrderSize( $coin, 'BTC', 'buy' ) &&
                 $diff * $averageRate < $exchange->getSmallestOrderSize( $coin, 'BTC', 'sell' ) ) {
              $desiredBalance = $balance;
            }
          }
        }

        logg( "$exname | $coin | AVAILABLE: $balance | RATE: $rate | AVG.RATE: $averageRate | USES: $uses | TARGET: $desiredBalance" );
        Database::saveSnapshot( $coin, $balance, $desiredBalance, $rate, $exid, $time );
      }
    }

    $link = Database::connect();

    foreach ( $balances as $coin => $balance ) {
      Database::saveBalance( $coin, $balance, '0', $time, $link );
    }

    mysql_close( $link );

  }

  private function balanceCurrencies() {
    logg( "balanceCurrencies()" );
    $this->balance( 'BTC', true );

  }

  private function balance( $coin, $skipUsageCheck = false, $safetyFactorArg = 0.01 ) {

    logg( "balance($coin)" );
    if ( Config::isBlocked( $coin ) ) {
      logg( "Skipping: $coin is blocked!" );
      return;
    }

    $exchanges = [ ];
    $exchangeWallets = [ ];
    foreach ( $this->exchanges as $exchange ) {
      $wallets = $exchangeWallets[ $exchange->getName() ] =
        $exchange->getWalletsConsideringPendingDeposits();
      if ( key_exists( $coin, $wallets ) ) {
        $exchanges[] = $exchange;
      }
    }

    $requiredOpportunities = Config::get( Config::REQUIRED_OPPORTUNITIES, Config::DEFAULT_REQUIRED_OPPORTUNITIES );
    if ( $skipUsageCheck ) {
      $requiredOpportunities = 0;
    }

    $kickedExchanges = 0;
    $totalCoins = 0;
    foreach ( $exchanges as $exchange ) {

      $wallets = $exchangeWallets[ $exchange->getName() ];
      $balance = $wallets[ $coin ];
      $opportunityCount = Database::getOpportunityCount( $coin, 'BTC', $exchange->getID() );

      logg( str_pad( $exchange->getName(), 10, ' ', STR_PAD_LEFT ) . ": $balance $coin ($opportunityCount usages)" );

      $totalCoins += $balance;

      if ( $opportunityCount < $requiredOpportunities ) {
        $kickedExchanges++;
        continue;
      }
    }

    $count = count( $exchanges ) - $kickedExchanges;

    if ( $count == 0 ) {
      logg( "Not balancing as this coin is unused!" );
      return;
    }

    logg( "      TOTAL: $totalCoins $coin" );
    $averageCoins = formatBTC( $totalCoins / $count );
    logg( "    AVERAGE: $averageCoins $coin" );

    $positiveExchanges = [ ];
    $negativeExchanges = [ ];

    $minXFER = 0;
    $safetyFactor = $safetyFactorArg;
    if ( $coin == 'BTC' ) {
      $minXFER = Config::get( Config::MIN_BTC_XFER, Config::DEFAULT_MIN_BTC_XFER );
      // Be a bit more conservative with BTC, since it's our profits after all!
      $safetyFactor /= Config::get( Config::BTC_XFER_SAFETY_FACTOR,
                                    Config::DEFAULT_BTC_XFER_SAFETY_FACTOR );
    }
    $oneIsZero = false;
    $oneIsNearZero = false; // Only used for BTC
    $nearZeroThreshold = Config::get( Config::NEAR_ZERO_BTC_VALUE, Config::DEFAULT_NEAR_ZERO_BTC_VALUE );
    foreach ( $exchanges as $exchange ) {
      // Allow max 1% of coin amount to be transfer fee:
      $minXFER = max( $minXFER, $this->getSafeWithdrawFee( $exchange, $coin, $averageCoins ) / $safetyFactor );

      $wallets = $exchange->getWallets();
      if ( $wallets[ $coin ] == 0 ) {
        $oneIsZero = true;
      }
      if ( $coin == 'BTC' && $wallets[ $coin ] <= $nearZeroThreshold ) {
        $oneIsNearZero = true;
      }
    }
    logg( "XFER THRES.: $minXFER $coin" );

    foreach ( $exchanges as $exchange ) {

      $opportunityCount = Database::getOpportunityCount( $coin, 'BTC', $exchange->getID() );

      $wallets = $exchange->getWallets();
      $difference = formatBTC( $wallets[ $coin ] - ($opportunityCount < $requiredOpportunities ? 0 : $averageCoins) );

      if ( $oneIsZero && $wallets[ $coin ] > 2 * $minXFER &&
           $difference == 0 ) {
        // If the other exchange has run out of balance, send a minimum transfer to
        // the other side to unblock it.
        $difference = $minXFER;
      }

      if ( abs( $difference ) < $minXFER ) {
        logg( $exchange->getName() . " diff $difference $coin below xfer threshold!" );
        continue;
      }

      if ( $difference < 0 ) {
        logg( $exchange->getName() . " is missing $difference $coin" );
        $negativeExchanges[] = ['e' => $exchange, 'd' => abs( $difference ) ];
      }
      else if ( $difference > 0 ) {
        logg( $exchange->getName() . " could give $difference $coin" );
        $positiveExchanges[] = ['e' => $exchange, 'd' => $difference ];
      }
    }

    if ( count( $positiveExchanges ) == 0 || count( $negativeExchanges ) == 0 ) {
      if ( $oneIsNearZero && count( $exchanges ) > 2 ) {
        // While balancing currencies with more than 2 exchanges, if we get to a situation
        // where one of our exchanges is running out of its balance but we have been unable
        // to perform a rebalancing, if we just give up we may end up stuck in this local
        // minima for quite a while.  So to avoid this, we try to be less conservative and
        // relax our safety factor a bit to see if we'll manage to rebalance that way.
        if ( $safetyFactorArg <= 0.09 && // Don't lose more than 10% of our balance in transfers!
             // Throttle how often this feature kicks in.
             time() >= $this->stats[ self::STAT_NEXT_CURRENCY_AGGRESSIVE_BALANCE_ALLOWED ] ) {
          $this->stats[ self::STAT_NEXT_CURRENCY_AGGRESSIVE_BALANCE_ALLOWED ] = time() +
            Config::get( Config::INTERVAL_CURRENCY_AGGRESSIVE_BALANCE,
                         Config::DEFAULT_INTERVAL_CURRENCY_AGGRESSIVE_BALANCE ) * 1800;
          Database::saveStats( $this->stats );

          logg( sprintf( "Failed to rebalance with a safety factor of %d%%, trying %d%% now...",
                         floor( $safetyFactorArg * 100 ),
                         floor( ( $safetyFactorArg + 0.01 ) * 100 ) ) );
          return $this->balance( $coin, $skipUsageCheck, $safetyFactorArg + 0.01 );
        }
      }
      logg( "No exchange in need or available to give" );
      return;
    }

    while ( 'A' != 'B' ) {

      $from = -1;
      $to = -1;

      for ( $i = 0; $i < count( $negativeExchanges ); $i++ ) {
        $missing = $negativeExchanges[ $i ][ 'd' ];
        if ( $missing < $minXFER ) {
          continue;
        }
        $to = $i;

        for ( $j = 0; $j < count( $positiveExchanges ); $j++ ) {
          $offering = $positiveExchanges[ $j ][ 'd' ];
          if ( $offering < $minXFER ) {
            continue;
          }
          $from = $j;

          break;
        }

        break;
      }

      if ( $from < 0 || $to < 0 ) {
        break;
      }

      $amount = min( $positiveExchanges[ $from ][ 'd' ], $negativeExchanges[ $to ][ 'd' ] );
      $source = $positiveExchanges[ $from ][ 'e' ];
      $target = $negativeExchanges[ $to ][ 'e' ];

      $this->withdraw( $source, $target, $coin, $amount );

      $positiveExchanges[ $from ][ 'd' ] -= $amount;
      $negativeExchanges[ $to ][ 'd' ] -= $amount;

      //
    }

  }

  private function stuckDetection() {

    if ( !Config::get( Config::MODULE_STUCK_DETECTION, Config::DEFAULT_MODULE_STUCK_DETECTION ) ) {
      return;
    }

    logg( "stuckDetection()" );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->detectStuckTransfers();
    }

  }

  private function duplicateDetection() {

    if ( !Config::get( Config::MODULE_DUPLICATE_DETECTION, Config::DEFAULT_MODULE_DUPLICATE_DETECTION ) ) {
      return;
    }

    logg( "duplicateDetection()" );
    foreach ( $this->exchanges as $exchange ) {
      $exchange->detectDuplicateWithdrawals();
    }

  }

  private $unusedCoins = [ ];

  private function unusedCoinsDetection() {

    if ( !Config::get( Config::MODULE_UNUSED_COINS_DETECTION, Config::DEFAULT_MODULE_UNUSED_COINS_DETECTION ) ) {
      return;
    }

    logg( "unusedCoinsDetection()" );
    foreach ( $this->exchanges as $exchange ) {

      $xid = $exchange->getID();
      $ticker = $exchange->getTickers( 'BTC' );
      $wallets = $exchange->getWalletsConsideringPendingDeposits();

      foreach ( $wallets as $coin => $balance ) {

        if ( !key_exists( $xid, $this->unusedCoins ) || !key_exists( $coin, $this->unusedCoins[ $xid ] ) ) {
          logg( "[DEBUG] $xid $coin => init, set 0" );
          $this->unusedCoins[ $xid ][ $coin ] = 0;
        }

        if ( $balance == 0 ) {
          logg( "[DEBUG] $xid $coin => zero balance, set 0" );
          $this->unusedCoins[ $xid ][ $coin ] = 0;
          continue;
        }

        if ( Config::isCurrency( $coin ) ) {
          logg( "[DEBUG] $xid $coin => is currency, set 0" );
          $this->unusedCoins[ $xid ][ $coin ] = 0;
          continue;
        }

        if ( key_exists( $coin, $ticker ) && !Config::isBlocked( $coin ) ) {
          logg( "[DEBUG] $xid $coin => not blocked and traded, set 0" );
          $this->unusedCoins[ $xid ][ $coin ] = 0;
          continue;
        }

        if ( $this->unusedCoins[ $xid ][ $coin ] == 0 ) {
          logg( "Queueing $coin @ " . $exchange->getName() . " for liquidation notification..." );
          $this->unusedCoins[ $xid ][ $coin ] = time();
          continue;
        }

        if ( time() > $this->unusedCoins[ $xid ][ $coin ] + 3600 * 24 ) {
          $this->unusedCoins[ $xid ][ $coin ] = 0;
          logg( "Unused $balance $coin @ " . $exchange->getName() . ". It is safe to liquidate this coin!", true );
        }
      }
    }

  }

  private function takeProfit() {

    if ( !Config::get( Config::MODULE_TAKE_PROFIT, Config::DEFAULT_MODULE_TAKE_PROFIT ) ) {
      return;
    }

    logg( "takeProfit()" );

    $profitAddress = Config::get( Config::TAKE_PROFIT_ADDRESS, Config::DEFAULT_TAKE_PROFIT_ADDRESS );
    $profitLimit = Config::get( Config::TAKE_PROFIT_AMOUNT, Config::DEFAULT_TAKE_PROFIT_AMOUNT );

    if ( is_null( $profitAddress ) || is_null( $profitLimit ) || strlen( $profitAddress ) < 34 ) {
      logg( "Skipping: Configuration is incomplete/invalid" );
      return;
    }

    $totalBTC = 0;

    $highestExchange = null;
    $highestExchangeAmount = 0;

    foreach ( $this->exchanges as $exchange ) {
      $balance = $exchange->getWallets()[ 'BTC' ];

      if ( $balance > $highestExchangeAmount ) {
        $highestExchangeAmount = $balance;
        $highestExchange = $exchange;
      }

      $totalBTC += $balance;
    }

    $averageBTC = formatBTC( $totalBTC / count( $this->exchanges ) );

    $profit = formatBTC( $totalBTC - $profitLimit );
    logg( "Profit: " . $profit );
    $restockCash = formatBTC( min( Config::get( Config::TAKE_PROFIT_MIN_RESTOCK_CASH,
                                                Config::DEFAULT_TAKE_PROFIT_MIN_RESTOCK_CASH ),
                                   $profit * Config::get( Config::TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE,
                                                          Config::DEFAULT_TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE ) ) );

    $minXFER = Config::get( Config::MIN_BTC_XFER, Config::DEFAULT_MIN_BTC_XFER );
    // Be a bit more conservative with BTC, since it's our profits after all!
    $safetyFactor = 0.01 / Config::get( Config::BTC_XFER_SAFETY_FACTOR,
                                        Config::DEFAULT_BTC_XFER_SAFETY_FACTOR );
    foreach ( $this->exchanges as $exchange ) {
      // Allow max 1%/safetyFactor of coin amount to be transfer fee:
      $minXFER = max( $minXFER, $this->getSafeWithdrawFee( $exchange, 'BTC', $averageBTC ) / $safetyFactor );
    }

    $remainingProfit = formatBTC( $profit - $restockCash );
    if ( $remainingProfit < $minXFER ) {
      logg( "Not enough profit yet..." );
      return;
    }

    logg( "Withdrawing profit: $remainingProfit BTC to $profitAddress", true );
    if ( $highestExchange->withdraw( 'BTC', $remainingProfit, $profitAddress ) ) {
      $txFee = $this->getSafeWithdrawFee( $highestExchange, 'BTC', $averageBTC );
      Database::recordProfit( $remainingProfit - $txFee, 'BTC', $profitAddress, time() );
      Database::saveWithdrawal( 'BTC', $remainingProfit, $profitAddress, $highestExchange->getID(), 0,
                                $highestExchange->getWithdrawFee( 'BTC', $remainingProfit ) );

      // -------------------------------------------------------------------------
      $restockFunds = $this->stats[ self::STAT_AUTOBUY_FUNDS ];
      logg( "Overwriting restock funds..." );
      logg( "Restock cash before: $restockFunds BTC" );
      $this->stats[ self::STAT_AUTOBUY_FUNDS ] = $restockCash;
      logg( " Restock cash after: $restockCash BTC" );
      logg( "   Remaining profit: $remainingProfit BTC" );
      // -------------------------------------------------------------------------
    }

  }

  private function autobuyAltcoins( &$arbitrator ) {

    if ( !Config::get( Config::MODULE_AUTOBUY, Config::DEFAULT_MODULE_AUTOBUY ) ) {
      return;
    }

    logg( "autobuyAltcoins()" );

    $autobuyFunds = formatBTC( $this->stats[ self::STAT_AUTOBUY_FUNDS ] );
    logg( "Autobuy funds: $autobuyFunds BTC" );

    $autobuyAmount = formatBTC( min( Config::get( Config::MAX_BUY, Config::DEFAULT_MAX_BUY ), $autobuyFunds ) );
    logg( "Autobuying for $autobuyAmount BTC" );
    if ( $autobuyAmount < 0.0001 ) {
      logg( "Not enough autobuy funds" );
      $this->stats[ self::STAT_AUTOBUY_FUNDS ] = 0;
      return;
    }

    $needs = [ ];
    $allWallets = [ ];
    foreach ( $this->exchanges as $exchange ) {
      $allWallets[ $exchange->getID() ] = $exchange->getWalletsConsideringPendingDeposits();
    }

    logg( "Getting need..." );

    $results = Database::getCurrentSimulatedProfitRate();
    $stats = Database::getWalletStats();
    foreach ( $results as $row ) {

      $currency = $row[ 'currency' ];
      $coin = $row[ 'coin' ];
      if ( !Config::isCurrency( $currency ) ) {
        logg( "Skipping: $currency is not a currency!" );
        continue;
      }
      if ( Config::isCurrency( $coin ) || Config::isBlocked( $coin ) ) {
        logg( "Skipping: $coin is blocked!" );
        continue;
      }

      $exchangeID = $row[ 'ID_exchange_target'];
      $stat = $stats[ $coin ][ $exchangeID ];
      $exchange = $this->exchangesID[ $exchangeID ];
      $balance = $allWallets[ $exchangeID ][ $coin ];
      $desiredBalance = $stat[ 'desired_balance' ];

      $diff = formatBTC( $desiredBalance - $balance );
      if ( $diff > 0 ) {
        logg( "Need $diff $coin @ " . $exchange->getName() );
        // We add an entry to the $needs array ratio times to get a weighted randomized autobuy function.
        for ( $i = 0; $i < $row[ 'ratio' ]; $i++ ) {
          $needs[] = ['coin' => $coin, 'amount' => $diff, 'exchange' => $exchangeID ];
        }
      }
    }

    if ( count( $needs ) == 0 ) {
      logg( "No need!" );
      return;
    }

    // Try up to ten times to fill a need
    for ( $i = 0; $i < 10; $i++ ) {

      logg( "Rolling the dice..." );
      shuffle( $needs );
      $need = $needs[ 0 ];

      $exchange = $this->exchangesID[ $need[ 'exchange' ] ];
      $coin = $need[ 'coin' ];
      $needAmount = $need[ 'amount' ];

      logg( "Filling need $needAmount $coin @ " . $exchange->getName() );

      $orderbook = $exchange->getOrderbook( $coin, 'BTC' );
      if ( is_null( $orderbook ) ) {
        logg( "Invalid orderbook!" );
        continue;
      }
      $rate = formatBTC( $orderbook->getBestAsk()->getPrice() );
      $askAmount = $orderbook->getBestAsk()->getAmount();
      $buyAmount = formatBTC( min( $needAmount, min( $askAmount, $autobuyAmount / ($rate * 1.01) ) ) );
      $buyPrice = formatBTC( $buyAmount * $rate );
      if ( $buyPrice < $exchange->getSmallestOrderSize( $coin, 'BTC', 'buy' ) ) {
        logg( "Not enough coins at top of the orderbook!" );
        continue;
      }

      $tradeableBefore = $exchange->getWallets()[ $coin ];
      logg( "Posting buy order to " . $exchange->getName() . ": $buyAmount $coin for $buyPrice @ $rate" );
      $orderID = $exchange->buy( $coin, 'BTC', $rate, $buyAmount );
      if ( !is_null( $orderID ) ) {
        logg( "Waiting for order execution..." );
        sleep( Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) );

        if ( !$exchange->cancelOrder( $orderID ) ) {
          // Cancellation failed: Order has been executed!
          logg( "Order executed!" );
          Database::saveManagement( $coin, $buyAmount, $rate, $exchange->getID() );
          $this->stats[ self::STAT_AUTOBUY_FUNDS ] = formatBTC( $autobuyFunds - $buyPrice );

          // Make sure the wallets are updated for pending deposit calculations.
          $exchange->refreshWallets( true );

          $arbitrator->getTradeMatcher()->handlePostTradeTasks( $arbitrator, $exchange, $coin, 'BTC', 'buy',
                                                                $orderID, $buyAmount );
          return;
        }
      }
    }

  }

  private function manageWallets( &$arbitrator ) {

    logg( "manageWallets()" );

    self::saveSnapshot();

    self::autobuyAltcoins( $arbitrator );

    self::balanceCurrencies();

    self::balanceAltcoins();

    self::liquidateAltcoins( $arbitrator );

  }

  private function balanceAltcoins() {

    logg( "balanceAltcoins()" );

    if ( !Config::get( Config::MODULE_AUTOBALANCE, Config::DEFAULT_MODULE_AUTOBALANCE ) ) {
      logg( "Module disabled: Skipping balancing!" );
      return;
    }

    $allcoins = [ ];
    foreach ( $this->exchanges as $exchange ) {
      $wallets = $exchange->getWalletsConsideringPendingDeposits();
      foreach ( $wallets as $coin => $balance ) {
        if ( $balance > 0 ) {
          $allcoins[] = $coin;
        }
      }
    }

    $coins = array_unique( $allcoins );
    foreach ( $coins as $coin ) {
      if ( $coin == 'BTC' ) {
        continue;
      }
      $this->balance( $coin );
    }

  }

  private function liquidateAltcoins( &$arbitrator ) {
    logg( "liquidateAltcoins()" );

    if ( !Config::get( Config::MODULE_LIQUIDATE, Config::DEFAULT_MODULE_LIQUIDATE ) ) {
      logg( "Module disabled: Skipping liquidation!" );
      return;
    }

    if ( $this->getBotAgeInDays() < 7 ) {
      logg( "Bot not active long enough: Skipping liquidation!" );
      return;
    }

    logg( "Calculating overbalances..." );
    $overbalances = [ ];

    $stats = Database::getWalletStats();
    foreach ( $stats as $coin => $data ) {

      if ( Config::isCurrency( $coin ) || Config::isBlocked( $coin ) ) {
        logg( "Skipping: $coin is blocked!" );
        continue;
      }

      $entry = [ ];

      $coinIsNeeded = false;
      foreach ( $data as $exchangeID => $stat ) {

        $exchange = $this->exchangesID[ $exchangeID ];
        $wallets = $exchange->getWallets();
        $balance = $wallets[ $coin ];
        $desiredBalance = $stat[ 'desired_balance' ];

        $diff = formatBTC( $desiredBalance - $balance );
        if ( $diff == 0 ) {
          continue;
        }

        if ( $diff > 0 ) {
          logg( "Need $diff $coin @ " . $exchange->getName() );
          $coinIsNeeded = true;
          continue;
        }

        $aDiff = abs( $diff );

        logg( "Unneeded $aDiff $coin @ " . $exchange->getName() );
        $entry[] = ['coin' => $coin, 'amount' => $aDiff, 'exchange' => $exchangeID ];
      }

      if ( $coinIsNeeded ) {
        logg( "$coin is needed: Not liquidating!" );
        continue;
      }

      $overbalances = array_merge( $entry, $overbalances );
    }

    if ( count( $overbalances ) == 0 ) {
      logg( "No overbalances!" );
      return;
    }

    foreach ( $overbalances as $overbalance ) {

      $exchange = $this->exchangesID[ $overbalance[ 'exchange' ] ];
      $coin = $overbalance[ 'coin' ];
      $liquidationAmount = $overbalance[ 'amount' ];

      logg( "Liquidating $liquidationAmount $coin @ " . $exchange->getName() );

      $orderbook = $exchange->getOrderbook( $coin, 'BTC' );
      if ( is_null( $orderbook ) ) {
        logg( "Invalid orderbook!" );
        continue;
      }
      $rate = formatBTC( $orderbook->getBestBid()->getPrice() );
      $bidAmount = $orderbook->getBestBid()->getAmount();
      $sellAmount = formatBTC( min( $liquidationAmount, $bidAmount ) );
      $sellPrice = formatBTC( $sellAmount * $rate );
      if ( $sellPrice < $exchange->getSmallestOrderSize( $coin, 'BTC', 'sell' ) ) {
        logg( "Not enough coins at top of the orderbook!" );
        continue;
      }

      $tradeableBefore = $exchange->getWallets()[ $coin ];
      logg( "Posting sell order to " . $exchange->getName() . ": $sellAmount $coin for $sellPrice @ $rate" );
      $orderID = $exchange->sell( $coin, 'BTC', $rate, $sellAmount );
      if ( !is_null( $orderID ) ) {
        logg( "Waiting for order execution..." );
        sleep( Config::get( Config::ORDER_CHECK_DELAY, Config::DEFAULT_ORDER_CHECK_DELAY ) );

        if ( !$exchange->cancelOrder( $orderID ) ) {
          // Cancellation failed: Order has been executed!
          logg( "Order executed!" );
          Database::saveManagement( $coin, $sellAmount * -1, $rate, $exchange->getID() );

          // Make sure the wallets are updated for pending deposit calculations.
          $exchange->refreshWallets( true );

          $arbitrator->getTradeMatcher()->handlePostTradeTasks( $arbitrator, $exchange, $coin, 'BTC', 'sell',
                                                                $orderID, $sellAmount );
        }
      }
    }

  }

  public function getBotAgeInDays() {
    return floor( $this->getBotAgeInSeconds() / 24 * 3600 );

  }

  public function getBotAgeInSeconds() {
    return time() - $this->stats[ self::STAT_BOT_AGE ];

  }

  private function doWithdraw( $source, $coin, $amount, $address ) {

    try {
      return $source->withdraw( $coin, $amount, $address );
    }
    catch ( Exception $ex ) {
      // Perhaps the withdrawal was unsuccessful because of insufficient balance.
      // This can happen if the account only has $amount balance, in which case
      // we need to subtract the withdrawal fee.
      $amount -= $source->getWithdrawFee( $coin, $amount );
      return $source->withdraw( $coin, $amount, $address );
    }

    return false;

  }

  public function withdraw( $source, $target, $coin, $amount ) {

    if ( !Config::isCurrency( $coin ) ) {
      $limits = $source->getLimits( $coin, 'BTC' );

      if ( !is_null( $limits[ 'amount' ][ 'min' ] ) &&
           floatval( $limits[ 'amount' ][ 'min' ] ) > $amount ) {
        logg( sprintf( "Withdrawal amount %s below minimum trade amount %s",
                       $amount, $limits[ 'amount' ][ 'min' ] ) );
        return;
      }

      if ( !is_null( $limits[ 'amount' ][ 'max' ] ) &&
           floatval( $limits[ 'amount' ][ 'max' ] ) < $amount ) {
        logg( sprintf( "Withdrawal amount %s above maximum trade amount %s",
                       $amount, $limits[ 'amount' ][ 'max' ] ) );
        return;
      }
    }

    $amount = formatBTC( $amount );
    logg( "Transfering $amount $coin " . $source->getName() . " => " . $target->getName() );
    $address = $target->getDepositAddress( $coin );
    if ( is_null( $address ) || strlen( trim( $address ) ) == 0 ) {
      logg( "Invalid deposit address for " . $target->getName() . ", received: ". $address );

      return;
    }


    logg( "Depositaddress: $address" );
    if ( $this->doWithdraw( $source, $coin, $amount, trim( $address ) ) ) {
      Database::saveWithdrawal( $coin, $amount, trim( $address ), $source->getID(), $target->getID(),
                                $source->getWithdrawFee( $coin, $amount ) +
                                $target->getDepositFee( $coin, $amount ) );
    }

  }

  public function getSafeDepositFee( $exchange, $tradeable, $amount ) {

    $fee = $exchange->getDepositFee( $tradeable, $amount );
    if ( is_null( $fee ) ) {
      $fee = 0;
    }

    return $fee;

  }

  public function getSafeWithdrawFee( $exchange, $tradeable, $amount ) {

    $fee = $exchange->getWithdrawFee( $tradeable, $amount );
    if ( !is_null( $fee ) ) {
      return $fee;
    }

    $txFees = [ ];

    // Average fee from other exchanges:
    foreach ( $this->exchanges as $x ) {

      if ( $x === $exchange ) {
        continue;
      }

      $txFee = $x->getWithdrawFee( $tradeable, $amount );
      if ( !is_null( $txFee ) ) {
        $txFees[] = $txFee;
      }
    }

    if ( count( $txFees ) == 0 ) {
      logg( "[" . $exchange->getName() . "] WARNING: Unknown transfer fee for $tradeable. Calculation may be inaccurate!" );
      return 0;
    }

    return max( $txFees );

  }

}
