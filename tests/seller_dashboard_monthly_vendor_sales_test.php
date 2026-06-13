<?php

$root = dirname(__DIR__);
$seller = file_get_contents($root . '/sellers.php');

if ($seller === false) {
    fwrite(STDERR, "could not read sellers.php\n");
    exit(1);
}

foreach (array(
    'dashboard must load router data for revenue cards' => '$sellerShouldLoadRouterData = true;',
    'monthly vendor sales data structure' => '$sellerMonthlySalesByVendor',
    'same session vendor filter' => '$sellerSessionSellers',
    'monthly vendor sales dashboard card' => 'seller-monthly-vendor-sales-card',
    'monthly vendor sales rows' => 'seller-monthly-vendor-sales-row',
    'monthly vendor sales count label' => 'Tickets vendus ce mois',
) as $label => $needle) {
    if (strpos($seller, $needle) === false) {
        fwrite(STDERR, $label . " missing\n");
        exit(1);
    }
}

echo "seller_dashboard_monthly_vendor_sales_test passed\n";
