<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard/home.php');

if (strpos($dashboard, '$reportMonthlySales = mikhmon_fetch_sales_by_month($API, $currentMonthKey);') === false
    || strpos($dashboard, 'mikhmon_dashboard_income_summary($API, $clockDayKey, $reportMonthlySales)') === false) {
    fwrite(STDERR, "FAIL: Le revenu dashboard doit suivre les ventes du rapport mensuel.\n");
    exit(1);
}

echo "OK\n";
