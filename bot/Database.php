<?php
require_once __DIR__ . '/../lib/mysql.php';
class Database {

  const STATISTICS_MAX_AGE = 432000;

  private static $trades = null;

  public static function connect() {

    $dbHost = Config::get( Config::DB_HOST, null );
    $dbName = Config::get( Config::DB_NAME, null );
    $dbUser = Config::get( Config::DB_USER, null );
    $dbPass = Config::get( Config::DB_PASS, null );

    if ( is_null( $dbHost ) || is_null( $dbName ) || is_null( $dbUser ) || is_null( $dbPass ) ) {
      throw new Exception( 'Database configuration data missing or incomplete' );
    }

    $link = mysql_connect( $dbHost, $dbUser, $dbPass, true );
    if ( !$link ) {
      throw new Exception( 'database error: ' . mysql_error( $link ) );
    }
    mysql_select_db( $dbName, $link );
    return $link;

  }

  /*
   * Helper to clean too frequent track entries in db
    public static function cleanTrack() {

    $link = self::connect();

    $query = "SELECT * FROM track;";

    $result = mysql_query( $query, $link );
    if ( !$result ) {
    throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $opb = [ ];

    $deletions = [ ];

    $tracks = [ ];

    echo "REMOVING USELESS TRACKS...\n";

    while ( $row = mysql_fetch_assoc( $result ) ) {

    $id = $row[ 'ID' ];
    $xid = $row[ 'ID_exchange' ];
    $coin = $row[ 'coin' ];
    $created = $row[ 'created' ];

    if ( !array_key_exists( $coin, $opb ) ) {
    $opb[ $coin ] = self::getOpportunityCount( $coin, $currency, 0 );
    }

    if ( !array_key_exists( $xid, $tracks ) ) {
    $tracks[ $xid ] = [ ];
    }
    if ( !array_key_exists( $coin, $tracks[ $xid ] ) ) {
    $tracks[ $xid ][ $coin ] = 0;
    }

    if ( $created < $tracks[ $xid ][ $coin ] + 3600 ) {
    echo "Dropping track $id ($coin @ $xid)\n";

    mysql_query( "DELETE FROM track WHERE ID = $id", $link );

    if ( !array_key_exists( $coin, $deletions ) ) {
    $deletions[ $coin ] = 0;
    }
    $deletions[ $coin ] ++;
    }
    else {
    $tracks[ $xid ][ $coin ] = $created;
    }
    }

    echo "\n\nSUMMARY:\n";
    foreach ( $deletions as $coin => $value ) {
    $opa = self::getOpportunityCount( $coin, $currency, 0 );
    echo "$coin has $value deletions | ";
    echo $opb[ $coin ] . " uses before | ";
    echo "$opa uses after!";
    if ( $opb[ $coin ] >= 3 && $opa < 3 ) {
    echo " (KILLED A COIN)";
    }
    echo "\n";
    }

    mysql_close( $link );

    }
   */

  public static function cleanup() {

    $link = self::connect();

    $age = time() - Config::get( Config::MAX_LOG_AGE, Config::DEFAULT_MAX_LOG_AGE ) * 3600;

    if ( !mysql_query( sprintf( "DELETE FROM log WHERE created < %d;", $age ), $link ) ) {
      throw new Exception( "database cleanup error: " . mysql_error( $link ) );
    }

    $rows = mysql_affected_rows( $link );

    mysql_close( $link );

    return $rows;

  }

