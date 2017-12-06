<?php

require_once './WebDB.php';

header( 'Cache-Control: no-cache, must-revalidate' );
header( 'Expires: Mon, 26 Jul 1997 05:00:00 GMT' );
header( 'Content-type: application/json' );

function isLocal() {
  return $_SERVER[ 'HTTP_HOST' ] === 'localhost' ||
         $_SERVER[ 'HTTP_HOST' ] === '127.0.0.1';
}

function isSecure() {
  return !empty( $_SERVER[ 'HTTPS' ] ) && $_SERVER[ 'HTTPS' ] === 'on';
}

if (!isSecure() && !isLocal() &&
    !Config::get( Config::ALLOW_INSECURE_UI, Config::DEFAULT_ALLOW_INSECURE_UI )) {
  // Refuse to render the UI on insecure Internet exposed addresses.
  die( "{\"error\":\"UI is disabled, please turn on HTTPS.\"}" );
}

function isAuthenticated() {
  return !empty( $_SERVER[ 'PHP_AUTH_USER' ] );
}

if (!isAuthenticated() && !isLocal() &&
    !Config::get( Config::ALLOW_UNAUTHENTICATED_UI, Config::DEFAULT_ALLOW_UNAUTHENTICATED_UI )) {
  // Refuse to render the UI on insecure Internet exposed addresses.
  die( "{\"error\":\"UI is disabled, please turn on HTTP authentication.\"}" );
}

