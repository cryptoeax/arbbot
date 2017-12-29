<?php
ini_set("precision", 16);

require_once __DIR__ . '/Orderbook.php';
require_once __DIR__ . '/utils.php';

abstract class Exchange {

  protected $apiKey;
  protected $apiSecret;
  //
  protected $wallets = [ ];
  protected $transferFees = [ ];
  protected $confirmationTimes = [ ];
  protected $names = [ ];
  protected $pairs = [ ];
  protected $tradeablePairs = [ ];
  protected $tradeables = [ ];

  function __construct( $apiKey, $apiSecret ) {


    if ( is_null( $apiKey ) || is_null( $apiSecret ) ) {
      throw new Exception( $this->prefix() . 'Invalid API key or secret' );
    }

    $this->apiKey = $apiKey;
    $this->apiSecret = $apiSecret;

  }

  public static function createFromID( $id ) {

    $path = __DIR__ . '/xchange/map.' . $id;
    if ( !is_readable( $path ) ) {
      logg( "WARNING: Invalid ID passed to Exchange::createFromID: ${id}" );
      throw new Exception( "Invalid id: '${id}'" );
    }
    $name = file_get_contents( $path );
    require_once __DIR__ . "/xchange/${name}.php";
    return new $name();

  }

  protected function calculateTradeablePairs() {

    $maxTxFee = Config::get( Config::MAX_TX_FEE_ALLOWED, Config::DEFAULT_MAX_TX_FEE_ALLOWED );
    $maxConfTime = Config::get( Config::MAX_MIN_CONFIRMATIONS_ALLOWED, Config::DEFAULT_MAX_MIN_CONFIRMATIONS_ALLOWED );

    // Never consider pairs that have a confirmation time that exceeds
    // max-min-confirmations-allowed for trading.
    $pairs = array( );
    $tradeables = array( );
    foreach ( $this->pairs as $pair ) {
      $arr = explode( '_', $pair );
      $tradeable = $arr[ 0 ];
      $currency = $arr[ 1 ];
      $averageRate = Database::getAverageRate( $tradeable );

      // If the tradeable is too expensive to transfer, let it go.
      if ( isset( $this->transferFees[ $tradeable ] ) &&
           !endsWith( $this->transferFees[ $tradeable ], '%' ) &&
           $this->transferFees[ $tradeable ] * $averageRate >= $maxTxFee ) {
        continue;
      }

      // If a transaction of the tradeable takes too long to confirm on its blockchain, let it go.
      if ( isset( $this->confirmationTimes[ $tradeable ] ) &&
           $this->confirmationTimes[ $tradeable ] >= $maxConfTime ) {
        continue;
      }

      $pairs[] = $pair;
      $tradeables[] = $tradeable;
    }

    $this->tradeablePairs = $pairs;
    $this->tradeables[] = array(
      'CoinType' => 'BITCOIN',
      'Currency' => $tradeable,
    );

  }

  public function getTradeablePairs() {

    return $this->tradeablePairs;

  }

  public function getTradeables() {

    return $this->tradeables;

  }

  public function getWallets() {

    return $this->wallets;

  }

  public function addFeeToPrice( $price ) {
    return $price;

  }

  public function deductFeeFromAmountBuy( $amount ) {
    return $amount;

  }

  public function deductFeeFromAmountSell( $amount ) {
    return $amount;

  }

  public function getCoinName( $coin ) {

    if ( !key_exists( $coin, $this->names ) ) {
      logg( $this->prefix() . "WARNING: Unknown coin name for $coin. There is a minimal risk that two different coins with the same abbreviation exist. This cannot be automatically checked for $coin." );
      return null;
    }
    return $this->names[ $coin ];

  }

  public function getTransferFee( $tradeable, $amount ) {

    if ( !key_exists( $tradeable, $this->transferFees ) ) {
      //logg( $this->prefix() . "WARNING: Unknown transfer fee for $tradeable. Calculations may be inaccurate!" );
      return null;
    }

    $fee = $this->transferFees[ $tradeable ];

    if ( endsWith( $fee, '%' ) ) {
      return $amount * substr( $fee, 0, -1 );
    }
    return $fee;

  }

  public function getConfirmationTime( $tradeable ) {

    if ( !key_exists( $tradeable, $this->confirmationTimes ) ) {
      logg( $this->prefix(). "WARNING: Unknown confirmation time for $tradeable. Calculations may be inaccurate!" );
      return null;
    }

    return $this->confirmationTimes[ $tradeable ];

  }

