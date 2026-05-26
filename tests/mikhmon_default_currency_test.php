<?php

$settings = file_get_contents(__DIR__ . '/../settings/settings.php');

if (strpos($settings, "\$currency = 'fcfa';") === false) {
    fwrite(STDERR, "new router drafts must default to fcfa currency\n");
    exit(1);
}

if (strpos($settings, "\$currency = 'Rp';") !== false) {
    fwrite(STDERR, "new router drafts must not default to Rp currency\n");
    exit(1);
}

echo "mikhmon_default_currency_test passed\n";

