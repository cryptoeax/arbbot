<?php

require_once __DIR__ . '/Exchange.php';

// This class implements the common functionality among exchanges that share a
// similar API to Bittrex.
abstract class BittrexLikeExchange extends Exchange {

  private $marketOptions = [ ];
  private $orderIDField = '';
  private $orderIDParam = '';
  private $orderbookBothPhrase = '';

  protected abstract function getPublicURL();
  protected abstract function getPrivateURL();

  function __construct( $apiKey, $apiSecret, $marketOptions, $orderIDField, $orderIDParam, $orderbookBothPhrase ) {
    parent::__construct( $apiKey, $apiSecret );
    $this->marketOptions = $marketOptions;
    $this->orderIDField = $orderIDField;
    $this->orderIDParam = $orderIDParam;
    $this->orderbookBothPhrase = $orderbookBothPhrase;
  }

  public function getTickers( $currency ) {

    $ticker = [ ];

    $markets = $this->queryMarketSummary();

    foreach ( $markets as $market ) {

      $name = $this->parseMarketName( $market[ 'MarketName' ] );
      if ( $name[ 'currency' ] != $currency ) {
        continue;
      }

      $ticker[ $name[ 'tradeable' ] ] = $market[ 'Last' ];
    }

    return $ticker;

  }

  public function withdraw( $coin, $amount, $address ) {

    try {
      $this->queryWithdraw( $coin, $amount, $address );
      return true;
    }
    catch ( Exception $ex ) {
      echo( $this->prefix() . "Withdrawal error: " . $ex->getMessage() );
      return false;
    }

  }

  public function getDepositAddress( $coin ) {

    return $this->queryDepositAddress( $coin );

  }

