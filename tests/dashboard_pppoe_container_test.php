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

    $hotspotRow = strpos($contents, 'dashboard-hotspot-row');
    $pppoeRow = strpos($contents, 'dashboard-pppoe-row');
    $pppoeCard = strpos($contents, 'dashboard-pppoe-card');
    $revenuePos = strpos($contents, 'reloadLreport');

    if ($hotspotRow === false || $pppoeRow === false || $pppoeCard === false) {
        fwrite(STDERR, $path . " must render Hotspot and PPPoE in separate dashboard containers\n");
        exit(1);
    }

    if ($hotspotRow > $pppoeRow) {
        fwrite(STDERR, $path . " must place Hotspot above PPPoE\n");
        exit(1);
    }

    if ($revenuePos !== false && $pppoeRow > $revenuePos) {
        fwrite(STDERR, $path . " must keep PPPoE outside the revenue container\n");
        exit(1);
    }
}

$css = file_get_contents($root . '/css/mikhmon-responsive.css');
if ($css === false
    || strpos($css, '.dashboard-hotspot-row') === false
    || strpos($css, '.dashboard-pppoe-row') === false
    || strpos($css, '.dashboard-pppoe-card') === false
    || strpos($css, '.seller-portal .main-container > .row') === false
    || strpos($css, '.manager-portal .main-container > .row') === false
    || strpos($css, '.portal-admin-shell > .row') === false) {
    fwrite(STDERR, "dashboard PPPoE responsive CSS missing\n");
    exit(1);
}

$index = file_get_contents($root . '/index.php');
if ($index === false || strpos($index, '#r_pppoe') === false) {
    fwrite(STDERR, "dashboard PPPoE container must refresh independently\n");
    exit(1);
}

echo "dashboard_pppoe_container_test passed\n";
