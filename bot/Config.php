<?php

class Config {

  //
  // MAIL
  const MAIL_RECIPIENT = 'mail.address';
  //
  // EXPERT
  const BALANCE_FACTOR = 'expert.balance-factor';
  const DEFAULT_BALANCE_FACTOR = 1.05;
  //
  const REQUIRED_OPPORTUNITIES = 'expert.required-opportunities';
  const DEFAULT_REQUIRED_OPPORTUNITIES = 3;
  //
  const MAX_TX_FEE_ALLOWED = 'expert.max-tx-fee-allowed';
  const DEFAULT_MAX_TX_FEE_ALLOWED = 0.00001;
  //
  const NEAR_ZERO_BTC_VALUE = 'expert.near-zero-btc-value';
  const DEFAULT_NEAR_ZERO_BTC_VALUE = 0.02;
  //
  const MAX_MIN_CONFIRMATIONS_ALLOWED = 'expert.max-min-confirmations-allowed';
  const DEFAULT_MAX_MIN_CONFIRMATIONS_ALLOWED = 50;
  //
  const UNDERSELL_PROTECTION = 'expert.undersell-protection';
  const DEFAULT_UNDERSELL_PROTECTION = false;
  //
  const RATE_EMA_PERIOD = 'expert.rate-ema-period';
  const DEFAULT_RATE_EMA_PERIOD = 12;
  //
  const OPPORTUNITY_COUNT_AGE = 'expert.opportunity-count-age';
  const DEFAULT_OPPORTUNITY_COUNT_AGE = 120;
  //
  const OPPORTUNITY_SAVE_INTERVAL = 'expert.opportunity-save-interval';
  const DEFAULT_OPPORTUNITY_SAVE_INTERVAL = 60;
  //
  const MAX_LOG_AGE = 'expert.max-log-age';
  const DEFAULT_MAX_LOG_AGE = 300;
  //
  const QUERY_DELAY = 'expert.query-delay';
  const DEFAULT_QUERY_DELAY = 10;
  //
  const ORDER_CHECK_DELAY = 'expert.order-check-delay';
  const DEFAULT_ORDER_CHECK_DELAY = 60;
  //
  const MAX_PAIRS_PER_RUN = 'expert.max-pairs-per-run';
  const DEFAULT_MAX_PAIRS_PER_RUN = 10;
  //
  const INTERVAL_MANAGEMENT = 'expert.interval-management';
  const DEFAULT_INTERVAL_MANAGEMENT = 1;
  //
  const INTERVAL_TAKE_PROFIT = 'expert.interval-take-profit';
  const DEFAULT_INTERVAL_TAKE_PROFIT = 6;
  //
  const INTERVAL_STUCK_DETECTION = 'expert.interval-stuck-detection';
  const DEFAULT_INTERVAL_STUCK_DETECTION = 12;
  //
  const INTERVAL_UNUSED_COIN_DETECTION = 'expert.interval-unused-coin-detection';
  const DEFAULT_INTERVAL_UNUSED_COIN_DETECTION = 24;
  //
  const INTERVAL_DB_CLEANUP = 'expert.interval-db-cleanup';
  const DEFAULT_INTERVAL_DB_CLEANUP = 2;
  //
  const INTERVAL_CURRENCY_AGGRESSIVE_BALANCE = 'expert.interval-currency-aggressive-balance';
  const DEFAULT_INTERVAL_CURRENCY_AGGRESSIVE_BALANCE = 5;
  //
  const ALLOW_INSECURE_UI = 'expert.allow-insecure-ui';
  const DEFAULT_ALLOW_INSECURE_UI = false;
  //
  const ALLOW_UNAUTHENTICATED_UI = 'expert.allow-unauthenticated-ui';
  const DEFAULT_ALLOW_UNAUTHENTICATED_UI = false;
  //
  //
  // GENERAL
  const CANCEL_STRAY_ORDERS = 'general.cancel-stray-orders';
  const DEFAULT_CANCEL_STRAY_ORDERS = true;
  //
  const MIN_BTC_XFER = 'general.min-btc-xfer';
  const DEFAULT_MIN_BTC_XFER = 0.02;
  //
  const BTC_XFER_SAFETY_FACTOR = 'general.btc-xfer-safety-factor';
  const DEFAULT_BTC_XFER_SAFETY_FACTOR = 1.5;
  //
  const BLOCKED_COINS = 'general.blockedCoins';
  const DEFAULT_BLOCKED_COINS = 'BTS,NXT,XMR,ETH,ETC,BURST,SWIFT,XRP,STEEM,ARDR,SBD,NAUT';
  //
  const ADMIN_UI = 'general.admin-ui';
  const DEFAULT_ADMIN_UI = false;
  //
  // TRADE MODULE
  const MAX_TRADE_SIZE = 'trade.max-trade-size';
  const DEFAULT_MAX_TRADE_SIZE = 0.01;
  //
  const MIN_PROFIT = 'trade.min-profit';
  const DEFAULT_MIN_PROFIT = 0.00000050;
  //
  const BUY_RATE_FACTOR = 'trade.buy-factor';
  const DEFAULT_BUY_RATE_FACTOR = 1.005;
  //
  const SELL_RATE_FACTOR = 'trade.sell-factor';
  const DEFAULT_SELL_RATE_FACTOR = 0.995;
  //
  // TAKEPROFIT MODULE
  const TAKE_PROFIT_ADDRESS = 'takeprofit.profit-address';
  const DEFAULT_TAKE_PROFIT_ADDRESS = null;
  //
  const TAKE_PROFIT_AMOUNT = 'takeprofit.profit-limit';
  const DEFAULT_TAKE_PROFIT_AMOUNT = null;
  //
  const TAKE_PROFIT_MIN_RESTOCK_CASH = 'takeprofit.min-restock-cash';
  const DEFAULT_TAKE_PROFIT_MIN_RESTOCK_CASH = 0.1;
  //
  const TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE = 'takeprofit.restock-cash-percentage';
  const DEFAULT_TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE = 0.33;
  //
  // AUTOBUY
  const MAX_BUY = 'autobuy.max-buy';
  const DEFAULT_MAX_BUY = 0.005;
  //
  // MODULES
  const MODULE_TRADE = 'modules.trade';
  const DEFAULT_MODULE_TRADE = true;
  //
  const MODULE_AUTOBALANCE = 'modules.auto-balance';
  const DEFAULT_MODULE_AUTOBALANCE = true;
  //
  const MODULE_LIQUIDATE = 'modules.coin-liquidation';
  const DEFAULT_MODULE_LIQUIDATE = false;
  //
  const MODULE_AUTOBUY = 'modules.coin-autobuy';
  const DEFAULT_MODULE_AUTOBUY = false;
  //
  const MODULE_TAKE_PROFIT = 'modules.take-profit';
  const DEFAULT_MODULE_TAKE_PROFIT = false;
  //
  const MODULE_STUCK_DETECTION = 'modules.stuck-detection';
  const DEFAULT_MODULE_STUCK_DETECTION = true;
  //
  const MODULE_UNUSED_COINS_DETECTION = 'modules.unused-coins-detection';
  const DEFAULT_MODULE_UNUSED_COINS_DETECTION = true;

