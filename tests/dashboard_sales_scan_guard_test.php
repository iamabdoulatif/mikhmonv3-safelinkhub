<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard/home.php');
$sellingReport = file_get_contents(__DIR__ . '/../report/selling.php');
$printReport = file_get_contents(__DIR__ . '/../report/print.php');

if (strpos($dashboard, '$reportMonthlySales = mikhmon_fetch_sales_by_month($API, $currentMonthKey);') === false
    || strpos($dashboard, 'mikhmon_dashboard_income_summary($API, $clockDayKey, $reportMonthlySales)') === false) {
    fwrite(STDERR, "FAIL: Le revenu dashboard doit suivre les ventes du rapport mensuel.\n");
    exit(1);
}

if (strpos($dashboard, '$currentMonthTag = mikhmon_sale_month_key($clockDayKey);') === false
    || strpos($dashboard, '$attributedMonthlySales = mikhmon_fetch_mikhmon_sale_scripts($API);') === false
    || strpos($dashboard, 'strtolower(date("M")) . date("Y")') !== false) {
    fwrite(STDERR, "FAIL: L'analyse vendeurs doit utiliser le mois routeur et la lecture compatible des ventes.\n");
    exit(1);
}

if (strpos($sellingReport, 'mikhmon_fetch_sales_by_month($API, $idbl, $monthCutoff)') === false
    || strpos($printReport, 'mikhmon_fetch_sales_by_month($API, $idbl, $monthCutoff)') === false) {
    fwrite(STDERR, "FAIL: Le rapport mensuel doit utiliser la meme borne que le revenu mensuel.\n");
    exit(1);
}

echo "OK\n";
