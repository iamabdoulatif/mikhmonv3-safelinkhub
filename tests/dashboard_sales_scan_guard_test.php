<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard/home.php');
$sellingReport = file_get_contents(__DIR__ . '/../report/selling.php');
$printReport = file_get_contents(__DIR__ . '/../report/print.php');

if (strpos($dashboard, '$reportMonthlySales = mikhmon_dashboard_sales_for_month($API, $currentMonthKey, $clockDayKey, $iphost, $userhost, $passwdhost);') === false
    || strpos($dashboard, '$incomeSummary = mikhmon_income_summary_from_scripts($reportMonthlySales, $clockDayKey, $currentMonthKey);') === false) {
    fwrite(STDERR, "FAIL: Le revenu dashboard doit suivre les ventes du rapport mensuel.\n");
    exit(1);
}

if (strpos($dashboard, '$currentMonthTag = mikhmon_sale_month_key($clockDayKey);') === false
    || strpos($dashboard, ': mikhmon_dashboard_sales_for_month($API, $currentMonthTag, $currentDayTag, $iphost, $userhost, $passwdhost);') === false
    || strpos($dashboard, "mikhmon_comm_with_reconnect(\$API, '/ip/hotspot/user/print'") === false
    || strpos($dashboard, '$attributedMonthlySales = mikhmon_fetch_mikhmon_sale_scripts($API);') !== false
    || strpos($dashboard, 'strtolower(date("M")) . date("Y")') !== false) {
    fwrite(STDERR, "FAIL: L'analyse vendeurs doit utiliser le mois routeur et la lecture compatible des ventes.\n");
    exit(1);
}

if (strpos($sellingReport, 'mikhmon_fetch_sales_by_month_index($API, $idbl, $monthCutoff, false)') === false
    || strpos($printReport, 'mikhmon_report_sales_for_month($API, $idbl, $monthCutoff)') === false) {
    fwrite(STDERR, "FAIL: Le rapport mensuel affiche doit utiliser l'index rapide, l'impression garde le fallback historique.\n");
    exit(1);
}

echo "OK\n";