  //
  private static $config = [ ];
  //
  private static $defaultNotifications = [ ];
  //
  private static $blockList = [ ];
  //
  private static $tradedCoins = [ ];

  private function __construct() {

  }

  public static function isCurrency( $coin ) {

    return $coin == 'BTC';

  }

  public static function isBlocked( $coin ) {

    $blockedCoins = self::get( self::BLOCKED_COINS );
    if ( !is_null( $blockedCoins ) ) {
      $coins = explode( ',', $blockedCoins );
      foreach ( $coins as $block ) {
        if ( trim( $block ) == $coin ) {
          logg( "Skipping $coin (Blocked from config)" );
          return true;
        }
      }
    }

    if ( key_exists( $coin, self::$blockList ) && (self::$blockList[ $coin ] == 0 || self::$blockList[ $coin ] < time()) ) {
      logg( "Skipping $coin (Blocked during runtime)" );
      return true;
    }
    return false;

  }

  public static function isTraded( $coin ) {

    return key_exists( $coin, self::$tradedCoins );

  }

  public static function blockCoin( $coin, $expiration = 0 ) {

    self::$blockList[ $coin ] = time() + $expiration;

  }

  public static function setTradedCoins( $tradedCoins ) {

    self::$tradedCoins = $tradedCoins;

  }

