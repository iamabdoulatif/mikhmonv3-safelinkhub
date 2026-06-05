<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class DashboardIncomeFallbackApiStub
{
    public $queries = array();
    private $counterValues;

    public function __construct($counterValues = array())
    {
        $this->counterValues = $counterValues;
    }

    public function comm($path, $params = array())
    {
        $this->queries[] = array($path, $params);
        if ($path === '/file/print') {
            $name = isset($params['?name']) ? $params['?name'] : '';
            return isset($this->counterValues[$name])
                ? array(array('name' => $name, 'contents' => $this->counterValues[$name]))
                : array();
        }

        if ($path === '/system/scheduler/print') {
            return array(array('.id' => '*cache', 'name' => 'mikhmon-income-cache'));
        }

        return array();
    }
}

$missingCountersApi = new DashboardIncomeFallbackApiStub();
$summary = mikhmon_dashboard_income_summary($missingCountersApi, 'jun/05/2026');
if ($summary['today_count'] !== 0 || $summary['today_total'] !== 0.0
    || $summary['month_count'] !== 0 || $summary['month_total'] !== 0.0) {
    fwrite(STDERR, "dashboard must keep empty counters until the asynchronous RouterOS cache refresh\n");
    exit(1);
}

foreach ($missingCountersApi->queries as $query) {
    if ($query[0] === '/system/script/print') {
        fwrite(STDERR, "dashboard request must never scan sale scripts directly\n");
        exit(1);
    }
}

$counterValues = array(
    'mikhmon-income-day-jun2026-05-count.txt' => '3',
    'mikhmon-income-day-jun2026-05-total.txt' => '4500',
    'mikhmon-income-month-jun2026-count.txt' => '12',
    'mikhmon-income-month-jun2026-total.txt' => '18000',
);
$availableCountersApi = new DashboardIncomeFallbackApiStub($counterValues);
$summary = mikhmon_dashboard_income_summary($availableCountersApi, 'jun/05/2026');
if ($summary['today_count'] !== 3 || $summary['today_total'] !== 4500.0
    || $summary['month_count'] !== 12 || $summary['month_total'] !== 18000.0) {
    fwrite(STDERR, "dashboard must prefer available income counters\n");
    exit(1);
}

foreach ($availableCountersApi->queries as $query) {
    if ($query[0] === '/system/script/print') {
        fwrite(STDERR, "dashboard must not scan sale scripts when counters contain data\n");
        exit(1);
    }
}

echo "dashboard_income_fallback_test passed\n";