  public function buy( $tradeable, $currency, $rate, $amount ) {

    try {
      return $this->queryOrder( $tradeable, $currency, 'buy', $rate, $amount );
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'MARKET_OFFLINE' ) !== false ) {
        $this->onMarketOffline( $tradeable );
      }
      logg( $this->prefix() . "Got an exception in buy(): " . $ex->getMessage() );
      return null;
    }

  }

  public function sell( $tradeable, $currency, $rate, $amount ) {

    try {
      return $this->queryOrder( $tradeable, $currency, 'sell', $rate, $amount );
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'MARKET_OFFLINE' ) !== false ) {
        $this->onMarketOffline( $tradeable );
      }
      logg( $this->prefix() . "Got an exception in sell(): " . $ex->getMessage() );
      return null;
    }

  }

  protected function fetchOrderbook( $tradeable, $currency ) {

    $orderbook = $this->queryOrderbook( $tradeable, $currency );
    if ( count( $orderbook ) == 0 ) {
      return null;
    }

    $ask = $orderbook[ 'sell' ];
    if ( count( $ask ) == 0 ) {
      return null;
    }

    $bestAsk = $ask[ 0 ];

    $bid = $orderbook[ 'buy' ];
    if ( count( $bid ) == 0 ) {
      return null;
    }

    $bestBid = $bid[ 0 ];


    return new Orderbook( //
            $this, $tradeable, //
            $currency, //
            new OrderbookEntry( $bestAsk[ 'Quantity' ], $bestAsk[ 'Rate' ] ), //
            new OrderbookEntry( $bestBid[ 'Quantity' ], $bestBid[ 'Rate' ] ) //
    );

  }

  public function cancelAllOrders() {

    $orders = $this->queryOpenOrders();
    foreach ( $orders as $order ) {
      $id = $order[ $this->orderIDField ];
      $this->cancelOrder( $id );
    }

  }

  public function refreshExchangeData() {

    $pairs = [ ];
    $markets = $this->queryMarkets();
    $currencies = $this->queryCurrencies();

    // This is a list of tradeables that have a market. Used to filter the
    // tx-fee list, which is later used to seed the wallets
    $tradeables = [ ];
    foreach ( $markets as $market ) {

      $tradeable = $market[ 'MarketCurrency' ];
      $currency = $market[ 'BaseCurrency' ];

      if ( !Config::isCurrency( $currency ) ||
           Config::isBlocked( $tradeable ) ) {
        continue;
      }

      // Bleutrade also uses MaintenanceMode.
      if ( !$market[ 'IsActive' ] || @$market[ 'MaintenanceMode' ] ) {
        continue;
      }

      $tradeables[] = $tradeable;
      $pairs[] = $tradeable . '_' . $currency;
    }

    $names = [ ];
    $txFees = [ ];
    $conf = [ ];

    foreach ( $currencies as $data ) {

      $coin = strtoupper( $data[ 'Currency' ] );
      $type = strtoupper( $data[ 'CoinType' ] );

      if ( $coin == 'BTC' ||
           array_search( $coin, $tradeables ) !== false ) {
        $names[ $coin ] = strtoupper( $data[ 'CurrencyLong' ] );
        $txFees[ $coin ] = $data[ 'TxFee' ] . ($type == 'BITCOIN_PERCENTAGE_FEE' ? '%' : '');
        $conf[ $coin ] = $data[ 'MinConfirmation' ];
      }
    }

    $this->pairs = $pairs;
    $this->names = $names;
    $this->transferFees = $txFees;
    $this->confirmationTimes = $conf;

    $this->calculateTradeablePairs();

  }

  private function onMarketOffline( $tradeable ) {
    $keys = array( );
    foreach ( $this->pairs as $pair ) {
      if ( startsWith( $pair, $tradeable . '_' ) ) {
        $keys[] = $pair;
      }
    }
    foreach ( $keys as $key ) {
      unset( $this->pairs[ $key ] );
    }

    unset( $this->names[ $tradeable ] );
    unset( $this->transferFees[ $tradeable ] );
    unset( $this->confirmationTimes[ $tradeable ] );
  }

  public function detectStuckTransfers() {

    // TODO: Detect stuck transfers!

  }

  public function getWalletsConsideringPendingDeposits() {

    $result = [ ];
    foreach ( $this->wallets as $coin => $balance ) {
      $result[ $coin ] = $balance;
    }

    $balances = $this->queryBalances();
    foreach ( $balances as $balance ) {
      $result[ strtoupper( $balance[ 'Currency' ] ) ] = $balance[ 'Balance' ] + $balance[ 'Pending' ];
    }

    return $result;

  }

  public function dumpWallets() {

    logg( $this->prefix() . print_r( $this->queryBalances(), true ) );

  }

  public function refreshWallets() {

    $wallets = [ ];

    // Create artifical wallet balances for all traded coins:
    $currencies = $this->getTradeables();
    if (!count( $currencies )) {
      // If this is the first call to refreshWallets(), $this->transferFees isn't
      // initialized yet.
      $currencies = $this->queryCurrencies();
    }
    foreach ( $currencies as $currency ) {
      if ( $currency[ 'CoinType' ] != 'BITCOIN' ) {
        // Ignore non-BTC assets for now.
        continue;
      }
      $wallets[ $currency[ 'Currency' ] ] = 0;
    }
    $wallets[ 'BTC' ] = 0;

    $balances = $this->queryBalances();
    foreach ( $balances as $balance ) {
      $wallets[ strtoupper( $balance[ 'Currency' ] ) ] = floatval( $balance[ 'Balance' ] );
    }

    $this->wallets = $wallets;

  }

  public function testAccess() {

    $this->queryBalances();

  }

  // Internal functions for querying the exchange

  private function queryDepositAddress( $coin ) {

    for ( $i = 0; $i < 100; $i++ ) {
      try {
        $data = $this->queryAPI( 'account/getdepositaddress', ['currency' => $coin ] );
        return $data[ 'Address' ];
      }
      catch ( Exception $ex ) {
        $info = json_decode($ex->getTrace()[ 0 ][ 'args' ][ 0 ]);
        if (is_object( $info ) &&
            $info->success === false &&
            $info->message === 'ADDRESS_GENERATING') {
          // Wait while the address is being generated.
          sleep( 30 );
          continue;
        }
        throw $ex;
      }
    }

  }

  private function queryOrderbook( $tradeable, $currency ) {
    return $this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'getorderbook?depth=1&type=' . $this->orderbookBothPhrase . '&market=' . $this->makeMarketName( $currency, $tradeable ) ) );

  }

  private function queryMarkets() {
    return $this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'getmarkets' ) );

  }

  private function queryCurrencies() {
    return $this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'getcurrencies' ) );

  }

  private function queryMarketSummary() {
    return $this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'getmarketsummaries' ) );

  }

  protected function queryCancelOrder( $id ) {
    return $this->queryAPI( 'market/cancel', //
                    [
                $this->orderIDParam => $id
                    ]
    );

  }

  private function queryOpenOrders() {
    return $this->queryAPI( 'market/getopenorders' );

  }

  protected function makeMarketName( $currency, $tradeable ) {

    $arr = [ $currency, $tradeable ];
    return $arr[ $this->marketOptions[ 'offsetCurrency' ] ] . $this->marketOptions[ 'separator' ] . $arr[ $this->marketOptions[ 'offsetTradeable' ] ];

  }

  protected function parseMarketName( $name ) {

    $split = explode( $this->marketOptions[ 'separator' ], $name );
    return array(
      'tradeable' => $split[ $this->marketOptions[ 'offsetTradeable' ] ],
      'currency' => $split[ $this->marketOptions[ 'offsetCurrency' ] ],
    );

  }

  private function queryOrder( $tradeable, $currency, $orderType, $rate, $amount ) {

    $result = $this->queryAPI( 'market/' . strtolower( $orderType ) . 'limit', //
            [
        'market' => $this->makeMarketName( $currency, $tradeable ),
        'quantity' => formatBTC( $amount ),
        'rate' => formatBTC( $rate )
            ]
    );

    return $result[ $this->orderIDParam ];

  }

  private function queryBalances() {
    return $this->queryAPI( 'account/getbalances' );

  }

  private function queryWithdraw( $coin, $amount, $address ) {
    return $this->queryAPI( 'account/withdraw', //
                    [
                'currency' => $coin,
                'quantity' => formatBTC( $amount ),
                'address' => $address
                    ]
    );

  }

  protected function xtractResponse( $response ) {

    $data = json_decode( $response, true );

    if ( !$data ) {
      throw new Exception( "Invalid data received: (" . $response . ")" );
    }

    if ( !key_exists( 'result', $data ) || !key_exists( 'success', $data ) || $data[ 'success' ] != true ) {

      if ( key_exists( 'message', $data ) ) {
        throw new Exception( "API error response: " . $data[ 'message' ] );
      }

      throw new Exception( "API error: " . print_r( $data, true ) );
    }

    return $data[ 'result' ];

  }

  protected function queryAPI( $method, $req = [ ] ) {

    $key = $this->apiKey;
    $secret = $this->apiSecret;
    $nonce = $this->nonce();

    $req[ 'apikey' ] = $key;
    $req[ 'nonce' ] = sprintf( "%ld", $nonce );

    $uri = $this->getPrivateURL() . $method . '?' . http_build_query( $req );
    $sign = hash_hmac( 'sha512', $uri, $secret );

    static $ch = null;
    if ( is_null( $ch ) ) {
      $ch = curl_init();
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
      curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
      curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
    }
    curl_setopt( $ch, CURLOPT_URL, $uri );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, ["apisign: $sign" ] );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );

    $error = null;
    for ( $i = 0; $i < 5; $i++ ) {
      try {
        $data = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ($code != 200) {
          throw new Exception( "HTTP ${code} received from server" );
        }
        //

	if ( $data === false ) {
	  $error = $this->prefix() . "Could not get reply: " . curl_error( $ch );
	  logg( $error );
	  continue;
	}

	return $this->xtractResponse( $data );
      }
      catch ( Exception $ex ) {
        $error = $ex->getMessage();
        logg( $this->prefix() . $error );

        if ( strpos( $error, 'ORDER_NOT_OPEN' ) !== false ) {
          // Real error, don't attempt to retry needlessly.
          break;
        }

        // Refresh request parameters
        $nonce = $this->nonce();
        $req[ 'nonce' ] = sprintf( "%ld", $nonce );
        $uri = $this->getPrivateURL() . $method . '?' . http_build_query( $req );
        $sign = hash_hmac( 'sha512', $uri, $secret );
        curl_setopt( $ch, CURLOPT_URL, $uri );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, ["apisign: $sign" ] );
        continue;
      }
    }
    throw new Exception( $error );

  }

};

