<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');

if ($manager === false || $responsive === false) {
    fwrite(STDERR, "could not read manager accounting responsive files\n");
    exit(1);
}

if (strpos($manager, "\$managerAllowedActions = array('dashboard', 'tickets', 'logout')") === false) {
    fwrite(STDERR, "manager accounting route must be hidden from the manager portal\n");
    exit(1);
}

$cssChecks = array(
    'mobile accounting shell rule' => '.manager-portal .mgr-accounting-shell',
    'mobile accounting form rule' => '.manager-portal .mgr-accounting-form',
    'mobile accounting actions rule' => '.manager-portal .mgr-accounting-actions',
    'two column tablet accounting form' => 'grid-template-columns: repeat(2, minmax(0, 1fr));',
    'single column phone accounting form' => 'grid-template-columns: minmax(0, 1fr);',
    'full width accounting buttons' => '.manager-portal .mgr-accounting-actions .btn',
);

foreach ($cssChecks as $label => $needle) {
    if (strpos($responsive, $needle) === false) {
        fwrite(STDERR, $label . " missing from mikhmon-responsive.css\n");
        exit(1);
    }
}

echo "manager_accounting_responsive_test passed\n";
