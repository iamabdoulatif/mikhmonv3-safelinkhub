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

$dockerfile = file_get_contents($root . '/Dockerfile');
$mikrotikDockerfile = file_get_contents($root . '/Dockerfile.mikrotik');
foreach (array($dockerfile, $mikrotikDockerfile) as $dockerConfigReset) {
  if (strpos($dockerConfigReset, '$data["mikhmon"] = array ("1"=>"mikhmon<|<mikhmon","2"=>"mikhmon>|>aWNlbA==");') === false) {
    fwrite(STDERR, 'default Docker image must start with zero router sessions' . PHP_EOL);
    exit(1);
  }
  if (strpos($dockerConfigReset, '$data["Safelink"]') !== false || strpos($dockerConfigReset, "v2:") !== false) {
    fwrite(STDERR, 'Docker image router credentials must not depend on a host-local secret.key.php' . PHP_EOL);
    exit(1);
  }
}

include $root . '/include/config.php';
if (empty($data['mikhmon'][1]) || empty($data['mikhmon'][2])) {
  fwrite(STDERR, 'default admin credentials must remain present after router session reset' . PHP_EOL);
  exit(1);
}

echo "mikhmon_router_connection_guard_test passed\n";
