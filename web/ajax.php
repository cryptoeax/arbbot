<?php

require_once './header.inc.php';

$func = filter_input( INPUT_GET, 'func' );

switch ($func) {
case 'alerts':
  $results = WebDB::getAlerts();
  break;
case 'graph':
  $coin = filter_input( INPUT_GET, 'coin' );
  $exchange = filter_input( INPUT_GET, 'exchange' );
  $mode = filter_input( INPUT_GET, 'mode' );

  $results = WebDB::getGraph( $coin, $exchange, $mode );
  break;
case 'log':
  $results = WebDB::getLog( time() - 60 );
  break;
case 'management':
  $results = WebDB::getManagement();
  break;
case 'pl':
  $mode = filter_input( INPUT_GET, 'mode' );

  $results = WebDB::getPL( $mode );
  break;
case 'stats':
  $results = WebDB::getStats();
  $results[ 'trades' ] = WebDB::getTradeCount(0);
  break;
case 'trades':
  $results = WebDB::getTrades();
  break;
case 'wallets':
  $results = WebDB::getWalletStats();
  break;
case 'xfer':
  $results = WebDB::getXfer();
  break;
}
  
echo json_encode( $results );