  public function getOrderbook( $tradeable, $currency ) {
    $orderbook = $this->fetchOrderbook( $tradeable, $currency );
    if ( is_null( $orderbook ) ) {
      return null;
    }
    if ( $orderbook->getBestAsk()->getPrice() == $orderbook->getBestBid()->getPrice() ) {
      logg( $this->prefix() . "Orderbook is drunk!" );
      return null;
    }
    return $orderbook;

  }

  public function getRecentOrderTrades( &$arbitrator, $coin, $currency, $type, $orderID, $tradeAmount ) {

    $trades = array( );
    $tradeMatcher = $arbitrator->getTradeMatcher();
    for ( $i = 0; $i < 20; ++ $i ) {
      $trades = $tradeMatcher->getExchangeNewTrades( $this->getID() );
      $trades = array_filter( $trades, function( $trade ) use ( $coin, $type ) {
        if ( $trade[ 'tradeable' ] != $coin ) {
          logg( sprintf( "WARNING: Got an unrelated trade while trying to perfrom post-trade tasks: %s of %.8f %s at %.8f, saved but will ignore",
                         $type, formatBTC( $trade[ 'amount' ] ),
                         $trade[ 'tradeable' ], $trade[ 'rate' ] ) );
          return false;
        }
        return true;
      } );
      $matched = $tradeMatcher->matchTradesConsideringPendingTransfers( $trades, $coin, $this, $tradeAmount );
      if ( $matched ) {
        break;
      }
      if ( $i == 19 ) {
        logg( "WARNING: waited for a while without finding all expected trades, so taking what we have for now..." );
      } else {
        logg( "WARNING: not reciving all $type trades from the exchange in time, waiting a bit and retrying..." );
        usleep( 500000 );
      }
    }

    if ( !count( $trades ) ) {
      logg( "WARNING: how have we not found any matches so far?" );
    }

    return $trades;
  }

  public abstract function getTickers( $currency );

  public abstract function withdraw( $coin, $amount, $address );

  public abstract function getDepositAddress( $coin );

  public abstract function buy( $tradeable, $currency, $rate, $amount );

  public abstract function sell( $tradeable, $currency, $rate, $amount );

  public abstract function cancelOrder( $orderID );

  public abstract function getFilledOrderPrice( $type, $tradeable, $currency, $orderID );

  public abstract function queryTradeHistory( $options = array( ), $recentOnly = false );

  public abstract function queryRecentDeposits( $currency = null );

  public abstract function queryRecentWithdrawals( $currency = null );

  public abstract function cancelAllOrders();

  public abstract function refreshExchangeData();

  public abstract function dumpWallets();

  public abstract function refreshWallets();

  public abstract function detectStuckTransfers();

  public abstract function getSmallestOrderSize();

  public abstract function getID();

  public abstract function getName();

  public abstract function getTradeHistoryCSVName();

  public abstract function testAccess();

  public abstract function getWalletsConsideringPendingDeposits();

  protected abstract function fetchOrderbook( $tradeable, $currency );

  protected function prefix() {

    return "[" . $this->getName() . "] ";

  }

  protected function nonce() {

    static $previousNonce = array( );
    $id = $this->getID();
    if ( !isset( $previousNonce[ $id ] ) ) {
      $previousNonce[ $id ] = 0;
    }

    // Try the current time, if we're getting called too fast, step up one by one.
    $nonce = floor( microtime( true ) * 1000000);
    if ( $nonce <= $previousNonce[ $id ] ) {
      $nonce = $previousNonce[ $id ] + 1;
    }

    $previousNonce[ $id ] = $nonce;
    return $nonce;

  }

  protected function queryPublicJSON( $url ) {

    // our curl handle (initialize if required)
    static $pubch = null;
    if ( is_null( $pubch ) ) {
      $pubch = curl_init();
      curl_setopt( $pubch, CURLOPT_CONNECTTIMEOUT, 15 );
      curl_setopt( $pubch, CURLOPT_TIMEOUT, 60 );
      curl_setopt( $pubch, CURLOPT_RETURNTRANSFER, TRUE );
      curl_setopt( $pubch, CURLOPT_SSL_VERIFYPEER, TRUE );
      curl_setopt( $pubch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
    }
    curl_setopt( $pubch, CURLOPT_URL, $url );

    $error = null;
    // Retry up to five times
    for ( $i = 0; $i < 5; $i++ ) {

      $data = curl_exec( $pubch );
      $code = curl_getinfo( $pubch, CURLINFO_HTTP_CODE );

      if ( $data === false || $code != 200 ) {
        $error = $this->prefix() . "Could not get reply (HTTP ${code}): " . curl_error( $pubch );
        logg( $error );
        continue;
      }

      return $data;
    }
    throw new Exception( $error );

  }

}
