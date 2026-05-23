<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$sellerLine = mikhmon_php_assignment_line('sellers_data', 'seller1', array(
  'password' => "pa'ss",
  'name' => "O'Neil",
  'session' => "main'); die('x",
  'commission' => 10,
));

$sellers_data = array();
eval($sellerLine);

if (!isset($sellers_data['seller1'])) {
  fwrite(STDERR, 'seller assignment line did not create the expected key' . PHP_EOL);
  exit(1);
}

if ($sellers_data['seller1']['name'] !== "O'Neil") {
  fwrite(STDERR, 'seller assignment line did not preserve apostrophes' . PHP_EOL);
  exit(1);
}

if ($sellers_data['seller1']['session'] !== "main'); die('x") {
  fwrite(STDERR, 'seller assignment line did not keep session data literal-safe' . PHP_EOL);
  exit(1);
}

$routerLine = mikhmon_php_assignment_line('data', 'routeur-1', array(
  1 => "routeur-1!10.10.0.1",
  2 => "routeur-1@|@admin",
  3 => "routeur-1#|#encrypted",
  4 => "routeur-1%Hotspot d'Abidjan",
  5 => "routeur-1^wifi.local",
  6 => "routeur-1&XOF",
  7 => "routeur-1*10",
  8 => "routeur-1(1",
  9 => "routeur-1)48656c6c6f",
  10 => "routeur-1=10",
  11 => "routeur-1@!@disable",
));

$data = array();
eval($routerLine);

if (($data['routeur-1'][4] ?? '') !== "routeur-1%Hotspot d'Abidjan") {
  fwrite(STDERR, 'router assignment line did not preserve hotspot names safely' . PHP_EOL);
  exit(1);
}

if (!mikhmon_assignment_line_matches($routerLine, 'data', 'routeur-1')) {
  fwrite(STDERR, 'assignment matcher did not recognize generated router line' . PHP_EOL);
  exit(1);
}

echo "mikhmon_config_security_test passed\n";
