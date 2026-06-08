<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

class MikhmonLegacyMonthSalesFakeApi
{
  public $calls = array();

  public function comm($path, $params = array())
  {
    $this->calls[] = array($path, $params);
    if ($path === '/system/script/print' && isset($params['?comment']) && $params['?comment'] === 'mikhmon') {
      return array(
        array('.id' => '*recent', 'name' => 'jun/05/2026-|-08:00:00-|-R1-|-1000-|-10.0.0.2-|-AA-|-1h-|-1H-|-lot-alpha', 'owner' => 'jun2026', 'comment' => 'mikhmon'),
        array('.id' => '*old1', 'name' => 'jun/01/2026-|-08:00:00-|-O1-|-500-|-10.0.0.3-|-BB-|-1h-|-1H-|-lot-alpha', 'owner' => '2026-01', 'comment' => 'mikhmon'),
        array('.id' => '*old2', 'name' => 'jun/02/2026-|-09:00:00-|-O2-|-700-|-10.0.0.4-|-CC-|-1h-|-1H-|-lot-beta', 'owner' => '2026-02', 'comment' => 'mikhmon'),
        array('.id' => '*other', 'name' => 'may/31/2026-|-09:00:00-|-X-|-900-|-10.0.0.5-|-DD-|-1h-|-1H-|-lot-beta', 'owner' => '2026-02', 'comment' => 'mikhmon'),
      );
    }

    return array();
  }
}

$api = new MikhmonLegacyMonthSalesFakeApi();
$sales = mikhmon_fetch_sales_by_month($api, 'jun2026');

if (count($sales) !== 3) {
  fwrite(STDERR, 'monthly restore must combine current and legacy RouterOS 7 sale owners' . PHP_EOL);
  exit(1);
}

$ids = array_column($sales, 'user');
sort($ids);
if ($ids !== array('O1', 'O2', 'R1')) {
  fwrite(STDERR, 'monthly restore must keep only sales belonging to the requested month' . PHP_EOL);
  exit(1);
}

$usedOwnerFilters = false;
foreach ($api->calls as $call) {
  if (isset($call[1]['?owner'])) {
    $usedOwnerFilters = true;
  }
}
if ($usedOwnerFilters) {
  fwrite(STDERR, 'monthly restore must avoid RouterOS owner filters that can break follow-up reads' . PHP_EOL);
  exit(1);
}

$restoredSellers = mikhmon_accounting_historical_sellers($sales, 'Safelink', array());
$summary = mikhmon_accounting_period_summary($sales, $restoredSellers, '2026-06-01', '2026-06-30');
if (!isset($restoredSellers['alpha']) || !isset($restoredSellers['beta'])) {
  fwrite(STDERR, 'reinstallation restore must reconstruct sellers from old and recent sale comments' . PHP_EOL);
  exit(1);
}
if ($summary['total']['count'] !== 3 || $summary['total']['revenue'] !== 2200.0) {
  fwrite(STDERR, 'reinstallation restore must rebuild the complete monthly seller totals' . PHP_EOL);
  exit(1);
}

echo "mikhmon_legacy_month_sales_restore_test passed\n";