  public static function insertAlert( $type, $message ) {

    $link = self::connect();

    if ( !mysql_query( sprintf( "INSERT INTO alerts (type, message, created) VALUES ('%s', '%s', %d);",
                                mysql_escape_string( $type ),
                                mysql_escape_string( strip_tags( $message ) ), time() ), $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function log( $message ) {

    $link = self::connect();

    if ( !mysql_query( sprintf( "INSERT INTO log (message, created) VALUES ('%s', %d);", mysql_escape_string( strip_tags( $message ) ), time() ), $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  private static function recordBalance( $coin, $sma, $balance, $exchangeID, $time, $link ) {

    // Record the balance entry.
    $query = sprintf( "INSERT INTO balances (coin, `value`, `raw`, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d);", //
            $coin, //
            formatBTC( $sma ), //
            formatBTC( $balance ), //
            $exchangeID, //
            $time //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

  }

  public static function queryBalanceMovingAverage( $coin, $balance, $exchangeID, $link ) {

    // Read the three most recent balances for this coin on this exchange.
    $query = '';
    if ( $exchangeID == '0' ) {
      $query = sprintf( "SELECT SUM(raw) AS amount FROM balances WHERE coin = '%s' AND ID_exchange = '0' GROUP BY created ORDER BY created DESC LIMIT 3", $coin );
    } else {
      $query = sprintf( "SELECT SUM(raw) AS amount FROM balances WHERE coin = '%s' AND ID_exchange = %d GROUP BY created ORDER BY created DESC LIMIT 3",
                        $coin, $exchangeID );
    }
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $data = [];
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $data[] = $row;
    }
    $prevBalanceSum = array_reduce( $data, 'sumOfAmount', 0 );
    return ($prevBalanceSum + $balance) / (1 + count( $data ));

  }

  public static function saveBalance( $coin, $balance, $exchangeID, $time, $link ) {

    $sma = self::queryBalanceMovingAverage( $coin, $balance, $exchangeID, $link );
    self::recordBalance( $coin, $sma, $balance, $exchangeID, $time, $link );

  }

  public static function saveSnapshot( $coin, $balance, $desiredBalance, $rate, $exchangeID, $time ) {

    $link = self::connect();

    self::saveBalance( $coin, $balance, $exchangeID, $time, $link );

    // Record the snapshot entry.
    $query = sprintf( "INSERT INTO snapshot (coin, balance, desired_balance, uses, trades, rate, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d, '%s', %d, %d);", //
            $coin, //
            formatBTC( $balance ), //
            formatBTC( $desiredBalance ), //
            self::getOpportunityCount( $coin, 'BTC', $exchangeID ), //
            self::getTradeCount( $coin, $exchangeID ), //
            formatBTC( $rate ), //
            $exchangeID, //
            $time //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveManagement( $coin, $amount, $rate, $exchange ) {
    $link = self::connect();
    $query = sprintf( "INSERT INTO management (amount, coin, rate, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d);", //
            formatBTC( $amount ), //
            $coin, //
            formatBTC( $rate ), //
            $exchange, //
            time() //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveTrack( $coin, $currency, $amount, $profit, $source, $target ) {

    $link = self::connect();

    $sourceID = $source->getID();
    $targetID = $target->getID();

    $lastTrackTime = self::getLastTrackTime( $coin, $currency, $sourceID, $targetID );
    if ( $lastTrackTime > time() - Config::get( Config::OPPORTUNITY_SAVE_INTERVAL, Config::DEFAULT_OPPORTUNITY_SAVE_INTERVAL ) * 60 ) {
      $targetName = $target->getName();
      logg( "[DB] Omitting track $amount $coin @ $targetName as previous entry is too young" );
      return;
    }

    $query = sprintf( "INSERT INTO track (amount, coin, currency, profit, ID_exchange_source, ID_exchange_target, created) VALUES ('%s', '%s', '%s', '%s', %d, %d, %d);", //
            formatBTC( $amount ), //
            $coin, $currency, //
            formatBTC( $profit ), //
            $sourceID, $targetID, //
            time() //
    );

    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function getLastTrackTime( $coin, $currency, $sourceID, $targetID ) {

    $link = self::connect();

    $query = sprintf( "SELECT MAX(created) AS created FROM track WHERE coin = '%s' AND " .
                      "currency = '%s' AND ID_exchange_source = %d AND ID_exchange_target = %d;", //
            $coin, //
            $currency, //
            $sourceID,
            $targetID
    );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $data = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $data = $row[ 'created' ];
    }

    mysql_close( $link );

    return $data;

  }

  public static function saveTrade( $coin, $currency, $amount, $exchangeSource, $exchangeTarget ) {
    $link = self::connect();
    $query = sprintf( "INSERT INTO trade (coin, currency, amount, ID_exchange_source, ID_exchange_target, created) VALUES ('%s', '%s', '%s', %d, %d, %d);", //
            $coin, //
            $currency, //
            formatBTC( $amount ), //
            $exchangeSource, //
            $exchangeTarget, //
            time() //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveWithdrawal( $coin, $amount, $address, $sourceExchangeID, $targetExchangeID ) {

    $link = self::connect();
    $query = sprintf( "INSERT INTO withdrawal (amount, coin, address, ID_exchange_source, ID_exchange_target, created) VALUES ('%s', '%s', '%s', %d, %d, %d);", //
            formatBTC( $amount ), //
            $coin, //
            $address, //
            $sourceExchangeID, //
            $targetExchangeID, //
            time() //
    );

    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveStats( $stats ) {
    $link = self::connect();

    foreach ( $stats as $key => $value ) {

      $query = sprintf( "INSERT INTO stats (keyy, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = '%s';", //
              mysql_escape_string( $key ), //
              mysql_escape_string( $value ), //
              mysql_escape_string( $value )
      );
      if ( !mysql_query( $query, $link ) ) {
        throw new Exception( "database insertion error ($query): " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

  }

  public static function getWalletStats() {

    $link = self::connect();

    $query = 'SELECT * FROM current_snapshot';

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $coin = $row[ 'coin' ];
      $exid = $row[ 'ID_exchange' ];

      $results[ $coin ][ $exid ][ 'balance' ] = $row[ 'balance' ];
      $results[ $coin ][ $exid ][ 'desired_balance' ] = $row[ 'desired_balance' ];
      $results[ $coin ][ $exid ][ 'balance_diff' ] = formatBTC( $row[ 'desired_balance' ] - $row[ 'balance' ] );
      $results[ $coin ][ $exid ][ 'opportunities' ] = $row[ 'uses' ];
    }

    mysql_close( $link );

    ksort( $results );

    return $results;

  }

  public static function getCurrentSimulatedProfitRate() {

    $link = self::connect();

    $query = 'SELECT * FROM current_simulated_profit_rate ORDER BY ratio DESC';

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $results[] = $row;
    }

    mysql_close( $link );

    return $results;

  }


  public static function getOpportunityCount( $coin, $currency, $exchangeID ) {

    $maxAge = time() - Config::get( Config::OPPORTUNITY_COUNT_AGE, Config::DEFAULT_OPPORTUNITY_COUNT_AGE ) * 3600;

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(ID) AS CNT FROM track WHERE coin = '%s' AND currency = '%s' %s AND created >= %d", //
            mysql_escape_string( $coin ), //
            mysql_escape_string( $currency ), //
            $exchangeID > 0 ? sprintf( "AND ID_exchange_target = %d", $exchangeID ) : "", //
            $maxAge //
    );

    $data = mysql_query( $query, $link );
    if ( !$data ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $result = null;
    while ( $row = mysql_fetch_assoc( $data ) ) {
      $result = $row[ 'CNT' ];
    }

    mysql_close( $link );

    // do not allow less opportunities than actual trades!
    return max( self::getTradeCount( $coin, $exchangeID ), $result );

  }

  public static function getTradeCount( $coin, $exchangeID ) {

    $maxAge = time() - Config::get( Config::OPPORTUNITY_COUNT_AGE, Config::DEFAULT_OPPORTUNITY_COUNT_AGE ) * 3600;

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(ID) AS CNT FROM trade WHERE coin = '%s' %s AND created >= %d", //
            mysql_escape_string( $coin ), //
            $exchangeID > 0 ? sprintf( "AND ID_exchange_target = %d", $exchangeID ) : "", //
            $maxAge //
    );

    $data = mysql_query( $query, $link );
    if ( !$data ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $result = null;
    while ( $row = mysql_fetch_assoc( $data ) ) {
      $result = $row[ 'CNT' ];
    }

    mysql_close( $link );

    return $result;

  }

  public static function getAverageRate( $coin ) {

    $link = self::connect();

    $query = sprintf( "SELECT AVG(rate) AS rate FROM snapshot WHERE coin = '%s' GROUP BY created ORDER BY created", mysql_escape_string( $coin ) );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    if ( mysql_num_rows( $result ) < 5 ) {
      return -1;
    }

    $period = Config::get( Config::RATE_EMA_PERIOD, Config::DEFAULT_RATE_EMA_PERIOD );
    $k = 2 / ($period + 1);

    $ema = -1;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $rate = $row[ "rate" ];

      $ema = $rate * $k + ($ema < 0 ? $rate : $ema) * (1 - $k);
    }

    mysql_close( $link );

    return formatBTC( $ema );

  }

  public static function handleAddressUpgrade() {

    $link = self::connect();

    $result = mysql_query( "SHOW COLUMNS FROM withdrawal LIKE 'address';", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    $row = mysql_fetch_assoc( $result );
    if ( $row[ 'Type' ] == 'char(35)' ) {
      // Old database format, need to upgrade first.
      $result = mysql_query( "ALTER TABLE withdrawal MODIFY address TEXT NOT NULL;", $link );
      if ( !$result ) {
        throw new Exception( "database alteration error: " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

  }

  public static function handleCoinUpgrade() {

    $link = self::connect();

    $result = mysql_query( "SHOW COLUMNS FROM management LIKE 'coin';", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $row = mysql_fetch_assoc( $result );
    if ( $row[ 'Type' ] == 'char(5)' ) {
      // Old database format, need to upgrade first.
      $upgrades = array(
        [ 'balances', 'coin' ],
        [ 'exchange_trades', 'coin' ],
        [ 'exchange_trades', 'currency' ],
        [ 'management', 'coin' ],
        [ 'profits', 'currency' ],
        [ 'profit_loss', 'coin' ],
        [ 'profit_loss', 'currency' ],
        [ 'snapshot', 'coin' ],
        [ 'track', 'coin' ],
        [ 'trade', 'coin' ],
        [ 'withdrawal', 'coin' ],
      );
      foreach ( $upgrades as $item ) {
        $table = $item[ 0 ];
        $field = $item[ 1 ];
        $result = mysql_query( sprintf( "ALTER TABLE %s MODIFY %s CHAR(10) NOT NULL;",
                                        $table, $field ), $link );
        if ( !$result ) {
          throw new Exception( "database alteration error: " . mysql_error( $link ) );
        }
      }
    }

    mysql_close( $link );

  }

  public static function handleTrackUpgrade() {

    $link = self::connect();

    $result = mysql_query( "SHOW COLUMNS FROM track LIKE 'currency';", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    if ( mysql_num_rows( $result ) === 0 ) {
      // Old database format, need to upgrade first.
      $result = mysql_query( "ALTER TABLE track ADD currency CHAR(5) NOT NULL AFTER coin;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
      $result = mysql_query( "UPDATE track SET currency = 'BTC';", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
    }

    $result = mysql_query( "SHOW COLUMNS FROM track LIKE 'ID_exchange_source';", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    if ( mysql_num_rows( $result ) === 0 ) {
      // Old database format, need to upgrade first.
      $result = mysql_query( "ALTER TABLE track ADD ID_exchange_source INT(11) NOT NULL AFTER profit;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
      $result = mysql_query( "ALTER TABLE track CHANGE ID_exchange ID_exchange_target INT(11) NOT NULL;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
      // We don't know exactly how to fill in the new column, so let's just hope the user hasn't used a third exchange yet.
      $result = mysql_query( "UPDATE track SET ID_exchange_source = 1 WHERE ID_exchange_target = 3;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
      $result = mysql_query( "UPDATE track SET ID_exchange_source = 3 WHERE ID_exchange_target = 1;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

  }

  public static function getStats() {

    $link = self::connect();

    $result = mysql_query( "SELECT * FROM stats", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $results[ $row[ "keyy" ] ] = $row[ "value" ];
    }

    mysql_close( $link );

    return $results;

  }

  public static function getPL() {

    $link = self::connect();

    $result = mysql_query( "SELECT trade.created AS created, trade.coin AS coin, trade.currency AS currency, " .
                           "       trade.amount AS amount, ID_exchange_source AS source, ID_exchange_target AS target, message " .
                           "FROM trade, log WHERE message LIKE 'TRADE SUMMARY:\\nPAIR: %' AND trade.created = log.created " .
                           "ORDER BY trade.created DESC", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    $data = array();
    $total_pl = 0;
    $pl_currency = '';
    $profitables = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $message = $row[ 'message' ];
      if (!preg_match( '/^TRADE SUMMARY:\n(?:[^\n]+\n){3}\n(?:[^\n]+\n)\s*' .
                       $row[ 'coin' ] . '[^\n]+?([0-9.]+)\n\s*' .
                       $row[ 'currency' ] . '[^\n]+?(-?[0-9.]+)\n' .
                       '\n(?:[^\n]+\n)\s*' . $row[ 'coin' ] . '[^\n]+?(-?[0-9.]+)\n\s*' .
                       $row[ 'currency' ] . '[^\n]+?([0-9.]+)\n' .
                       '\n(?:[^\n]+\n)\s*' . $row[ 'currency' ] . '[^\n]+?(-?[0-9.]+)\n' .
                       '(?:[^\n]+\n){2}\n\(Transfer fee is ([0-9.]+)\)/',
                       $message, $matches )) {
        throw new Exception( "invalid log message encountered: " . $message );
      }
      $exchange = Exchange::createFromID( $row[ 'target' ] );

      $price_sold = $matches[ 2 ] / $exchange->deductFeeFromAmountSell( $row[ 'amount' ], $row[ 'coin' ], $row[ 'currency' ] );
      $tx_fee = $matches[ 6 ] * $price_sold;
      $pl = $matches[ 4 ] - $tx_fee;
      if ($pl > 0) {
        $profitables++;
      }
      $total_pl += $pl;
      if (empty( $pl_currency )) {
        $pl_currency = $row[ 'currency' ];
      } else if ($row[ 'currency' ] != $pl_currency) {
        throw new Exception( "P&L currency changed from ${pl_currency} to ${row['currency']} unexpectedly." );
      }
      $data[] = [
        'time' => $row[ 'created' ],
        'coin' => $row[ 'coin' ],
        'currency' => $row[ 'currency' ],
        'amount_bought_tradeable' => floatval( $matches[ 1 ] ),
        'amount_sold_tradeable' => abs( $matches[ 3 ] ),
        'tradeable_bought' => floatval( $matches[ 1 ] ),
        'tradeable_sold' => abs( $matches[ 3 ] ),
        'currency_bought' => abs( $matches[ 2 ] ),
        'currency_sold' => floatval( $matches[ 4 ] ),
        'currency_revenue' => floatval( $matches[ 5 ] ),
        'tx_fee_tradeable' => floatval( $matches[ 6 ] ),
        'source_exchange' => $row[ 'source' ],
        'target_exchange' => $row[ 'target' ],
      ];
    }

    mysql_close( $link );

    return $data;
  }

  public static function saveProfitLoss( $coin, $currency, $time, $sourceExchange, $targetExchange,
                                         $rawTradeIDsBuy, $tradeIDsBuy, $rawTradeIDsSell, $tradeIDsSell,
                                         $rateBuy, $rateSell, $tradeableBought, $tradeableSold,
                                         $currencyBought, $currencySold, $currencyRevenue, $currencyProfitLoss,
                                         $tradeableTransferFee, $currencyTransferFee, $buyFee, $sellFee ) {

    $link = self::connect();
    $query = sprintf( "INSERT INTO profit_loss (created, ID_exchange_source, ID_exchange_target, coin, " .
                      "                         currency, raw_trade_IDs_buy, trade_IDs_buy, " .
                      "                         raw_trade_IDs_sell, trade_IDs_sell, rate_buy, " .
                      "                         rate_sell, tradeable_bought, tradeable_sold, " .
                      "                         currency_bought, currency_sold, currency_revenue, currency_pl, " .
                      "                         tradeable_tx_fee, currency_tx_fee, buy_fee, sell_fee) VALUES " .
                      "  (%d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', " .
                      "   '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');",
            $time, $sourceExchange, $targetExchange, $coin, $currency, $rawTradeIDsBuy, $tradeIDsBuy,
            $rawTradeIDsSell, $tradeIDsSell, formatBTC( $rateBuy ), formatBTC( $rateSell ),
            formatBTC( $tradeableBought ), formatBTC( $tradeableSold ), formatBTC( $currencyBought ),
            formatBTC( $currencySold ), formatBTC( $currencyRevenue ), formatBTC( $currencyProfitLoss ),
            formatBTC( $tradeableTransferFee ), formatBTC( $currencyTransferFee ), formatBTC( $buyFee ),
            formatBTC( $sellFee ) );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function fixupProfitLossCalculations( &$exchanges ) {
    $stats = self::getStats();

    if ( @$stats[ 'profit_loss_fixup' ] != 1 ) {
      $link = self::connect();

      $result = mysql_query( "SELECT ID, created, ID_exchange_source, coin, tradeable_bought, rate_sell, tradeable_tx_fee, currency_tx_fee, currency_pl FROM profit_loss " .
                             "WHERE trade_IDs_buy != '' OR trade_IDs_sell != '' OR raw_trade_IDs_buy != '' OR raw_trade_IDs_sell != '';",
                             $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }

      $exchangeMap = [ ];
      foreach ( $exchanges as $ex ) {
        $exchangeMap[ $ex->getID() ] = $ex;
        $ex->refreshExchangeData();
      }
      $cm = new CoinManager( $exchanges );

      print "Fixing incorrect transfer fee calculations in the Profit&Loss data, this may take a while...\n";

      while ( $row = mysql_fetch_assoc( $result ) ) {
        // Poor man's progress bar
        print strftime( "\rChecking transaction performed on %Y-%m-%d %H:%M:%S", $row[ 'created' ] );

        $oldFee = floatval( $row[ 'tradeable_tx_fee' ] );
        $oldFeeInCurrency = floatval( $row[ 'currency_tx_fee' ] );
        $newFee = floatval( $cm->getSafeTxFee( $exchangeMap[ $row[ 'ID_exchange_source' ] ], $row[ 'coin' ], $row[ 'tradeable_bought' ] ) );
        $newFeeInCurrency = floatval( $row[ 'rate_sell' ] * $newFee );
        if ( $oldFee != $newFee ) {
          $diff = $newFeeInCurrency - $oldFeeInCurrency;
          if ( $diff >= 0 ) {
            // The fee data obtained from exchanges is subject to change.  If we get a positive diff here,
            // the only possible explanation is a change in the transfer fees, so we can't know anything
            // conclusive about what the real fees were at the transaction time unfortunately any more...
            continue;
          }

          $result2 = mysql_query( sprintf( "UPDATE profit_loss SET tradeable_tx_fee = '%s', currency_tx_fee = '%s', " .
                                           "currency_pl = '%s' WHERE ID = %d;",
                                           formatBTC( $newFee ), formatBTC( $newFeeInCurrency ),
                                           formatBTC( $row[ 'currency_pl' ] - $diff ), $row[ 'ID' ] ),
                                  $link );
          if ( !$result2 ) {
            throw new Exception( "database insertion error: " . mysql_error( $link ) );
          }
        }
      }

      print "\n";

      $stats[ 'profit_loss_fixup' ] = 1;
      self::saveStats( $stats );
    }
  }

  public static function getTop5ProfitableCoinsOfTheDay() {

    $link = self::connect();

    $query = "SELECT CONCAT(ID_exchange_source, '-', ID_exchange_target) AS exchange, coin, currency, SUM(currency_pl) AS pl " .
             "FROM profit_loss " .
             "WHERE DATE_FORMAT(FROM_UNIXTIME(created), GET_FORMAT(DATE,'ISO')) = DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP()), GET_FORMAT(DATE,'ISO')) AND currency_pl > 0 " .
             "GROUP BY DATE_FORMAT(FROM_UNIXTIME(created), GET_FORMAT(DATE,'ISO')), exchange, coin, currency " .
             "ORDER BY pl DESC LIMIT 5";
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $results[] = $row;
    }

    mysql_close( $link );

    return $results;

  }

  public static function saveExchangeTrade( $exchangeID, $type, $coin, $currency, $time, $rawTradeID, $tradeID,
                                            $rate, $amount, $fee, $total ) {

    $link = self::connect();
    self::ensureTradesUpdated( $link );
    self::$trades[ $rawTradeID ] = true;

    $query = sprintf( "REPLACE INTO exchange_trades (created, ID_exchange, coin, currency, " .
                      "                              raw_trade_ID, trade_ID, rate, amount, " .
                      "                              fee, total, type) VALUES " .
                      "  (%d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');",
             $time, $exchangeID, $coin, $currency, $rawTradeID, $tradeID, formatBTC( $rate ), 
             formatBTC( $amount ), formatBTC( $fee ), formatBTC( $total ), $type );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  private static function tableExistsHelper( $name ) {

    $link = self::connect();

    if ( !mysql_query( sprintf( "SELECT * FROM information_schema.tables WHERE table_schema = '%s' " .
                                "AND table_name = '%s' LIMIT 1;",
                                mysql_escape_string( Config::get( Config::DB_NAME, null ) ),
                                mysql_escape_string( $name ) ), $link ) ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $rows = mysql_affected_rows( $link );
    $result = $rows > 0;

    mysql_close( $link );

    return $result;

  }

  public static function createTableHelper( $name ) {

    $link = self::connect();

    $query = file_get_contents( __DIR__ . sprintf( '/../%s.sql', $name ) );

    foreach ( explode( ';', $query ) as $q ) {
      $q = trim( $q );
      if ( !strlen( $q ) ) {
        continue;
      }
      if ( !mysql_query( $q, $link ) ) {
        throw new Exception( "database insertion error: " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

    return true;

  }

  public static function alertsTableExists() {

    return self::tableExistsHelper( 'alerts' );

  }

  public static function createAlertsTable() {

    return self::createTableHelper( 'alerts' );

  }

  public static function importAlerts() {

    $link = self::connect();

    $result = mysql_query( "SELECT ID, created FROM log WHERE message = 'stuckDetection()' ORDER BY created ASC", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    while ( $row = mysql_fetch_assoc( $result ) ) {
      $id = $row[ 'ID' ];
      // Poor man's progress bar
      print strftime( "\rLooking at stuck withdrawal check performed on %Y-%m-%d %H:%M:%S", $row[ 'created' ] );

      $result2 = mysql_query( "SELECT created, message FROM log WHERE ID > $id ORDER BY ID ASC", $link );
      if ( !$result2 ) {
	throw new Exception( "database selection error: " . mysql_error( $link ) );
      }

      while ( $row = mysql_fetch_assoc( $result2 ) ) {
	if (!preg_match( '/Please investigate and open support ticket if neccessary/', $row[ 'message' ] )) {
	  break;
	}
	$result3 = mysql_query( sprintf( "INSERT INTO alerts(type, created, message) " .
                                         "VALUES ('stuck-transfer', %d, '%s');",
                                         $row[ 'created' ],
                                         mysql_escape_string( $row[ 'message' ] ) ),
                                $link );
	if ( !$result3 ) {
	  throw new Exception( "database selection error: " . mysql_error( $link ) );
	}
      }
    }

    print "\n";

    mysql_close( $link );

 }
 
  public static function balancesTableExists() {

    return self::tableExistsHelper( 'balances' );

  }

  public static function createBalancesTable() {

    return self::createTableHelper( 'balances' );

  }
  
  public static function getSmoothedResultsForGraph( $result ) {
  
    $ma = [ ];
  
    $data = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {
  
      $value = floatval( $row[ 'data' ] );
      $ex = $row[ 'ID_exchange' ];
  
      if (!in_array( $ex, array_keys( $ma ) )) {
        $ma[$ex] = [ ];
      }
      $ma[$ex][] = $value;
      while ( count( $ma[$ex] ) > 4 ) {
        array_shift( $ma[$ex] );
      }
  
      $sma = array_sum( $ma[$ex] ) / count( $ma[$ex] );
      $data[] = ['time' => $row[ 'created' ], 'value' => $sma , 'raw' => $value,
                 'exchange' => $ex ];
    }
  
    return $data;
  
  }

  private static function importBalancesHelper( $coin, $exchange, $link ) {

    $query = '';
    if ( $exchange === '0' ) {
      $query = sprintf( "SELECT SUM(balance) AS data, created, '0' AS ID_exchange FROM snapshot WHERE coin = '%s' GROUP BY created;", //
              mysql_escape_string( $coin )
      );
    } else {
      // There is only one ID_exchange that we're selecting on, so MAX(ID_exchange) is the same as ID_exchange.
      $query = sprintf( "SELECT SUM(balance) AS data, created, MAX(ID_exchange) AS ID_exchange FROM snapshot WHERE coin = '%s' AND ID_exchange = %d GROUP BY created;", //
              mysql_escape_string( $coin ), //
              mysql_escape_string( $exchange )
      );
    }
   
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $data = self::getSmoothedResultsForGraph( $result );

    foreach ( $data as $row ) {
      self::recordBalance( $coin, $row[ 'value' ], $row[ 'raw' ], $exchange, 
                           $row[ 'time' ], $link );
    }

  }

  public static function importBalances() {

    $link = self::connect();

    // We want to iterate over all unique pairs (coin, exchange)
    $result = mysql_query( "SELECT DISTINCT coin, ID_exchange AS exchange FROM snapshot ORDER BY coin ASC, exchange ASC;", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $prevCoin = '';
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $coin = $row[ 'coin' ];
      $exchange = $row[ 'exchange' ];
      $name = 'unknown';
      try {
        $name = Exchange::getExchangeName( $exchange );
      }
      catch ( Exception $ex ) {
      }
      // Poor man's progress bar
      printf( "\rImporting balances for %s on %s", $coin, $name );

      if ( $prevCoin != '' && $prevCoin != $coin ) {
        self::importBalancesHelper( $prevCoin, '0', $link );
      }
      if ( $prevCoin != $coin ) {
        $prevCoin = $coin;
      }
      self::importBalancesHelper( $coin, $exchange, $link );
    }

    // Handle the boundary condition
    if ( $prevCoin != '' ) {
      self::importBalancesHelper( $prevCoin, '0', $link );
    }

    print "\n";

    mysql_close( $link );

  }

  public static function currentSimulatedProfitsRateViewExists() {

    return self::tableExistsHelper( 'current_simulated_profits_rate' );

  }

  public static function createCurrentSimulatedProfitsRateView() {

    return self::createTableHelper( 'current_simulated_profits_rate' );

  }

  public static function pendingDepositsTableExists() {

    return self::tableExistsHelper( 'pending_deposits' );

  }

  public static function createPendingDepositsTable() {

    return self::createTableHelper( 'pending_deposits' );

  }

  public static function profitsTableExists() {

    return self::tableExistsHelper( 'profits' );

  }

  public static function createProfitsTable() {

    return self::createTableHelper( 'profits' );

  }

  public static function importProfits() {

    $link = self::connect();

    $result = mysql_query( "SELECT message, created FROM log WHERE message LIKE 'Withdrawing profit: %' ORDER BY created ASC", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    while ( $row = mysql_fetch_assoc( $result ) ) {
      if (!preg_match( '/^Withdrawing profit: ([0-9\.]+) BTC to (.*)$/',
                       $row[ 'message' ], $matches )) {
        break;
      }
      Database::recordProfit( $matches[ 1 ], 'BTC', $matches[ 2 ], $row[ 'created' ] );
    }

    mysql_close( $link );

  }

  public static function recordProfit( $amount, $currency, $address, $created ) {

    $link = self::connect();
    $percentRestock = Config::get( Config::TAKE_PROFIT_MIN_RESTOCK_CASH,
                                   Config::DEFAULT_TAKE_PROFIT_MIN_RESTOCK_CASH );

    if ( !mysql_query( sprintf( "INSERT INTO profits (created, currency, amount, cash_restock_percent, " .
                                                      "cash_restock_amount, address) VALUES (%d, '%s', " .
                                                      "'%s', '%s', '%s', '%s');",
                                $created, mysql_escape_string( $currency ),
                                formatBTC( $amount ),
                                formatBTC( $percentRestock ),
                                formatBTC( $percentRestock * $amount ),
                                mysql_escape_string( $address ) ),
                        $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function profitLossTableExists() {

    return self::tableExistsHelper( 'profit_loss' );

  }

  public static function createProfitLossTable() {

    return self::createTableHelper( 'profit_loss' );

  }

  private static function ensureTradesUpdated( $link ) {

    if ( !is_null( self::$trades ) ) {
      return;
    }
    self::$trades = array( );

    if ( !$result = mysql_query( "SELECT raw_trade_ID FROM exchange_trades ", $link ) ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    while ( $row = mysql_fetch_assoc( $result ) ) {
      self::$trades[ $row[ 'raw_trade_ID' ] ] = true;
    }

  }

  public static function getNewTrades( $recentTradeIDs ) {

    $link = self::connect();

    if ( !count( $recentTradeIDs ) ) {
      return array( );
    }

    self::ensureTradesUpdated( $link );

    mysql_close( $link );

    $result = array( );
    foreach ( $recentTradeIDs as $id ) {
      if ( isset( self::$trades[ $id ] ) ) {
        continue;
      }
      // If we don't find an exact match, look for a partial match.
      $add = true;
      foreach ( array_keys( self::$trades ) as $candidate ) {
        if ( strpos( $candidate, $id ) !== false ) {
          $add = false;
          break;
        }
      }
      if ( $add ) {
        $result[] = $id;
      }
    }

    return $result;
  }

}
