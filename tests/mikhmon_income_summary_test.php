<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$sales = array(
  array(
    'name' => 'may/09/2026-|-08:00:00-|-u1-|-100-|-10.0.0.2-|-AA-|-5h-|-05-HEURES-|-batch',
    'source' => 'may/09/2026',
    'owner' => 'may2026',
    'comment' => 'mikhmon',
  ),
  array(
    'name' => 'may/09/2026-|-09:00:00-|-u2-|-200-|-10.0.0.3-|-BB-|-1d-|-01-JOUR-|-batch',
    'source' => '2026-05-09',
    'owner' => 'may2026',
    'comment' => 'mikhmon',
  ),
  array(
    'name' => 'may/08/2026-|-09:00:00-|-u3-|-500-|-10.0.0.4-|-CC-|-5d-|-05-JOURS-|-batch',
    'source' => 'may/08/2026',
    'owner' => 'may2026',
    'comment' => 'mikhmon',
  ),
  array(
    'name' => 'apr/30/2026-|-09:00:00-|-u4-|-700-|-10.0.0.5-|-DD-|-1w-|-01-SEMAINE-|-batch',
    'source' => 'apr/30/2026',
    'owner' => 'apr2026',
    'comment' => 'mikhmon',
  ),
  array(
    'name' => 'may/09/2026-|-10:00:00-|-u5-|-1 500-|-10.0.0.6-|-EE-|-1w-|-VIP-|-batch',
    'source' => 'may/09/2026',
    'owner' => 'may2026',
    'comment' => 'mikhmon',
  ),
  array(
    'name' => 'may/10/2026-|-10:00:00-|-u6-|-1.500-|-10.0.0.7-|-FF-|-1w-|-VIP-|-batch',
    'source' => '2026-05-10',
    'owner' => 'may2026',
    'comment' => 'mikhmon',
  ),
);

$summary = mikhmon_income_summary_from_scripts($sales, 'may/09/2026', 'may2026');

$expected = array(
  'today_count' => 3,
  'today_total' => 1800.0,
  'month_count' => 4,
  'month_total' => 2300.0,
);

foreach ($expected as $key => $value) {
  if ($summary[$key] !== $value) {
    fwrite(STDERR, $key . ' expected ' . $value . ' got ' . $summary[$key] . PHP_EOL);
    exit(1);
  }
}

echo "mikhmon_income_summary_test passed\n";
