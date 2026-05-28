<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manager === false || $portalCss === false) {
    fwrite(STDERR, "could not read manager vendor stock board files\n");
    exit(1);
}

$start = strpos($manager, "<?php elseif (\$action === 'vendors'): ?>");
$end = strpos($manager, "<?php elseif (\$action === 'logs'): ?>", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "could not isolate manager vendors section\n");
    exit(1);
}

$vendors = substr($manager, $start, $end - $start);

foreach (array(
    'manager vendor stock row' => 'manager-vendors-stock-row',
    'manager vendor stock card' => 'manager-vendors-stock-card',
    'manager stock board grid' => 'manager-stock-board-grid',
    'shared stock board card' => 'stock-board-card',
    'shared stock profile row' => 'stock-profile-row',
) as $label => $needle) {
    if (strpos($vendors, $needle) === false) {
        fwrite(STDERR, $label . " missing from manager vendors section\n");
        exit(1);
    }
}

foreach (array(
    '.manager-portal .stock-board-grid',
    '.manager-stock-board-grid',
    '.manager-vendors-stock-card',
) as $cssHook) {
    if (strpos($portalCss, $cssHook) === false) {
        fwrite(STDERR, "manager vendor stock board CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

echo "manager_vendor_stock_board_layout_test passed\n";
