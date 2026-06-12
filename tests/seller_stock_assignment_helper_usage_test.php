<?php

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/settings/manage_sellers.php');
$manager = file_get_contents($root . '/manager.php');

if ($admin === false || $manager === false) {
    fwrite(STDERR, "could not read stock transfer files\n");
    exit(1);
}

foreach (array(
    'admin stock scan' => '$matchedSeller = mikhmon_comment_seller_key(isset($u[\'comment\']) ? $u[\'comment\'] : \'\', $sellers_data);',
    'admin source transfer' => 'mikhmon_comment_seller_key(isset($u[\'comment\']) ? $u[\'comment\'] : \'\', $sellers_data) === $src',
    'admin global stock refresh' => '$assigned = mikhmon_comment_seller_key(isset($u[\'comment\']) ? $u[\'comment\'] : \'\', $sellers_data) !== \'\';',
) as $label => $needle) {
    if (strpos($admin, $needle) === false) {
        fwrite(STDERR, $label . " must use the shared seller comment helper\n");
        exit(1);
    }
}

if (strpos($manager, 'mikhmon_comment_seller_key(isset($u[\'comment\']) ? $u[\'comment\'] : \'\', $managerSellersData) === $src') === false) {
    fwrite(STDERR, "manager source transfer must use the shared seller comment helper\n");
    exit(1);
}

foreach (array($admin, $manager) as $source) {
    if (preg_match('/substr\(\$cmt,\s*-strlen\(\$sfx(Key)?\)\)\s*===\s*\$sfx(Key)?/', $source)) {
        fwrite(STDERR, "stock assignment must not rely on seller-id suffix checks only\n");
        exit(1);
    }
}

echo "seller_stock_assignment_helper_usage_test passed\n";
