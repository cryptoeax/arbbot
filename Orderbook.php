<?php

class Orderbook {

  private $bestAsk;
  private $bestBid;
  private $source;
  private $tradeable;
  private $currency;

  function __construct( $source, $tradeable, $currency, $bestAsk, $bestBid ) {

    $this->source = $source;
    $this->tradeable = $tradeable;
    $this->currency = $currency;

    $this->bestAsk = $bestAsk;
    $this->bestBid = $bestBid;

  }

  public function getBestAsk() {
    return $this->bestAsk;

  }

  public function getBestBid() {
    return $this->bestBid;

  }

  public function getSource() {
    return $this->source;

  }

  public function getTradeable() {
    return $this->tradeable;

  }

  public function getCurrency() {
    return $this->currency;

  }

}

class OrderbookEntry {

  private $amount;
  private $price;

  function __construct( $amount, $price ) {
    $this->amount = $amount;
    $this->price = $price;

  }

  public function getAmount() {
    return $this->amount;

  }

  public function getPrice() {
    return $this->price;

  }

}
