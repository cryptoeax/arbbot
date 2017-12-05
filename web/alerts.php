<?php

require_once './header.inc.php';

echo json_encode( WebDB::getAlerts() );
