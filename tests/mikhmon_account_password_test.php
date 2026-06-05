<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$plain = 'Secret-123';
$hash = password_hash($plain, PASSWORD_DEFAULT);

if (!mikhmon_account_password_matches($plain, $hash)) {
    fwrite(STDERR, 'restored password hashes must authenticate without plaintext recovery' . PHP_EOL);
    exit(1);
}

if (mikhmon_account_password_matches('wrong', $hash)) {
    fwrite(STDERR, 'restored password hashes must reject invalid credentials' . PHP_EOL);
    exit(1);
}

if (mikhmon_account_password_storage($hash) !== $hash) {
    fwrite(STDERR, 'password hashes must not be encrypted a second time during reconstruction' . PHP_EOL);
    exit(1);
}

$managerPage = file_get_contents(__DIR__ . '/../manager.php');
$sellerPage = file_get_contents(__DIR__ . '/../sellers.php');
if (strpos($managerPage, 'mikhmon_account_password_matches($mp, $managers_data[$mu][\'password\'])') === false
    || strpos($sellerPage, 'mikhmon_account_password_matches($sp, $sellers_data[$su][\'password\'])') === false) {
    fwrite(STDERR, 'seller and manager login must support reconstructed password hashes' . PHP_EOL);
    exit(1);
}

echo "mikhmon_account_password_test passed\n";
