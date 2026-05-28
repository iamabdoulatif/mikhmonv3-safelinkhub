<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manager === false || $responsive === false || $portalCss === false) {
    fwrite(STDERR, "could not read manager overview files\n");
    exit(1);
}

$css = $responsive . "\n" . $portalCss;

$overviewStart = strpos($manager, "<?php elseif (\$action === 'overview'): ?>");
$overviewEnd = strpos($manager, "<?php elseif (\$action === 'accounting'): ?>");
if ($overviewStart === false || $overviewEnd === false || $overviewEnd <= $overviewStart) {
    fwrite(STDERR, "could not isolate manager overview block\n");
    exit(1);
}

$overview = substr($manager, $overviewStart, $overviewEnd - $overviewStart);

$requiredOverviewHooks = array(
    'overview bootstrap container' => 'container-fluid manager-overview-container',
    'overview shell' => 'manager-overview-shell',
    'overview filter row' => 'row manager-overview-row manager-overview-filter-row',
    'overview summary row' => 'row mgr-summary-cards manager-overview-row manager-overview-summary-row',
    'overview profile row' => 'row manager-overview-row manager-overview-profile-row',
    'overview grid' => 'manager-overview-grid',
    'overview profile card' => 'manager-overview-profile-card',
    'overview profile stats' => 'manager-overview-profile-stats',
    'overview table wrap' => 'manager-overview-table-wrap',
    'overview mobile value wrapper' => 'manager-overview-cell-value',
    'overview bootstrap card column' => 'col-3 col-box-6 manager-bootstrap-col',
);

foreach ($requiredOverviewHooks as $label => $needle) {
    if (strpos($overview, $needle) === false) {
        fwrite(STDERR, $label . " missing from manager overview\n");
        exit(1);
    }
}

$summaryStart = strpos($overview, 'manager-overview-summary-row');
$profileStart = strpos($overview, 'manager-overview-profile-row');
if ($summaryStart === false || $profileStart === false || $profileStart <= $summaryStart) {
    fwrite(STDERR, "manager overview must place summary before profile rows\n");
    exit(1);
}
$summary = substr($overview, $summaryStart, $profileStart - $summaryStart);

foreach (array('bg-blue', 'bg-green', 'bg-yellow', 'bg-red') as $colorClass) {
    if (strpos($summary, $colorClass) === false) {
        fwrite(STDERR, "manager overview summary must use admin color " . $colorClass . "\n");
        exit(1);
    }
}

foreach (array('Aujourd\'hui', '$overviewReportPeriodLabel', 'Stock total', 'Commissions') as $label) {
    if (strpos($summary, $label) === false) {
        fwrite(STDERR, "manager overview summary label missing: " . $label . "\n");
        exit(1);
    }
}

$profile = substr($overview, $profileStart);
foreach (array('bg-blue', 'bg-green', 'bg-yellow', 'bg-red') as $colorClass) {
    if (strpos($profile, 'manager-overview-stat ' . $colorClass) === false) {
        fwrite(STDERR, "manager overview profile stats must use " . $colorClass . "\n");
        exit(1);
    }
}

foreach (array('data-label="<?= isset($_seller) ? $_seller : \'Vendeur\' ?>"', 'data-label="CA période"', 'data-label="Stock"', 'manager-overview-cell-value') as $mobileNeedle) {
    if (strpos($profile, $mobileNeedle) === false) {
        fwrite(STDERR, "manager overview mobile table markup missing: " . $mobileNeedle . "\n");
        exit(1);
    }
}

foreach (array('.manager-overview-row', '.manager-overview-summary-row', '.manager-overview-profile-row', '.manager-overview-table-wrap', '.manager-bootstrap-col', '.manager-overview-table td::before', '.manager-overview-cell-value') as $cssHook) {
    if (strpos($css, $cssHook) === false) {
        fwrite(STDERR, "manager overview responsive CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

if (strpos($css, 'overflow-x: visible !important;') === false || strpos($css, 'display: flex !important;') === false) {
    fwrite(STDERR, "manager overview mobile table must avoid horizontal scrolling\n");
    exit(1);
}

echo "manager_overview_layout_test passed\n";
