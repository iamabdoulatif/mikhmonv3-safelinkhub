<?php

$root = dirname(__DIR__);
$css = file_get_contents($root . '/css/mikhmon-portal.css');
$home = file_get_contents($root . '/dashboard/home.php');

if ($css === false || $home === false) {
    fwrite(STDERR, "could not read revenue forecast files\n");
    exit(1);
}

$homeHooks = array(
    'forecast card container' => 'class="card forecast-card"',
    'forecast details grid' => 'forecast-details',
    'forecast detail items' => 'forecast-detail-item',
);

foreach ($homeHooks as $label => $needle) {
    if (strpos($home, $needle) === false) {
        fwrite(STDERR, $label . " missing from dashboard forecast markup\n");
        exit(1);
    }
}

$cssHooks = array(
    'forecast contained width' => '#r_forecast',
    'forecast mobile breakpoint' => '@media screen and (max-width: 640px)',
    'forecast single column grid' => 'grid-template-columns: 1fr;',
    'forecast text wrapping' => 'overflow-wrap: anywhere;',
    'forecast fluid amount' => 'clamp(1.35rem, 7vw, 2rem)',
);

foreach ($cssHooks as $label => $needle) {
    if (strpos($css, $needle) === false) {
        fwrite(STDERR, $label . " missing from forecast responsive CSS\n");
        exit(1);
    }
}

echo "revenue_forecast_responsive_test passed\n";
