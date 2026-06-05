<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard/home.php');

if (strpos($dashboard, 'mikhmon_dashboard_income_summary($API, $clockDayKey)') === false) {
    fwrite(STDERR, "FAIL: Le dashboard doit lire les compteurs avec repli sur les ventes RouterOS.\n");
    exit(1);
}

echo "OK\n";
