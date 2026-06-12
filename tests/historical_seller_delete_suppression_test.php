<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/hotspot_account_assignment.php';

$sales = array(
    array(
        '.id' => '*sale1',
        'name' => 'jun/12/2026-|-19:57:06-|-U1-|-1000-|-10.0.0.2-|-AA-|-1h-|-01-JOUR-|-Mijai',
        'comment' => 'mikhmon',
        'owner' => 'jun2026',
    ),
);

$stockUsers = array(
    array(
        '.id' => '*u1',
        'name' => 'vcr001',
        'profile' => '01-JOUR',
        'uptime' => '0s',
        'comment' => 'Levie',
    ),
);

$restored = mikhmon_hotspot_restored_account_records(
    array(),
    array(),
    'Safelink',
    array(),
    array(),
    array(),
    $sales,
    $stockUsers
);

if (!isset($restored['sellers']['Mijai']) || !isset($restored['sellers']['Levie'])) {
    fwrite(STDERR, "historical sellers must still be restored when they were not deleted\n");
    exit(1);
}

$suppressed = mikhmon_hotspot_restored_account_records(
    array(),
    array(),
    'Safelink',
    array(),
    array(),
    array(),
    $sales,
    $stockUsers,
    array('Mijai', 'Levie'),
    array()
);

if (isset($suppressed['sellers']['Mijai']) || isset($suppressed['sellers']['Levie'])) {
    fwrite(STDERR, "deleted historical sellers must not be restored again from old sales or stock comments\n");
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../settings/manage_sellers.php');
foreach (array(
    'deleted account config file' => '$deleted_accounts_file',
    'seller deletion marker' => "mikhmon_store_deleted_account_marker(\$deleted_accounts_file, \$deleted_accounts_data, 'sellers'",
    'offline historical seller deletion' => '$deleteSellerIsHistorical',
    'seller restore suppression' => "mikhmon_deleted_account_session_keys(\$deleted_accounts_data, 'sellers', \$session)",
) as $label => $needle) {
    if (strpos($admin, $needle) === false) {
        fwrite(STDERR, $label . " missing\n");
        exit(1);
    }
}

echo "historical_seller_delete_suppression_test passed\n";
