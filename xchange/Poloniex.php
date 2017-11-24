<?php
ini_set("precision", 16);

require_once __DIR__ . '/../Config.php';

class Poloniex extends Exchange {

  const ID = 1;
//
  const PUBLIC_URL = 'https://poloniex.com/public?command=';

//
  private $depositAddresses;

  function __construct() {
    parent::__construct( Config::get( "poloniex.key" ), Config::get( "poloniex.secret" ) );

  }

  public function addFeeToPrice( $price ) {

    return parent::addFeeToPrice( $price );

  }

  public function deductFeeFromAmountBuy( $amount ) {

    return $amount * 0.998;

  }

  public function deductFeeFromAmountSell( $amount ) {

    return $amount * 0.998;

  }

  public function getTickers( $currency ) {

    $ticker = [ ];

    $markets = $this->queryTicker();

    foreach ( $markets as $market => $data ) {

      $split = explode( "_", $market );
      if ( $split[ 0 ] != $currency ) {
        continue;
      }

      $ticker[ $split[ 1 ] ] = $data[ 'last' ];
    }

    return $ticker;

  }

  public function withdraw( $coin, $amount, $address ) {

    try {
      $this->queryWithdraw( $coin, $amount, $address );
      return true;
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'frozen' ) !== false ) {
        logg( $this->prefix() . "Withdrawals for $amount are frozen, retrying later..." );
        return false;
      }
      throw $ex;
    }

  }

  public function getDepositAddress( $coin ) {
    if ( !key_exists( $coin, $this->depositAddresses ) ) {
      return null;
    }

    $address = $this->depositAddresses[ $coin ];
    if ( strpos( strtolower( $address ), 'press' ) !== false ) {
      logg( $this->prefix() . "Please generate a deposit address for $coin!", true );
      return null;
    }

    return $address;

  }

  public function buy( $tradeable, $currency, $rate, $amount ) {
    return $this->queryOrder( $tradeable, $currency, 'buy', $rate, $amount );

  }

  public function sell( $tradeable, $currency, $rate, $amount ) {
    return $this->queryOrder( $tradeable, $currency, 'sell', $rate, $amount );

  }

  protected function fetchOrderbook( $tradeable, $currency ) {

    $orderbook = $this->queryOrderbook( $tradeable, $currency );
    if ( count( $orderbook ) == 0 ) {
      return null;
    }

    if ( !key_exists( 'asks', $orderbook ) ) {
      return null;
    }
    $asks = $orderbook[ 'asks' ];
    if ( count( $asks ) == 0 ) {
      return null;
    }
    $bestAsk = $asks[ 0 ];

    if ( !key_exists( 'bids', $orderbook ) ) {
      return null;
    }
    $bids = $orderbook[ "bids" ];
    if ( count( $bids ) == 0 ) {
      return null;
    }
    $bestBid = $bids[ 0 ];

    if ( $orderbook[ 'isFrozen' ] != 0 ) {
      return null;
    }

    return new Orderbook( //
            $this, $tradeable, //
            $currency, //
            new OrderbookEntry( $bestAsk[ 1 ], $bestAsk[ 0 ] ), //
            new OrderbookEntry( $bestBid[ 1 ], $bestBid[ 0 ] ) //
    );

  }

  public function cancelOrder( $orderID ) {

    logg( $this->prefix() . "Cancelling order $orderID" );

    $split = explode( ':', $orderID );
    $pair = $split[ 0 ];
    $id = $split[ 1 ];

    try {
      $this->queryCancel( $pair, $id );
      return true;
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'PLACEHODLER' ) ) {
        return false;
      }
    }

  }

  public function cancelAllOrders() {

    $overview = $this->queryOpenOrders();
    foreach ( $overview as $pair => $orders ) {
      if ( count( $orders ) == 0 ) {
        continue;
      }
      foreach ( $orders as $order ) {
        $orderID = $order[ 'orderNumber' ];
        $this->cancelOrder( $pair . ":" . $orderID );
      }
    }

  }


  public function refreshExchangeData() {

    $pairs = [ ];
    $markets = $this->queryTicker();

    $currencies = $this->queryCurrencies();

    foreach ( $markets as $market => $data ) {

      $split = explode( "_", $market );
      $tradeable = $split[ 1 ];
      $currency = $split[ 0 ];

      if ( !Config::isCurrency( $currency ) ) {
        continue;
      }

      if ( !key_exists( $tradeable, $currencies ) || $currencies[ $tradeable ][ 'disabled' ] == 1 ) {
        continue;
      }

      $pair = strtoupper( $tradeable . "_" . $currency );
      $pairs[] = $pair;
    }

    $fees = [ ];
    foreach ( $currencies as $coin => $data ) {
      $fees[ $coin ] = $data[ 'txFee' ];
    }

    $depositAddresses = $this->queryDepositAddresses();

    $this->pairs = $pairs;
    $this->transferFees = $fees;
    $this->depositAddresses = $depositAddresses;

  }

  private $lastStuckReportTime = [ ];

  public function detectStuckTransfers() {

    $history = $this->queryDepositsAndWithdrawals();
    foreach ( $history as $key => $block ) {
      foreach ( $block as $entry ) {
        $timestamp = $entry[ 'timestamp' ];
        if ( key_exists( $key, $this->lastStuckReportTime ) && $timestamp < $this->lastStuckReportTime[ $key ] ) {
          continue;
        }
        $status = strtoupper( $entry[ 'status' ] );

        if ( $timestamp < time() - 12 * 3600 && (substr( $status, 0, 8 ) != 'COMPLETE' || strpos( $status, 'ERROR' ) !== false) ) {
          logg( $this->prefix() . "Stuck $key! Please investigate and open support ticket if neccessary!\n\n" . print_r( $entry, true ), true );
          $this->lastStuckReportTime[ $key ] = $timestamp;
        }
      }
    }

  }

  public function dumpWallets() {

    logg( $this->prefix() . print_r( $this->queryBalances(), true ) );

  }

  public function refreshWallets() {

    $wallets = [ ];

    foreach ( $this->queryBalances() as $coin => $balance ) {
      $wallets[ strtoupper( $coin ) ] = $balance;
    }

    $this->wallets = $wallets;

  }

  public function testAccess() {

    $this->queryBalances();

  }

  public function getSmallestOrderSize() {

    return '0.00010000';

  }

  public function getID() {

    return Poloniex::ID;

  }

  public function getName() {

    return "POLONIEX";

  }

