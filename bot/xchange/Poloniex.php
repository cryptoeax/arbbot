<?php
ini_set("precision", 16);

require_once __DIR__ . '/../Config.php';

class Poloniex extends Exchange {

  const ID = 1;
//
  const PUBLIC_URL = 'https://poloniex.com/public?command=';

//
  private $depositAddresses;
  private $tradeFee = 0.0025;

  private $fullOrderHistory = null;

  function __construct() {
    parent::__construct( Config::get( "poloniex.key" ), Config::get( "poloniex.secret" ) );

  }

  public function addFeeToPrice( $price ) {

    return $price * (1 + $this->tradeFee);

  }

  public function deductFeeFromAmountBuy( $amount ) {

    return $amount * (1 - $this->tradeFee);

  }

  public function deductFeeFromAmountSell( $amount ) {

    return $amount * (1 - $this->tradeFee);

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
      if ( strpos( $ex->getMessage(), 'daily withdrawal limit' ) !== false ) {
        $alert = str_replace( 'API error response: ', '', $ex->getMessage() );
        $alert .= sprintf( "\nHappened while withdrawing %s %s to the following address: %s.",
                           formatBTC( $amount ), $coin, $address );
        alert( 'poloniex-withdrawal-limit', $alert );
        return false;
      }
      throw $ex;
    }

  }

  public function getDepositAddress( $coin ) {
    if ( !key_exists( $coin, $this->depositAddresses ) ) {
      $result = $this->queryAPI( 'generateNewAddress',
                                 [ 'currency' => $coin ] );
      if ($result[ 'success' ] !== 1) {
        return null;
      }
      $this->depositAddresses[ $coin ] = $result[ 'response' ];
    }

    $address = $this->depositAddresses[ $coin ];
    if ( strpos( strtolower( $address ), 'press' ) !== false ) {
      logg( $this->prefix() . "Please generate a deposit address for $coin!", true );
      return null;
    }

    return $address;

  }

  public function buy( $tradeable, $currency, $rate, $amount ) {
    try {
      return $this->queryOrder( $tradeable, $currency, 'buy', $rate, $amount );
    }
    catch ( Exception $ex ) {
      logg( $this->prefix() . "Got an exception in buy(): " . $ex->getMessage() );
      return null;
    }

  }

  public function sell( $tradeable, $currency, $rate, $amount ) {
    try {
      return $this->queryOrder( $tradeable, $currency, 'sell', $rate, $amount );
    }
    catch ( Exception $ex ) {
      logg( $this->prefix() . "Got an exception in sell(): " . $ex->getMessage() );
      return null;
    }

  }

  public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {
    if (!preg_match( '/^[A-Z0-9_]+:(.*)$/', $id, $matches )) {
      throw new Exception( $this->prefix() . "Invalid order id: " . $id);
    }
    $orderNumber = $matches[ 1 ];
    $market = $currency . "_" . $tradeable;
    $result = $this->queryAPI( 'returnTradeHistory', [ 'currencyPair' => $market ] );

    foreach ($result as $entry) {
      if ($entry[ 'orderNumber' ] == $orderNumber) {
        $factor = ($type == 'sell') ? -1 : 1;
        return floatval( $entry[ 'total' ] ) * (1 + $factor * floatval( $entry[ 'fee' ] ));
      }
    }
    return null;
  }

  public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
    $results = array( );

    if (!$recentOnly && $this->fullOrderHistory !== null) {
      $results = $this->fullOrderHistory;
    } else if (!$recentOnly && !$this->fullOrderHistory &&
               file_exists( __DIR__ . '/../../poloniex-tradeHistory.csv' )) {
      $file = file_get_contents( __DIR__ . '/../../poloniex-tradeHistory.csv' );
      $lines = explode( "\n", $file );
      $first = true;
      foreach ($lines as $line) {
        if ($first) {
          // Ignore the first line.
          $first = false;
          continue;
        }
        $data = str_getcsv( $line );
        if (count( $data ) != 11) {
          continue;
        }
        $market = $data[ 1 ];
        $arr = explode( '/', $market );
        $currency = $arr[ 1 ];
        $tradeable = $arr[ 0 ];
        $market = "${currency}_${tradeable}";
        $feeFactor = ($data[ 3 ] == 'Sell') ? -1 : 1;
        $results[ $market ][] = array(
          'rawID' => $data[ 8 ],
          'id' => $currency . '_' . $tradeable . ':' . $data[ 8 ],
          'currency' => $currency,
          'tradeable' => $tradeable,
          'type' => strtolower( $data[ 3 ] ),
          'time' => strtotime( $data[ 0 ] ),
          'rate' => floatval( $data[ 4 ] ),
          'amount' => floatval( $data[ 5 ] ),
          'fee' => floatval( $data[ 6 ] ) * ($feeFactor * floatval( $data[ 7 ] )),
          'total' => floatval( $data[ 6 ] ),
        );
      }
      $this->fullOrderHistory = $results;
    }

    if (!in_array( 'currencyPair', $options )) {
      $options[ 'currencyPair' ] = 'all';
    }
    $history = $this->queryAPI( 'returnTradeHistory', $options );

    $checkArray = !empty( $results );

    $idsSeen = array( );

    foreach (array_keys($history) as $market) {
      $arr = explode( '_', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      foreach ($history[ $market ] as $row) {
	if ( isset( $idsSeen[ $row[ 'orderNumber' ] ] ) ) {
	  // Poloniex sometimes returns duplicate results!
	  continue;
	}
	$idsSeen[ $row[ 'orderNumber' ] ] = true;

        if (!in_array( $market, array_keys( $results ) )) {
          $results[ $market ] = array();
        }
        $feeFactor = ($row[ 'type' ] == 'sell') ? -1 : 1;

        if ($checkArray) {
          $seen = false;
          foreach ($results[ $market ] as $item) {
            if ($item[ 'rawID' ] == $row[ 'orderNumber' ]) {
              // We have already recorder this ID.
              $seen = true;
              break;
            }
          }
          if ($seen) {
            continue;
          }
        }

        $results[ $market ][] = array(
          'rawID' => $row[ 'orderNumber' ],
          'id' => $currency . '_' . $tradeable . ':' . $row[ 'orderNumber' ],
          'currency' => $currency,
          'tradeable' => $tradeable,
          'type' => $row[ 'type' ],
          'time' => strtotime( $row[ 'date' ] ),
          'rate' => floatval( $row[ 'rate' ] ),
          'amount' => floatval( $row[ 'amount' ] ),
          'fee' => floatval( $row[ 'total' ] ) * ($feeFactor * floatval( $row[ 'fee' ] )),
          'total' => floatval( $row[ 'total' ] ),
        );
      }
    }

    foreach ( array_keys( $results ) as $market ) {
      usort( $results[ $market ], 'compareByTime' );
    }

    return $results;
  }

  public function getRecentOrderTrades( &$arbitrator, $tradeable, $currency, $type, $orderID, $tradeAmount ) {

    if (!preg_match( '/^[A-Z0-9_]+:(.*)$/', $orderID, $matches )) {
      throw new Exception( $this->prefix() . "Invalid order id: " . $orderID );
    }
    $rawOrderID = $matches[ 1 ];
    $results = $this->queryAPI( 'returnOrderTrades', array(
      'orderNumber' => $rawOrderID,
    ) );

    $trades = array( );
    $feeFactor = ($type == 'sell') ? -1 : 1;
    foreach ( $results as $row ) {
      $trades[] = array(
        'rawID' => $rawOrderID . '/' . $row[ 'globalTradeID' ] . '/' . $row[ 'tradeID' ],
        'id' => $orderID,
        'currency' => $currency,
        'tradeable' => $tradeable,
        'type' => $type,
        'time' => strtotime( $row[ 'date' ] ),
        'rate' => floatval( $row[ 'rate' ] ),
        'amount' => floatval( $row[ 'amount' ] ),
        'fee' => floatval( $row[ 'total' ] ) * ($feeFactor * floatval( $row[ 'fee' ] )),
        'total' => floatval( $row[ 'total' ] ),
      );
    }

    return $trades;

  }

  private function queryRecentTransfers( $type, $currency ) {

    $now = time();
    $history = $this->queryAPI( 'returnDepositsWithdrawals', array(
      'start' => $now - 30 * 60,
      'end' => $now,
    ) );

    $result = array();
    foreach ( $history[ "${type}s" ] as $row ) {
      if ( !is_null( $currency ) && $currency != $row[ 'currency' ] ) {
        continue;
      }

      $result[] = array(
        'currency' => $row[ 'currency' ],
        'amount' => $row[ 'amount' ],
        // deposits have txid, withdrawals have withdrawalNumber
        'txid' => isset( $row[ 'txid' ] ) ? $row[ 'txid' ] : $row[ 'withdrawalNumber' ],
        'address' => $row[ 'address' ],
        'time' => $row[ 'timestamp' ],
        'pending' => strpos( $row[ 'status' ], 'COMPLETE' ) !== false,
      );
    }

    usort( $result, 'compareByTime' );

    return $result;

  }

  public function queryRecentDeposits( $currency = null ) {

    return $this->queryRecentTransfers( 'deposit', $currency );

  }

  public function queryRecentWithdrawals( $currency = null ) {

    return $this->queryRecentTransfers( 'withdrawal', $currency );

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
      if ( strpos( $ex->getMessage(), 'Invalid order number' ) === false ) {
	logg( $this->prefix() . "Got an exception in cancelOrder(): " . $ex->getMessage() );
	return true;
      }
      return false;
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

    if (empty($this->wallets)) {
      logg("Attempting to refresh exchange data before wallets are initialized");
      throw new Exception("wallets not initialized");
    }

    $pairs = [ ];
    $markets = $this->queryTicker();

    $currencies = $this->queryCurrencies();

    foreach ( $markets as $market => $data ) {

      $split = explode( "_", $market );
      $tradeable = $split[ 1 ];
      $currency = $split[ 0 ];

      if ( !Config::isCurrency( $currency ) ||
           Config::isBlocked( $tradeable ) ||
           !in_array( $tradeable, array_keys( $this->wallets ) ) ) {
        continue;
      }

      if ( !key_exists( $tradeable, $currencies ) ||
           $currencies[ $tradeable ][ 'disabled' ] == 1 ||
           $currencies[ $tradeable ][ 'delisted' ] == 1 ||
           $currencies[ $tradeable ][ 'frozen' ] == 1 ) {
        continue;
      }

      $pair = strtoupper( $tradeable . "_" . $currency );
      $pairs[] = $pair;
    }

    $fees = [ ];
    $conf = [ ];
    foreach ( $currencies as $coin => $data ) {
      $fees[ $coin ] = $data[ 'txFee' ];
      $conf[ $coin ] = $data[ 'minConf' ];
    }

    $depositAddresses = $this->queryDepositAddresses();

    $feeInfo = $this->queryFeeInfo();
    // It is hard to know in each trade whether we are going to be the maker or the taker,
    // so we are going to assume the worst and assume we're always the taker since that one
    // has the highest fees.
    $this->tradeFee = floatval( $feeInfo[ 'takerFee' ] );

    $this->pairs = $pairs;
    $this->transferFees = $fees;
    $this->confirmationTimes = $conf;
    $this->depositAddresses = $depositAddresses;

    $this->calculateTradeablePairs();

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
          alert( 'stuck-transfer', $this->prefix() . "Stuck $key! Please investigate and open support ticket if neccessary!\n\n" . print_r( $entry, true ), true );
          $this->lastStuckReportTime[ $key ] = $timestamp;
        }
      }
    }

  }

  public function getWalletsConsideringPendingDeposits() {

    $result = [ ];
    foreach ( $this->wallets as $coin => $balance ) {
      $result[ $coin ] = $balance;
    }
    $history = $this->queryDepositsAndWithdrawals();

    foreach ( $history[ 'deposits' ] as $entry ) {

      $status = strtoupper( $entry[ 'status' ] );
      if ($status != 'PENDING') {
        continue;
      }

      $coin = strtoupper( $entry[ 'currency' ] );
      $amount = $entry[ 'amount' ];
      $result[ $coin ] += $amount;

    }

    return $result;

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

  public function getTradeHistoryCSVName() {

    return "poloniex-tradeHistory.csv";

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

  private function queryFeeInfo() {
    return $this->queryAPI( 'returnFeeInfo' );
  }

  public function queryAPI( $command, array $req = [ ] ) {

    $key = $this->apiKey;
    $secret = $this->apiSecret;
    //$mt = explode(' ', microtime());
    $nonce = $this->nonce();


    $req[ 'command' ] = $command;
    //$req[ 'nonce' ] = $mt[1].substr($mt[0], 2, 6);
    $req['nonce'] = sprintf( "%ld", $nonce );
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
      curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
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
      $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
      if ( $res === false || $code != 200 ) {
        $error = $this->prefix() . "Could not get reply (HTTP ${code}): " . curl_error( $ch );
        logg( $error );

        // Refresh request parameters
        $nonce = $this->nonce();
        $req['nonce'] = sprintf( "%ld", $nonce );
        $post_data = http_build_query( $req, '', '&' );
        $sign = hash_hmac( 'sha512', $post_data, $secret );
        $headers[ 1 ] = 'Sign: ' . $sign;
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        continue;
      }

      $data = json_decode( $res, true );
      if ( $data === null ) {
        $error = $this->prefix() . "Invalid data received: (" . $res . ")";
        logg( $error );

        // Refresh request parameters
        $nonce = $this->nonce();
        $req['nonce'] = sprintf( "%ld", $nonce );
        $post_data = http_build_query( $req, '', '&' );
        $sign = hash_hmac( 'sha512', $post_data, $secret );
        $headers[ 1 ] = 'Sign: ' . $sign;
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
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
