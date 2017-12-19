<?php

require_once __DIR__ . '/../lib/mysql.php';
require_once __DIR__ . '/config.inc.php';
require_once __DIR__ . '/../bot/utils.php';
require_once __DIR__ . '/../bot/Config.php';
require_once __DIR__ . '/../bot/Exchange.php';

date_default_timezone_set( "UTC" );

try {
  Config::refresh();
}
catch ( Exception $ex ) {
  return;
}

class WebDB {

  private static function connect() {

    global $dbHost, $dbName, $dbUser, $dbPass;

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

  public static function isAdminUIEnabled() {
    return Config::get( Config::ADMIN_UI, Config::DEFAULT_ADMIN_UI );
  }

  public static function getLog( $last = null ) {
    if ( is_null( $last ) ) {
      $last = time() - 300;
    }

    $link = self::connect();

    $query = sprintf( "SELECT * FROM log WHERE created > %d", $last );
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $results[] = ['message' => $row[ 'message' ], 'time' => $row[ 'created' ] ];
    }

    mysql_close( $link );

    return $results;

  }

  public static function getAlerts() {
    $link = self::connect();

    $query = "SELECT created, message FROM log WHERE ID > " .
               "(SELECT ID FROM log WHERE message = 'stuckDetection()' ORDER BY created DESC LIMIT 1) " .
             "ORDER BY ID ASC";
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {
      if (!preg_match( '/Please investigate and open support ticket if neccessary/', $row[ 'message' ] )) {
        break;
      }
      $results[] = ['message' => $row[ 'message' ], 'time' => $row[ 'created' ] ];
    }

    mysql_close( $link );

    return $results;

  }

  public static function getGraph( $coin, $exchange, $mode ) {

    $link = self::connect();

    $query = null;
    if ( $mode == 0 ) {
      $query = sprintf( "SELECT SUM(balance) AS data, created, ID_exchange FROM snapshot WHERE coin = '%s' %s GROUP BY created, ID_exchange;", //
              mysql_escape_string( $coin ), //
              $exchange === "0" ? "" : sprintf( " AND ID_exchange = %d", mysql_escape_string( $exchange ) )
      );
    }
    else if ( $mode == 1 ) {
      $query = sprintf( "SELECT rate AS data, created, ID_exchange FROM snapshot WHERE coin = '%s' %s;", //
              mysql_escape_string( $coin ), //
              $exchange === "0" ? "" : sprintf( " AND ID_exchange = %d", mysql_escape_string( $exchange ) )
      );
    }
    else if ( $mode == 2 ) {
      $query = sprintf( "SELECT SUM(desired_balance) AS data, created, ID_exchange FROM snapshot WHERE coin = '%s' %s GROUP BY created, ID_exchange;", //
              mysql_escape_string( $coin ), //
              $exchange === "0" ? "" : sprintf( " AND ID_exchange = %d", mysql_escape_string( $exchange ) )
      );
    }
    else {
      return [ ];
    }

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $ma = [ ];

    $data = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $value = floatval( $row[ 'data' ] );
      $exchange = $row[ 'ID_exchange' ];

      if (!in_array( $exchange, $ma )) {
        $ma[$exchange] = [ ];
      }
      $ma[$exchange][] = $value;
      while ( count( $ma[$exchange] ) > 4 ) {
        array_shift( $ma[$exchange] );
      }

      $sma = array_sum( $ma[$exchange] ) / count( $ma[$exchange] );
      $data[] = ['time' => $row[ 'created' ], 'value' => $sma , 'raw' => $value,
                 'exchange' => $exchange ];
    }

    mysql_close( $link );

