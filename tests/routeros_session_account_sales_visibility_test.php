<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/seller_ticket_helper.php';

$sellers = array(
    'router_seller' => array(
        'name' => 'Router Seller',
        'session' => 'Safelink',
        'commission' => 10,
    ),
);

$routerosComment = 'Router Seller | MIKHMON_ACCOUNT role=vendeur session=Safelink account=router_seller';
if (mikhmon_comment_seller_key($routerosComment, $sellers) !== 'router_seller') {
    fwrite(STDERR, "portal sales must match RouterOS-created seller footprint comments\n");
    exit(1);
}

$sales = array(
    array(
        'date' => 'jun/12/2026',
        'time' => '09:00:00',
        'user' => 'R1',
        'price' => '1000',
        'profile' => '01-JOUR',
        'comment' => $routerosComment,
    ),
);

$summary = mikhmon_accounting_period_summary($sales, $sellers, '2026-06-12', '2026-06-12');
if ($summary['total']['count'] !== 1 || $summary['total']['revenue'] !== 1000.0) {
    fwrite(STDERR, "manager/admin accounting must include RouterOS-created seller sales\n");
    exit(1);
}

$managerPage = file_get_contents(__DIR__ . '/../manager.php');
if (strpos($managerPage, '$managerShouldLoadRouterData = true;') === false) {
    fwrite(STDERR, "manager dashboard must load router data so seller sales and stock are visible\n");
    exit(1);
}

echo "routeros_session_account_sales_visibility_test passed\n";
