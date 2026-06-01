<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/seller_ticket_helper.php';

$sellers = array(
    'alpha' => array('name' => 'Alpha'),
    'beta' => array('name' => 'Beta'),
);

$sales = array();
for ($i = 1; $i <= 23; $i++) {
    $sales[] = array(
        '.id' => '*alpha-' . $i,
        'date' => 'jun/01/2026',
        'profile' => '01-JOUR',
        'comment' => 'lot-alpha',
    );
}
$sales[] = array(
    '.id' => '*alpha-sold-out-1',
    'date' => 'jun/01/2026',
    'profile' => '05-JOURS',
    'comment' => 'lot-alpha',
);
$sales[] = array(
    '.id' => '*alpha-sold-out-2',
    'date' => 'jun/01/2026',
    'profile' => '05-JOURS',
    'comment' => 'lot-alpha',
);
$sales[] = array(
    '.id' => '*beta-1',
    'date' => 'jun/01/2026',
    'profile' => '01-JOUR',
    'comment' => 'beta',
);
$sales[] = array(
    '.id' => '*beta-1',
    'date' => 'jun/01/2026',
    'profile' => '01-JOUR',
    'comment' => 'beta',
);

$available = array(
    'alpha' => array('01-JOUR' => 100),
    'beta' => array('01-JOUR' => 20),
);

$metrics = mikhmon_seller_profile_metrics($sales, $available, $sellers);

if ($metrics['alpha']['01-JOUR'] !== array('sold' => 23, 'available' => 100, 'total' => 123)) {
    fwrite(STDERR, "alpha 01-JOUR expected 23 sold / 123 attributed\n");
    exit(1);
}

if ($metrics['alpha']['05-JOURS'] !== array('sold' => 2, 'available' => 0, 'total' => 2)) {
    fwrite(STDERR, "sold-out profile must remain visible\n");
    exit(1);
}

if ($metrics['beta']['01-JOUR'] !== array('sold' => 1, 'available' => 20, 'total' => 21)) {
    fwrite(STDERR, "duplicate RouterOS sale scripts must be ignored\n");
    exit(1);
}

$afterTransfer = mikhmon_seller_profile_metrics($sales, array(
    'alpha' => array('01-JOUR' => 80),
    'beta' => array('01-JOUR' => 40),
), $sellers);

if ($afterTransfer['alpha']['01-JOUR']['total'] !== 103 || $afterTransfer['beta']['01-JOUR']['total'] !== 41) {
    fwrite(STDERR, "available stock transfer must update attributed totals\n");
    exit(1);
}

echo "seller_stock_profile_metrics_test passed\n";