  public static function exists( $key ) {

    $value = self::get( $key, null );
    return !is_null( $value ) && strlen( $value ) > 0;

  }

  public static function get( $key, $default = null ) {

    $config = self::$config;

    $value = $config;
    $keys = explode( '.', $key );
    foreach ( $keys as $k ) {
      if ( !key_exists( $k, $value ) ) {
        if ( !in_array( $key, self::$defaultNotifications ) ) {
          //logg( "INFO: Using default value for $key: \"" . (is_null( $default ) ? 'null' : $default) . "\"" );
          self::$defaultNotifications[] = $key;
        }
        return $default;
      }
      $value = $value[ $k ];
    }
    return $value;

  }

  public static function refresh() {

    $config = @parse_ini_file( "config.ini", true );
    if ( !$config ) {
      // The web UI accesses the Config object from ../bot, so config.ini will
      // be placed in the parent directory.
      $config = @parse_ini_file( "../config.ini", true );
      if ( !$config ) {
        throw new Exception( "Configuration not found or invalid!" );
      }
    }
    self::$config = $config;

  }

  public static function getEditableKeys() {

    $editableConfigs = [
      self::MIN_BTC_XFER,
      self::BTC_XFER_SAFETY_FACTOR,
      self::CANCEL_STRAY_ORDERS,
      self::BLOCKED_COINS,
      self::MODULE_TRADE,
      self::MODULE_AUTOBUY,
      self::MODULE_AUTOBALANCE,
      self::MODULE_LIQUIDATE,
      self::MODULE_TAKE_PROFIT,
      self::MODULE_STUCK_DETECTION,
      self::MODULE_UNUSED_COINS_DETECTION,
      self::MAX_BUY,
      self::TAKE_PROFIT_ADDRESS,
      self::TAKE_PROFIT_AMOUNT,
      self::TAKE_PROFIT_MIN_RESTOCK_CASH,
      self::TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE,
      self::MAX_TRADE_SIZE,
      self::MIN_PROFIT,
      self::BUY_RATE_FACTOR,
      self::SELL_RATE_FACTOR,
      self::MAX_PAIRS_PER_RUN,
      self::QUERY_DELAY,
      self::ORDER_CHECK_DELAY
    ];

    $results = array( );
    foreach ($editableConfigs as $config) {
      $arr = explode( ".", $config );
      $section = $arr[ 0 ];
      $name = $arr[ 1 ];

      $results[ $section ][] = array(
        'name' => $name,
      );
    }

    $file = file_get_contents( __DIR__ . '/../config.ini' );
    $lines = array( );
    if (strstr( $file, "\r\n" )) {
      $lines = explode( "\r\n", $file );
    } else {
      $lines = explode( "\n", $file );
    }

    $currentSection = '';
    $currentComment = '';
    for ($i = 0; $i < count( $lines ); $i++) {
      $line = $lines[ $i ];
      if (preg_match( '/^\s*\[.*\]/', $line )) {
	$currentSection = '';
        foreach (array_keys( $results ) as $section) {
          if (strstr( $line, "[$section]" )) {
            $currentSection = $section;
            break;
          }
        }
        continue;
      }
      if ($line == '') {
        $currentComment = '';
        continue;
      }
      if (preg_match( '/^\s*;\s*.*$/', $line )) {
        $comment = preg_replace( '/^\s*;\s*(.*)$/', '$1', $line );
        $currentComment .= "$comment ";
        continue;
      }
      if (strchr( $line, '=' ) && $currentSection != '') {
        $arr = array_map( 'trim', explode( "=", $line ) );
        foreach ($results[ $currentSection ] as $key => $var) {
          if ($var[ 'name' ] == $arr[ 0 ]) {
            $results[ $currentSection ][ $key ][ 'value' ] = $arr[ 1 ];
            $results[ $currentSection ][ $key ][ 'description' ] = $currentComment;
            break;
          }
        }
        continue;
      }
    }

    return $results;

  }

