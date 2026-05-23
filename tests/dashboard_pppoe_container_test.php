<?php

$root = dirname(__DIR__);
$files = array(
    'dashboard/home.php' => file_get_contents($root . '/dashboard/home.php'),
    'dashboard/aload.php' => file_get_contents($root . '/dashboard/aload.php'),
);

foreach ($files as $path => $contents) {
    if ($contents === false) {
        fwrite(STDERR, "could not read " . $path . "\n");
        exit(1);
    }

    $pppoeRow = strpos($contents, 'dashboard-pppoe-row');
    $pppoeCard = strpos($contents, 'dashboard-pppoe-card');
    $mainRow = strpos($contents, 'dashboard-main-row');

    if ($pppoeRow === false || $pppoeCard === false) {
        fwrite(STDERR, $path . " must render PPPoE in its own dashboard container\n");
        exit(1);
    }

    if ($mainRow !== false && $pppoeRow > $mainRow) {
        fwrite(STDERR, $path . " must place PPPoE before the revenue/dashboard-main container\n");
        exit(1);
    }

    $revenuePos = strpos($contents, 'reloadLreport');
    if ($revenuePos !== false && $pppoeRow > $revenuePos) {
        fwrite(STDERR, $path . " must keep PPPoE outside the revenue container\n");
        exit(1);
    }
}

$css = file_get_contents($root . '/css/mikhmon-responsive.css');
if ($css === false || strpos($css, '.dashboard-pppoe-row') === false || strpos($css, '.dashboard-pppoe-card') === false) {
    fwrite(STDERR, "dashboard PPPoE responsive CSS missing\n");
    exit(1);
}

$index = file_get_contents($root . '/index.php');
if ($index === false || strpos($index, '#r_pppoe') === false) {
    fwrite(STDERR, "dashboard PPPoE container must refresh independently\n");
    exit(1);
}

echo "dashboard_pppoe_container_test passed\n";
