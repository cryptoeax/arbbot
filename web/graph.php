<?php

require_once './header.inc.php';

$coin = filter_input( INPUT_GET, 'coin' );
$exchange = filter_input( INPUT_GET, 'exchange' );
$mode = filter_input( INPUT_GET, 'mode' );

echo json_encode( WebDB::getGraph( $coin, $exchange, $mode ) );
