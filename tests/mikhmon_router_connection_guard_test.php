<?php
$root = dirname(__DIR__);

$sellerPage = file_get_contents($root . '/sellers.php');
$managerPage = file_get_contents($root . '/manager.php');

if (substr_count($sellerPage, '->connect(') !== 1) {
  fwrite(STDERR, 'seller portal must not reconnect to RouterOS multiple times in one request' . PHP_EOL);
  exit(1);
}

if (strpos($sellerPage, '$seller_router_connected') === false || strpos($managerPage, '$manager_router_connected') === false) {
  fwrite(STDERR, 'portals must track RouterOS connection state explicitly' . PHP_EOL);
  exit(1);
}

if (strpos($sellerPage, '$seller_connection_error') === false || strpos($managerPage, '$manager_connection_error') === false) {
  fwrite(STDERR, 'portals must render a clear RouterOS connection error instead of an empty loading page' . PHP_EOL);
  exit(1);
}

include $root . '/lib/routeros_api.class.php';
$session = 'ALB-TECH';
include $root . '/include/config.php';
include $root . '/include/readcfg.php';

if ($iphost !== '172.25.194.29' || $userhost !== 'admin' || decrypt($passwdhost) === '') {
  fwrite(STDERR, 'ALB-TECH router config must point to the reachable RouterOS API and decrypt cleanly' . PHP_EOL);
  exit(1);
}

if (strpos($passwdhost, 'v2:') === 0) {
  fwrite(STDERR, 'Docker image router credentials must not depend on a host-local secret.key.php' . PHP_EOL);
  exit(1);
}

echo "mikhmon_router_connection_guard_test passed\n";
