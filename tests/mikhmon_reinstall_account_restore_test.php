<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/hotspot_account_assignment.php';

$routerUsers = array(
    array(
        '.id' => '*r1',
        'name' => 'router_seller',
        'comment' => mikhmon_hotspot_assignment_comment('Router Seller', 'Safelink', 'seller', 'router_seller', password_hash('seller-pass', PASSWORD_DEFAULT)),
    ),
    array(
        '.id' => '*r2',
        'name' => 'router_manager',
        'comment' => mikhmon_hotspot_assignment_comment('Router Manager', 'Safelink', 'manager', 'router_manager', password_hash('manager-pass', PASSWORD_DEFAULT)),
    ),
);

$sales = array(
    array(
        '.id' => '*sale1',
        'name' => 'jun/05/2026-|-08:00:00-|-U1-|-1000-|-10.0.0.2-|-AA-|-1h-|-1H-|-lot-alpha',
        'comment' => 'mikhmon',
        'owner' => 'jun2026',
    ),
    array(
        '.id' => '*sale2',
        'name' => 'jun/05/2026-|-09:00:00-|-U2-|-1000-|-10.0.0.3-|-BB-|-1h-|-01-JOUR-|-vc-123-06.12.26-01-JOUR',
        'comment' => 'mikhmon',
        'owner' => 'jun2026',
    ),
);

$stockUsers = array(
    array('.id' => '*u1', 'name' => 'vcr001', 'profile' => '01-JOUR', 'uptime' => '0s', 'comment' => 'lot-beta'),
    array('.id' => '*u2', 'name' => 'vcr002', 'profile' => '01-JOUR', 'uptime' => '0s', 'comment' => 'vc-123-06.12.26-01-JOUR'),
);

$restored = mikhmon_hotspot_restored_account_records(
    array(),
    array(),
    'Safelink',
    array(),
    array(),
    $routerUsers,
    $sales,
    $stockUsers
);

if (!isset($restored['sellers']['router_seller']) || !password_verify('seller-pass', $restored['sellers']['router_seller']['password'])) {
    fwrite(STDERR, "limited RouterOS seller must survive local/container reinstall\n");
    exit(1);
}

if (!isset($restored['managers']['router_manager']) || !password_verify('manager-pass', $restored['managers']['router_manager']['password'])) {
    fwrite(STDERR, "limited RouterOS manager must survive local/container reinstall\n");
    exit(1);
}

if (!isset($restored['sellers']['alpha']) || empty($restored['sellers']['alpha']['historical'])) {
    fwrite(STDERR, "seller from historical RouterOS sales must be restored for accounting totals\n");
    exit(1);
}

if (!isset($restored['sellers']['beta']) || empty($restored['sellers']['beta']['historical'])) {
    fwrite(STDERR, "seller from assigned stock comments must be restored for stock counts\n");
    exit(1);
}

if (isset($restored['sellers']['JOUR']) || isset($restored['sellers']['01JOUR'])) {
    fwrite(STDERR, "global stock profile suffixes must not be restored as fake sellers\n");
    exit(1);
}

echo "mikhmon_reinstall_account_restore_test passed\n";
