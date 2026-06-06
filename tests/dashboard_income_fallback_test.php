<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class DashboardIncomeFallbackApiStub
{
    public $queries = array();
    private $counterValues;
    private $sales;
    private $profiles;
    private $users;

    public function __construct($counterValues = array(), $sales = array(), $profiles = array(), $users = array())
    {
        $this->counterValues = $counterValues;
        $this->sales = $sales;
        $this->profiles = $profiles;
        $this->users = $users;
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

        if ($path === '/system/script/print') {
            return $this->sales;
        }

        if ($path === '/ip/hotspot/user/profile/print') {
            return $this->profiles;
        }

        if ($path === '/ip/hotspot/user/print') {
            return $this->users;
        }

        return array();
    }
}

$missingCountersApi = new DashboardIncomeFallbackApiStub(
    array(),
    array(),
    array(
        array('name' => '01-JOUR', 'on-login' => ':put (",remc,200,1d,200,,Disable,");'),
        array('name' => '05-JOURS', 'on-login' => ':put (",remc,500,5d,500,,Disable,");'),
    ),
    array(
        array('name' => 'u1', 'profile' => '01-JOUR', 'comment' => 'jun/06/2026 12:00:00'),
        array('name' => 'u2', 'profile' => '05-JOURS', 'comment' => 'jun/10/2026 08:00:00'),
        array('name' => 'unused', 'profile' => '01-JOUR', 'comment' => 'vc-123-06.06.26-01-JOUR'),
    )
);
$summary = mikhmon_dashboard_income_summary($missingCountersApi, 'jun/05/2026');
if ($summary['today_count'] !== 2 || $summary['today_total'] !== 700.0
    || $summary['month_count'] !== 2 || $summary['month_total'] !== 700.0) {
    fwrite(STDERR, "dashboard must estimate income from used hotspot users when counters and sale scripts are empty\n");
    exit(1);
}

$sawUserFallback = false;
foreach ($missingCountersApi->queries as $query) {
    if ($query[0] === '/ip/hotspot/user/print') {
        $sawUserFallback = true;
    }
}
if (!$sawUserFallback) {
    fwrite(STDERR, "dashboard must read hotspot users for the fallback estimate\n");
    exit(1);
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

$partialCounterValues = array(
    'mikhmon-income-day-jun2026-05-count.txt' => '0',
    'mikhmon-income-day-jun2026-05-total.txt' => '0',
    'mikhmon-income-month-jun2026-count.txt' => '12',
    'mikhmon-income-month-jun2026-total.txt' => '18000',
);
$partialCountersApi = new DashboardIncomeFallbackApiStub(
    $partialCounterValues,
    array(),
    array(array('name' => '01-JOUR', 'on-login' => ':put (",remc,200,1d,200,,Disable,");')),
    array(array('name' => 'u1', 'profile' => '01-JOUR', 'comment' => 'jun/06/2026 12:00:00'))
);
$summary = mikhmon_dashboard_income_summary($partialCountersApi, 'jun/05/2026');
if ($summary['today_count'] !== 1 || $summary['today_total'] !== 200.0
    || $summary['month_count'] !== 12 || $summary['month_total'] !== 18000.0) {
    fwrite(STDERR, "dashboard must fill empty daily counters from used vouchers while preserving monthly counters\n");
    exit(1);
}

foreach ($availableCountersApi->queries as $query) {
    if ($query[0] === '/system/script/print' || $query[0] === '/ip/hotspot/user/print') {
        fwrite(STDERR, "dashboard must not run fallback scans when counters contain data\n");
        exit(1);
    }
}

echo "dashboard_income_fallback_test passed\n";
