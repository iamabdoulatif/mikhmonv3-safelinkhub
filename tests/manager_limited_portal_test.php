<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$index = file_get_contents($root . '/index.php');

if ($manager === false || $index === false) {
    fwrite(STDERR, "could not read manager portal files\n");
    exit(1);
}

$required = array(
    'limited action list' => '$managerAllowedActions',
    'connected count variable' => '$managerConnectedCount',
    'active hotspot api call' => '/ip/hotspot/active/print',
    'connected users card' => 'Connectés maintenant',
    'ticket workspace card' => 'Générer &amp; imprimer',
    'vendor sales overview' => 'Ventes des vendeurs',
    'overview route allowed' => "\$managerAllowedActions = array('dashboard', 'overview', 'accounting', 'tickets', 'logout')",
    'manager seller accounting right' => 'Compte vendeur',
    'manager accounting route url' => "\$managerAccountingUrl = './manager.php?action=accounting&idbl='",
    'manager dashboard redirect for denied routes' => 'manager.php?action=dashboard',
);

foreach ($required as $label => $needle) {
    if (strpos($manager, $needle) === false && strpos($index, $needle) === false) {
        fwrite(STDERR, $label . " missing\n");
        exit(1);
    }
}

$forbiddenManagerNav = array(
    'manager transfer nav' => 'href="./manager.php?action=transfer"',
    'manager vendors nav' => 'href="./manager.php?action=vendors"',
    'manager logs nav' => 'href="./manager.php?action=logs"',
    'manager admin user list links' => 'href="./?hotspot=users',
    'manager admin profile list links' => 'hotspot=users-by-profile',
);

foreach ($forbiddenManagerNav as $label => $needle) {
    if (strpos($manager, $needle) !== false) {
        fwrite(STDERR, $label . " must not be exposed\n");
        exit(1);
    }
}

$dashboardStart = strpos($manager, "<?php elseif (\$action === 'dashboard'): ?>");
$dashboardEnd = strpos($manager, "<?php elseif (\$action === 'overview'): ?>");
if ($dashboardStart === false || $dashboardEnd === false || $dashboardEnd <= $dashboardStart) {
    fwrite(STDERR, "could not isolate manager dashboard block\n");
    exit(1);
}
$dashboard = substr($manager, $dashboardStart, $dashboardEnd - $dashboardStart);
$forbiddenDashboardRevenue = array(
    'chiffre d’affaires',
    'revenu',
    'commission',
    'mikhmon_format_money_amount',
    'managerTodayRevenue',
    'managerMonthRevenue',
);

foreach ($forbiddenDashboardRevenue as $needle) {
    if (stripos($dashboard, $needle) !== false) {
        fwrite(STDERR, "manager dashboard must not show revenue detail: " . $needle . PHP_EOL);
        exit(1);
    }
}

echo "manager_limited_portal_test passed\n";
