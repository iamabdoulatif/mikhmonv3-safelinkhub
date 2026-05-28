<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$week = mikhmon_sales_period_bounds('week', 2026, 5, 22);
if ($week['from'] !== '2026-05-25' || $week['to'] !== '2026-05-31') {
  fwrite(STDERR, 'week 22/2026 bounds expected 2026-05-25 to 2026-05-31' . PHP_EOL);
  exit(1);
}
if (strpos($week['label'], 'Semaine 22') === false) {
  fwrite(STDERR, 'week report label must mention the selected week' . PHP_EOL);
  exit(1);
}

$month = mikhmon_sales_period_bounds('month', 2026, 5, 22);
if ($month['from'] !== '2026-05-01' || $month['to'] !== '2026-05-31' || $month['month_key'] !== 'may2026') {
  fwrite(STDERR, 'month bounds expected may2026 from 2026-05-01 to 2026-05-31' . PHP_EOL);
  exit(1);
}

$year = mikhmon_sales_period_bounds('year', 2026, 5, 22);
if ($year['from'] !== '2026-01-01' || $year['to'] !== '2026-12-31') {
  fwrite(STDERR, 'year bounds expected 2026-01-01 to 2026-12-31' . PHP_EOL);
  exit(1);
}

$sales = array(
  array('date' => 'may/25/2026', 'time' => '08:00:00', 'user' => 'A1', 'price' => '1000', 'profile' => '01-JOUR', 'comment' => 'lot-aicha'),
  array('date' => 'may/31/2026', 'time' => '09:00:00', 'user' => 'A2', 'price' => '1000', 'profile' => '01-JOUR', 'comment' => 'lot-aicha'),
  array('date' => 'jun/01/2026', 'time' => '10:00:00', 'user' => 'A3', 'price' => '1000', 'profile' => '01-JOUR', 'comment' => 'lot-aicha'),
);

$filtered = mikhmon_filter_sale_scripts_by_iso_range($sales, $week['from'], $week['to']);
if (count($filtered) !== 2) {
  fwrite(STDERR, 'week report must keep only sales inside the selected ISO range' . PHP_EOL);
  exit(1);
}

echo "mikhmon_sales_period_report_test passed\n";
