<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manager === false || $portalCss === false) {
  fwrite(STDERR, "could not read manager overview period files\n");
  exit(1);
}

$overviewStart = strpos($manager, "<?php elseif (\$action === 'overview'): ?>");
$overviewEnd = strpos($manager, "<?php elseif (\$action === 'accounting'): ?>", $overviewStart === false ? 0 : $overviewStart);
if ($overviewStart === false || $overviewEnd === false || $overviewEnd <= $overviewStart) {
  fwrite(STDERR, "could not isolate manager overview block\n");
  exit(1);
}

$overview = substr($manager, $overviewStart, $overviewEnd - $overviewStart);

foreach (array(
  'period form' => 'manager-overview-period-form',
  'period selector' => 'name="period"',
  'week option' => 'value="week"',
  'month option' => 'value="month"',
  'year option' => 'value="year"',
  'week field' => 'name="week"',
  'year field' => 'name="year"',
  'period total label' => '$overviewReportPeriodLabel',
  'period CA label' => 'CA période',
) as $label => $needle) {
  if (strpos($overview, $needle) === false) {
    fwrite(STDERR, $label . " missing from manager overview period filter\n");
    exit(1);
  }
}

foreach (array(
  '$overviewReportBounds',
  'mikhmon_filter_sale_scripts_by_iso_range($getSales, $overviewReportFromIso, $overviewReportToIso)',
) as $needle) {
  if (strpos($manager, $needle) === false) {
    fwrite(STDERR, "manager overview must use period report bounds: " . $needle . "\n");
    exit(1);
  }
}

foreach (array('.manager-overview-period-form', '.manager-overview-period-form .portal-filter-item') as $cssHook) {
  if (strpos($portalCss, $cssHook) === false) {
    fwrite(STDERR, "manager overview period CSS missing: " . $cssHook . "\n");
    exit(1);
  }
}

echo "manager_overview_period_filter_test passed\n";
