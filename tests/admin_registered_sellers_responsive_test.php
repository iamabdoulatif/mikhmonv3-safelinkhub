<?php

$root = dirname(__DIR__);
$manage = file_get_contents($root . '/settings/manage_sellers.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');

if ($manage === false || $responsive === false) {
    fwrite(STDERR, "could not read admin registered sellers files\n");
    exit(1);
}

$start = strpos($manage, '<!-- ── Liste des vendeurs ── -->');
$end = strpos($manage, '<!-- Formulaires édition compte vendeur -->');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "could not isolate registered sellers block\n");
    exit(1);
}

$block = substr($manage, $start, $end - $start);

if (strpos($manage, '$displaySellersData = function_exists(\'mikhmon_filter_display_sellers\')') === false) {
    fwrite(STDERR, "admin sellers page must build a display-only seller list without historical accounts\n");
    exit(1);
}

if (strpos($block, 'foreach ($displaySellersData as $su => $sd)') === false
    || strpos($block, 'foreach ($sellers_data as $su => $sd)') !== false) {
    fwrite(STDERR, "registered sellers block must render display sellers, not raw sellers_data\n");
    exit(1);
}

if (strpos($manage, "if (!empty(\$restoredRecord['historical']))") === false) {
    fwrite(STDERR, "admin sellers page must not persist automatically restored historical sellers\n");
    exit(1);
}

foreach (array(
    'registered sellers row' => 'row admin-registered-sellers-row',
    'registered seller responsive column' => 'col-6 col-box-6 admin-registered-seller-col',
    'registered seller card' => 'admin-registered-seller-card',
    'registered seller header' => 'admin-registered-seller-head',
    'registered seller meta grid' => 'admin-registered-seller-meta',
    'registered seller actions' => 'admin-registered-seller-actions',
    'registered seller commission button' => 'admin-registered-seller-commission',
) as $label => $needle) {
    if (strpos($block, $needle) === false) {
        fwrite(STDERR, $label . " missing from registered sellers section\n");
        exit(1);
    }
}

if (strpos($block, 'portal-table-min-lg') !== false) {
    fwrite(STDERR, "registered sellers section must not use the wide desktop table on mobile\n");
    exit(1);
}

foreach (array(
    '.admin-registered-sellers-row',
    '.admin-registered-seller-col',
    '.admin-registered-seller-card',
    '.admin-registered-seller-actions',
    '.portal-admin-shell .admin-registered-sellers-row.row > .admin-registered-seller-col',
    'width: 50% !important;',
    'width: 100% !important;',
) as $cssHook) {
    if (strpos($responsive, $cssHook) === false) {
        fwrite(STDERR, "registered sellers responsive CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

foreach (array(
    'manage-sellers-card-header',
    'manage-sellers-dashboard-btn',
    '.manage-sellers-dashboard-btn',
    'min-height:44px',
) as $responsiveHook) {
    if (strpos($manage, $responsiveHook) === false) {
        fwrite(STDERR, "manage sellers dashboard mobile hook missing: " . $responsiveHook . "\n");
        exit(1);
    }
}

echo "admin_registered_sellers_responsive_test passed\n";
