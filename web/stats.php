<?php

require_once './header.inc.php';

$result = WebDB::getStats();
$result[ 'trades' ] = WebDB::getTradeCount(0);

echo json_encode( $result );
