<?php

$root = dirname(__DIR__);
$files = array(
    'dashboard/home.php' => file_get_contents($root . '/dashboard/home.php'),
    'dashboard/aload.php' => file_get_contents($root . '/dashboard/aload.php'),
);
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');

if ($responsive === false) {
    fwrite(STDERR, "could not read responsive CSS\n");
    exit(1);
}

foreach ($files as $path => $contents) {
    if ($contents === false) {
        fwrite(STDERR, "could not read " . $path . "\n");
        exit(1);
    }

    $start = strpos($contents, 'dashboard-hotspot-card');
    $end = strpos($contents, 'dashboard-pppoe-row', $start);
    if ($start === false || $end === false || $end <= $start) {
        fwrite(STDERR, $path . " must keep a Hotspot block before PPPoE\n");
        exit(1);
    }

    $hotspot = substr($contents, $start, $end - $start);
    if (substr_count($hotspot, 'col-3 col-box-6') !== 4) {
        fwrite(STDERR, $path . " Hotspot cards must keep four Bootstrap columns\n");
        exit(1);
    }
}

if (!preg_match('/@media\s+screen\s+and\s+\(min-width:\s*750px\)[^{]*\{[^}]*\.dashboard-hotspot-card\s+\.dashboard-hotspot-grid\s*>\s*\.col-3\s*,\s*\.dashboard-pppoe-card\s+\.dashboard-hotspot-grid\s*>\s*\.col-3\s*\{[^}]*width:\s*25%/is', $responsive)) {
    fwrite(STDERR, "dashboard hotspot cards must be four aligned columns on desktop\n");
    exit(1);
}

if (!preg_match('/@media\s+screen\s+and\s+\(max-width:\s*749px\)[^{]*\{.*?\.dashboard-hotspot-card\s+\.dashboard-hotspot-grid\s*>\s*\.col-3\s*,\s*\.dashboard-pppoe-card\s+\.dashboard-hotspot-grid\s*>\s*\.col-3\s*\{[^}]*width:\s*50%/is', $responsive)) {
    fwrite(STDERR, "dashboard hotspot cards must switch to two-by-two on mobile\n");
    exit(1);
}

if (preg_match('/\.dashboard-hotspot-card\s+\.dashboard-hotspot-grid\s*>\s*\.col-box-6\s*\{[^}]*width:\s*100%/is', $responsive)) {
    fwrite(STDERR, "dashboard hotspot cards must not collapse to one card per row on mobile\n");
    exit(1);
}

echo "dashboard_hotspot_layout_test passed\n";
