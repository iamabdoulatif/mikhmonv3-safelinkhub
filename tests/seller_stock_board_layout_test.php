<?php

$root = dirname(__DIR__);
$sellers = file_get_contents($root . '/sellers.php');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($sellers === false || $portalCss === false) {
    fwrite(STDERR, "could not read seller stock board files\n");
    exit(1);
}

$start = strpos($sellers, "<?php elseif (\$action === 'stock-board'): ?>");
$end = strpos($sellers, "<?php elseif (\$action === 'transfer'): ?>", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "could not isolate seller stock board section\n");
    exit(1);
}

$stockBoard = substr($sellers, $start, $end - $start);

foreach (array(
    'stock board grid wrapper' => 'stock-board-grid',
    'stock board card' => 'stock-board-card',
    'stock profile row' => 'stock-profile-row',
    'stock profile sold value' => 'stock-profile-sold',
    'stock profile attributed total' => 'stock-profile-total',
    'stock request button' => 'stock-request-btn',
    'stock request all button' => 'stock-request-all-btn',
) as $label => $needle) {
    if (strpos($stockBoard, $needle) === false) {
        fwrite(STDERR, $label . " missing from seller stock board\n");
        exit(1);
    }
}

if (strpos($stockBoard, 'dashboard-hotspot-grid') !== false) {
    fwrite(STDERR, "seller stock board must not use dashboard hotspot color boxes\n");
    exit(1);
}

foreach (array(
    '.seller-portal .stock-board-grid',
    'grid-template-columns: repeat(4, minmax(0, 1fr));',
    '.stock-board-card-header',
    '.stock-profile-row',
    '.stock-request-btn',
    '@media (max-width: 1200px)',
    '@media (max-width: 700px)',
) as $cssHook) {
    if (strpos($portalCss, $cssHook) === false) {
        fwrite(STDERR, "seller stock board CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

echo "seller_stock_board_layout_test passed\n";
