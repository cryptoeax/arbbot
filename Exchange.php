<?php
ini_set("precision", 16);

require_once __DIR__ . '/xchange/Poloniex.php';
require_once __DIR__ . '/xchange/Bittrex.php';
require_once __DIR__ . '/Orderbook.php';

//require_once 'Config.php';

abstract class Exchange {

  protected $apiKey;
  protected $apiSecret;
  //
  protected $wallets = [ ];
  protected $transferFees = [ ];
  protected $confirmationTimes = [ ];
  protected $names = [ ];
  protected $pairs = [ ];
  //
  protected $previousNonce = 0;

  function __construct( $apiKey, $apiSecret ) {


    if ( is_null( $apiKey ) || is_null( $apiSecret ) ) {
      throw new Exception( $this->prefix() . 'Invalid API key or secret' );
    }

    $this->apiKey = $apiKey;
    $this->apiSecret = $apiSecret;

  }

  public function getTradeablePairs() {

    return $this->pairs;

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

  public abstract function getTickers( $currency );

  public abstract function withdraw( $coin, $amount, $address );

  public abstract function getDepositAddress( $coin );

  public abstract function buy( $tradeable, $currency, $rate, $amount );

  public abstract function sell( $tradeable, $currency, $rate, $amount );

  public abstract function cancelOrder( $orderID );

  public abstract function getFilledOrderPrice( $type, $tradeable, $currency, $orderID );

  public abstract function cancelAllOrders();

  public abstract function refreshExchangeData();

  public abstract function dumpWallets();

  public abstract function refreshWallets();

  public abstract function detectStuckTransfers();

  public abstract function getSmallestOrderSize();

  public abstract function getID();

  public abstract function getName();

  public abstract function testAccess();

  public abstract function getWalletsConsideringPendingDeposits();

  protected abstract function fetchOrderbook( $tradeable, $currency );

  protected function prefix() {

    return "[" . $this->getName() . "] ";

  }

  protected function nonce() {

    $nonce = 0;
    while ( true ) {
      usleep( 1000 );
      $nonce = floor( microtime( true ) * 1000000);
      if ( $nonce == $this->previousNonce ) {
        usleep( 100 );
        continue;
      }
      break;
    }

    $this->previousNonce = $nonce;
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
      curl_setopt( $pubch, CURLOPT_SSL_VERIFYPEER, FALSE );
      curl_setopt( $pubch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
    }
    curl_setopt( $pubch, CURLOPT_URL, $url );

    $error = null;
    // Retry up to five times
    for ( $i = 0; $i < 5; $i++ ) {

      $data = curl_exec( $pubch );

      if ( $data === false ) {
        $error = $this->prefix() . "Could not get reply: " . curl_error( $pubch );
        logg( $error );
        continue;
      }

      return $data;
    }
    throw new Exception( $error );

  }

}