// Internal functions for querying the exchange

  private function queryDepositsAndWithdrawals() {
    return $this->queryAPI( 'returnDepositsWithdrawals', //
                    [
                'start' => time() - 14 * 24 * 3600,
                'end' => time() + 3600
                    ]
    );

  }

  public function queryDepositAddresses() {
    return $this->queryAPI( 'returnDepositAddresses' );

  }

  private function queryWithdraw( $coin, $amount, $address ) {
    return $this->queryAPI( 'withdraw', //
                    [
                'currency' => strtoupper( $coin ),
                'amount' => formatBTC( $amount ),
                'address' => $address
                    ]
    );

  }

  private function queryOrder( $tradeable, $currency, $orderType, $rate, $amount ) {

    $result = $this->queryAPI( strtolower( $orderType ), //
            [
        'currencyPair' => $currency . "_" . $tradeable,
        'rate' => formatBTC( $rate ),
        'amount' => formatBTC( $amount )
            ]
    );
    return $currency . '_' . $tradeable . ':' . $result[ 'orderNumber' ];

  }

  private function queryCurrencies() {
    return json_decode( $this->queryPublicJSON( Poloniex::PUBLIC_URL . 'returnCurrencies' ), true );

  }

  private function queryTicker() {
    return json_decode( $this->queryPublicJSON( Poloniex::PUBLIC_URL . 'returnTicker' ), true );

  }

  private function queryOrderbook( $tradeable, $currency ) {
    return json_decode( $this->queryPublicJSON( Poloniex::PUBLIC_URL . 'returnOrderBook&depth=1&currencyPair=' . $currency . '_' . $tradeable ), true );

  }

  private function queryCancel( $pair, $order_number ) {
    return $this->queryAPI( 'cancelOrder', ['currencyPair' => $pair, 'orderNumber' => $order_number ] );

  }

  private function queryOpenOrders() {
    return $this->queryAPI( 'returnOpenOrders', ['currencyPair' => 'all' ] );

  }

  private function queryBalances() {
    return $this->queryAPI( 'returnBalances' );

  }

  private function queryAPI( $command, array $req = [ ] ) {

    $key = $this->apiKey;
    $secret = $this->apiSecret;
    //$mt = explode(' ', microtime());
    $nonce = $this->nonce();


    $req[ 'command' ] = $command;
    //$req[ 'nonce' ] = $mt[1].substr($mt[0], 2, 6);
    $req['nonce'] = $nonce;
    // generate the POST data string
    $post_data = http_build_query( $req, '', '&' );
    $sign = hash_hmac( 'sha512', $post_data, $secret );

    // generate the extra headers
    $headers = array(
        'Key: ' . $key,
        'Sign: ' . $sign,
    );

    // curl handle (initialize if required)
    static $ch = null;
    if ( is_null( $ch ) ) {
      $ch = curl_init();
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
      curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
      curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Poloniex PHP bot; ' . php_uname( 'a' ) . '; PHP/' . phpversion() . ')' );
      curl_setopt( $ch, CURLOPT_URL, 'https://poloniex.com/tradingApi' );
    }
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );

    // run the query
    $error = null;
    for ( $i = 0; $i < 5; $i++ ) {
      $res = curl_exec( $ch );
      if ( $res === false ) {
        $error = $this->prefix() . "Could not get reply: " . curl_error( $ch );
        logg( $error );
        continue;
      }

      $data = json_decode( $res, true );
      if ( !$data ) {
        $error = $this->prefix() . "Invalid data received: (" . $res . ")";
        logg( $error );
        continue;
      }

      if ( key_exists( 'error', $data ) ) {
        throw new Exception( $this->prefix() . "API error response: " . $data[ 'error' ] );
      }

      return $data;
    }
    throw new Exception( $error );

  }

}
