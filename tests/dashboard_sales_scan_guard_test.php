<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard/home.php');

if (strpos($dashboard, 'mikhmon_income_summary_from_counter_files($API, $clockDayKey)') === false) {
    fwrite(STDERR, "FAIL: Le dashboard doit lire les compteurs de revenus RouterOS.\n");
    exit(1);
}

echo "OK\n";
