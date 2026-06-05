<?php

$seller = file_get_contents(__DIR__ . '/../sellers.php');
if ($seller === false) {
    fwrite(STDERR, "could not read sellers.php\n");
    exit(1);
}

foreach (array(
    '$sellerDisplayIdentity',
    'strlen($sellerDisplayIdentity) > 64',
    "strpos(\$sellerDisplayIdentity, '-|-') !== false",
    'htmlspecialchars($sellerDisplayIdentity)',
) as $needle) {
    if (strpos($seller, $needle) === false) {
        fwrite(STDERR, "seller router identity guard missing: {$needle}\n");
        exit(1);
    }
}

echo "seller_router_identity_guard_test passed\n";
