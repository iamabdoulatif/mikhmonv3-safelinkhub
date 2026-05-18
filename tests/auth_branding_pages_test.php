<?php
$pages = array(
    'include/login.php' => file_get_contents(__DIR__ . '/../include/login.php'),
    'manager.php' => file_get_contents(__DIR__ . '/../manager.php'),
    'sellers.php' => file_get_contents(__DIR__ . '/../sellers.php'),
);

$checks = array(
    'brand subtitle' => 'MIKHMON <small class="login-logo-subtitle">BY SafeLink Africa</small>',
    'brand phone' => '<div class="login-logo-contact">+2250709100552</div>',
    'brand footer logo' => '<div class="card-footer login-footer">',
    'safelink logo image' => '<img src="img/safelink-africa.png" alt="SafeLink Africa">',
);

foreach ($pages as $page => $source) {
    if ($source === false) {
        fwrite(STDERR, "could not read $page" . PHP_EOL);
        exit(1);
    }
    foreach ($checks as $label => $needle) {
        if (strpos($source, $needle) === false) {
            fwrite(STDERR, "$page missing $label" . PHP_EOL);
            exit(1);
        }
    }
}

echo "auth_branding_pages_test passed\n";