  public static function setEditableKeys($input) {

    $editableConfigs = [
      self::MIN_BTC_XFER,
      self::BTC_XFER_SAFETY_FACTOR,
      self::CANCEL_STRAY_ORDERS,
      self::BLOCKED_COINS,
      self::MODULE_TRADE,
      self::MODULE_AUTOBUY,
      self::MODULE_AUTOBALANCE,
      self::MODULE_LIQUIDATE,
      self::MODULE_TAKE_PROFIT,
      self::MODULE_STUCK_DETECTION,
      self::MODULE_UNUSED_COINS_DETECTION,
      self::MAX_BUY,
      self::TAKE_PROFIT_ADDRESS,
      self::TAKE_PROFIT_AMOUNT,
      self::TAKE_PROFIT_MIN_RESTOCK_CASH,
      self::TAKE_PROFIT_RESTOCK_CASH_PERCENTAGE,
      self::MAX_TRADE_SIZE,
      self::MIN_PROFIT,
      self::BUY_RATE_FACTOR,
      self::SELL_RATE_FACTOR,
      self::MAX_PAIRS_PER_RUN,
      self::QUERY_DELAY,
      self::ORDER_CHECK_DELAY
    ];

    $results = array( );
    foreach ($editableConfigs as $config) {
      $arr = explode( ".", $config );
      $section = $arr[ 0 ];
      $name = $arr[ 1 ];

      $results[ $section ][] = array(
        'name' => $name,
      );
    }

    $inputs = array( );
    foreach ($input as $item) {
      $inputs[ $item[ 'name' ] ] = $item[ 'value' ];
    }

    $file = file_get_contents( __DIR__ . '/../config.ini' );
    file_put_contents( __DIR__ . '/../config.ini.' . time(), $file );

    $lines = array( );
    $output = '';
    if (strstr( $file, "\r\n" )) {
      $lines = explode( "\r\n", $file );
      $sep = "\r\n";
    } else {
      $lines = explode( "\n", $file );
      $sep = "\n";
    }

    $currentSection = '';
    $currentComment = '';
    for ($i = 0; $i < count( $lines ); $i++) {
      $line = $lines[ $i ];
      if (preg_match( '/^\s*\[.*\]/', $line )) {
        $output .= $line . $sep;
	$currentSection = '';
        foreach (array_keys( $results ) as $section) {
          if (strstr( $line, "[$section]" )) {
            $currentSection = $section;
            break;
          }
        }
        continue;
      }
      if ($line == '') {
        $currentComment = '';
	if ($i != count( $lines ) - 1) {
          $output .= $sep;
        }
        continue;
      }
      if (preg_match( '/^\s*;\s*.*$/', $line )) {
        $comment = preg_replace( '/^\s*;\s*(.*)$/', '$1', $line );
        $currentComment .= "$comment ";
        $output .= $line . $sep;
        continue;
      }
      if (strchr( $line, '=' ) && $currentSection != '') {
        $arr = array_map( 'trim', explode( "=", $line ) );
        $name = $arr[ 0 ];
	$value = $arr[ 1 ];
        $inputName = "$currentSection.${arr[0]}";
        if (in_array( $inputName, array_keys( $inputs ) ) ) {
          $value = self::quoteValue( $inputs[ $inputName ] );
	  $output .= "$name = $value$sep";
          continue;
        }
      }
      if ($i == count( $lines ) - 1) {
        $output .= $line;
      } else {
        $output .= "$line$sep";
      }
    }

    $bytes = file_put_contents( __DIR__ . '/../config.ini', $output );

    return $bytes !== false;

  }

  private static function quoteValue( $str ) {

    if (preg_match( '/\s/', $str )) {
      return "\"$str\"";
    }
    return $str;

  }

}