    if ( $mode == 0 ) {
      // Append an entry for the current balances
      $ids = array_reduce( $data, function( $carry, $value ) {
        if ( !in_array( $value[ 'exchange' ], $carry ) ) {
          return array_merge( $carry, [ $value[ 'exchange' ] ] );
        }
        return $carry;
      }, [] );
      foreach ( $ids as $id ) {
        $ex = Exchange::createFromID( $id );
        $ex->refreshWallets();
        $wallets = $ex->getWalletsConsideringPendingDeposits();
        // Will be 0 if $coin doesn't exist in our wallets!
        $balance = @floatval( $wallets[ $coin ] );

	$ma[$id][] = $balance;
	while ( count( $ma[$id] ) > 4 ) {
	  array_shift( $ma[$id] );
	}

	$sma = array_sum( $ma[$id] ) / count( $ma[$id] );
	$data[] = ['time' => strval( time() ), 'value' => $sma, 'raw' => $balance,
		   'exchange' => $id ];
      }
    }

    if ( $mode != 1 ) {
      return [ '0' => $data, '1' => self::getTotalValue( $exchange, $coin, $mode ) ];
    }

    return [ '0' => $data ];

  }

  public static function getTotalValue( $exchange, $coin, $mode ) {

    $link = self::connect();

    // SELECT SUM(balance) * AVG(rate) AS value, coin, created FROM `snapshot` GROUP BY coin, created, ID_exchange ORDER BY `created` ASC
    $query = sprintf( "SELECT SUM(balance) * AVG(rate) AS value, SUM(balance) AS balance, " .
                      "       coin, created FROM `snapshot` " .
                      "WHERE coin = '%s' GROUP BY coin, created, ID_exchange " .
                      "ORDER BY `created` ASC", mysql_escape_string( $coin ) );

    $result = mysql_query( $query, $link );

    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $temp = [ ];
    $values = [ ];
    $prevCreated = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $value = $mode == '1' ? $row[ 'value' ] : $row[ 'balance' ];
      $created = $row[ 'created' ];

      if ( $prevCreated == 0 ) {
        $prevCreated = $created;
      }

      if ( $created != $prevCreated ) {
        $values[] = ['sum' => array_sum( $temp ), 'time' => $prevCreated ];
        $temp = [ ];

        $prevCreated = $created;
      }

      $temp[] = $value;
    }
    $values[] = ['sum' => array_sum( $temp ), 'time' => $created ];

    mysql_close( $link );

    $ma = [ ];

    $data = [ ];
    foreach ( $values as $value ) {

      if (!in_array( $exchange, $ma )) {
        $ma[$exchange] = [ ];
      }
      $ma[$exchange][] = $value[ 'sum' ];
      while ( count( $ma[$exchange] ) > 4 ) {
        array_shift( $ma[$exchange] );
      }

      $sma = array_sum( $ma[$exchange] ) / count( $ma[$exchange] );
      $data[] = ['time' => $value[ 'time' ], 'value' => $sma, 'raw' => $value[ 'sum' ] ];
    }

    return $data;

  }

  public static function getWalletStats() {

    $link = self::connect();

    $query = 'SELECT * FROM snapshot WHERE created = (SELECT MAX(created) FROM snapshot)';

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $age = 0;

    $extc = [ ];
    $exoc = [ ];

    $exchangeMap = [ ];
    $walletMap = [ ];

    $wallets = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $coin = $row[ 'coin' ];
      $exid = $row[ 'ID_exchange' ];
      if ( !isset( $exchangeMap[ $exid ] ) ) {
        $exchangeMap[ $exid ] = Exchange::createFromID( $exid );
        $exchangeMap[ $exid ]->refreshWallets();
        $walletMap[ $exid ] = $exchangeMap[ $exid ]->getWalletsConsideringPendingDeposits();
      }

      // Will be 0 if $coin doesn't exist in our wallets!
      $balance = @floatval( @$walletMap[ $exid ][ $coin ] );

      if ( key_exists( $exid, $extc ) === false ) {
        $extc[ $exid ] = intval( self::getTradeCount( $exid ) );
        $exoc[ $exid ] = intval( self::getOpportunityCount( $exid ) );
      }

      $age = time() - $row[ 'created' ];
      $wallets[ $coin ][ $exid ][ 'balance' ] = $balance;
      $wallets[ $coin ][ $exid ][ 'balance_diff' ] = floatval( formatBTC( $row[ 'desired_balance' ] - $balance ) );
      $wallets[ $coin ][ $exid ][ 'opportunities' ] = intval( $row[ 'uses' ] );
      $wallets[ $coin ][ $exid ][ 'change' ] = floatval( $balance  - self::getHistoricBalance( $coin, $exid ) );
      $wallets[ $coin ][ $exid ][ 'trades' ] = intval( $row[ 'trades' ] );
    }

    mysql_close( $link );

    ksort( $wallets );

    return ['age' => $age, 'trades' => $extc, 'uses' => $exoc, 'wallets' => $wallets ];

  }

  private static function getHistoricBalance( $coin, $exchangeID ) {

    $link = self::connect();

    $query = sprintf( "SELECT balance FROM snapshot WHERE coin = '%s' AND ID_exchange = %d AND created < (SELECT MAX(created) FROM snapshot) - 86400 ORDER BY created DESC LIMIT 1;", //
            mysql_escape_string( $coin ), //
            $exchangeID
    );

    $data = mysql_query( $query, $link );
    if ( !$data ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $result = 0;
    while ( $row = mysql_fetch_assoc( $data ) ) {
      $result = $row[ 'balance' ] ;
    }

    mysql_close( $link );

    return $result;

  }

  public static function getTrades() {

    $link = self::connect();

    $query = sprintf( "SELECT * FROM trade ORDER BY created DESC LIMIT 20" );
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      array_push( $results, array( //
          'amount' => $row[ 'amount' ] , //
          'coin' => $row[ 'coin' ], //
          'exchange' => $row[ 'ID_exchange_target' ], //
          'time' => $row[ 'created' ] //
      ) );
    }

    mysql_close( $link );

    return $results;

  }

  public static function getManagement() {

    $link = self::connect();

    $query = sprintf( "SELECT * FROM management ORDER BY created DESC LIMIT 20" );
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      array_push( $results, array( //
          'amount' => $row[ 'amount' ] , //
          'coin' => $row[ 'coin' ], //
          'exchange' => $row[ 'ID_exchange' ], //
          'time' => $row[ 'created' ] //
      ) );
    }

    mysql_close( $link );

    return $results;

  }

  public static function getXfer() {

    $link = self::connect();

    $query = sprintf( "SELECT * FROM withdrawal ORDER BY created DESC LIMIT 20" );
    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      array_push( $results, //
              [
          'amount' => $row[ 'amount' ] , //
          'coin' => $row[ 'coin' ], //
          'exchange_source' => $row[ 'ID_exchange_source' ], //
          'exchange_target' => $row[ 'ID_exchange_target' ], //
          'time' => $row[ 'created' ] //
              ]
      );
    }

    mysql_close( $link );

    return $results;

  }

  public static function getTradeCount( $xid ) {

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(*) AS CNT FROM trade %s;", //
            $xid == 0 ? "" : sprintf( "WHERE ID_exchange_source = %d OR ID_exchange_target = %d", $xid, $xid )
    );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $row = mysql_fetch_assoc( $result );
    $tradeCount = $row[ "CNT" ];

    mysql_close( $link );

    return $tradeCount;

  }

  public static function getOpportunityCount( $xid ) {

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(*) AS CNT FROM track %s;", //
            $xid == 0 ? "" : sprintf( "WHERE ID_exchange = %d", $xid )
    );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $row = mysql_fetch_assoc( $result );
    $tradeCount = $row[ "CNT" ];

    mysql_close( $link );

    return $tradeCount;

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

    $results[ 'admin_ui' ] = self::isAdminUIEnabled();

    mysql_close( $link );

    return $results;

  }

  public static function getPL() {

    $link = self::connect();

    $result = mysql_query( "SELECT created, coin, currency, tradeable_sold AS amount, currency_bought, " .
                           "       currency_sold, currency_revenue, currency_pl, currency_tx_fee, " .
                           "       tradeable_tx_fee, ID_exchange_source AS source, " .
                           "       ID_exchange_target AS target " .
                           "FROM profit_loss " .
                           "ORDER BY created DESC", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    $data = array();
    $pl_currency = '';
    $profitables = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $pl_currency = $row[ 'currency' ];
      if ($row[ 'currency_pl' ] > 0) {
        $profitables++;
      }
      $data[] = [
        'time' => $row[ 'created' ],
        'coin' => $row[ 'coin' ],
        'currency' => $row[ 'currency' ],
        'amount_sold' => floatval( $row[ 'amount' ] ),
        'currency_bought' => floatval( $row[ 'currency_bought' ] ),
        'currency_sold' => floatval( $row[ 'currency_sold' ] ),
        'currency_revenue' => floatval( $row[ 'currency_revenue' ] ),
        'currency_pl' => floatval( $row[ 'currency_pl' ] ),
        'currency_tx_fee' => floatval( $row[ 'currency_tx_fee' ] ),
        'tx_fee' => floatval( $row[ 'tradeable_tx_fee' ] ),
        'source_exchange' => $row[ 'source' ],
        'target_exchange' => $row[ 'target' ],
      ];
    }

    $result = mysql_query( "SELECT SUM(currency_pl) AS total_pl " .
                           "FROM profit_loss;", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $row = mysql_fetch_assoc( $result );
    $total_pl = $row[ 'total_pl' ];

    mysql_close( $link );

    $results = [
      'pl' => $total_pl,
      'pl_currency' => $pl_currency,
      'efficiency' => count( $data ) ? 100 * $profitables / count( $data ) : 0,
      'data' => $data,
    ];

    return $results;

  }

  public static function doAdminAction( $post ) {

    $query = '';
    switch ($post[ 'action' ]) {
    case 'set_autobuy_funds':
      $query = sprintf( "UPDATE stats SET value = '%.8f' WHERE keyy = 'autobuy_funds';",
                        $post[ 'value' ] );
      break;
    case 'get_bot_status':
      $query = "SELECT keyy, value FROM stats WHERE keyy IN ('last_run', 'last_paused', 'paused');";
      break;
    case 'pause_bot':
      $query = sprintf( "INSERT INTO stats (keyy, value) VALUES ('paused', '1'), ('last_paused', '%d') ON DUPLICATE KEY UPDATE value = '1';",
               time() );
      break;
    case 'resume_bot':
      $query = "DELETE FROM stats WHERE keyy = 'paused';";
      break;
    case 'get_config_fields':
      return Config::getEditableKeys();
    case 'set_config_fields':
      return Config::setEditableKeys( $post[ 'data' ] ) ? array( ) :
             'Error string which will not parse as valid JSON';
    }

    $link = self::connect();

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();

    switch ($post[ 'action' ]) {
    case 'get_bot_status':
      $paused = false;
      $healthy = true;
      $lastRun = 0;
      $lastPaused = 0;
      while ( $row = mysql_fetch_assoc( $result ) ) {
        if ($row[ "keyy" ] == "paused" &&
            $row[ "value" ] == true) {
          $paused = true;
        } else if ($row[ "keyy" ] == "last_run") {
          $lastRun = $row[ "value" ];
        } else if ($row[ "keyy" ] == "last_paused") {
          $lastPaused = $row[ "value" ];
        }
      }
      $stale = (abs($lastRun - time()) >= 30);
      $recentlyPaused = (abs($lastPaused - time()) >= 30);
      if ($stale && !$paused && !$recentlyPaused) {
        $healthy = false;
      }
      $results[ 'healthy' ] = $healthy;
      $results[ 'status' ] = ($paused || $stale) ? "Paused" : "Running";
      break;
    }

    mysql_close( $link );

    return $results;

  }

}

