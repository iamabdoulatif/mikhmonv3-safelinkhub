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

$reportSales = array(
    array(
        'name' => 'jun/05/2026-|-10:00:00-|-u1-|-200-|-10.0.0.2-|-AA-|-1d-|-01-JOUR-|-vc-1',
        'source' => 'jun/05/2026',
        'owner' => 'jun2026',
        'comment' => 'mikhmon',
    ),
    array(
        'name' => 'jun/06/2026-|-11:00:00-|-u2-|-500-|-10.0.0.3-|-BB-|-5d-|-05-JOURS-|-vc-2',
        'source' => 'jun/06/2026',
        'owner' => 'jun2026',
        'comment' => 'mikhmon',
    ),
    array(
        'name' => 'jun/14/2026-|-11:00:00-|-future-|-900-|-10.0.0.4-|-CC-|-1d-|-01-JOUR-|-vc-future',
        'source' => 'jun/14/2026',
        'owner' => 'jun2026',
        'comment' => 'mikhmon',
    ),
);
$summary = mikhmon_dashboard_income_summary(new DashboardIncomeFallbackApiStub($counterValues), 'jun/05/2026', $reportSales);
if ($summary['today_count'] !== 1 || $summary['today_total'] !== 200.0
    || $summary['month_count'] !== 1 || $summary['month_total'] !== 200.0) {
    fwrite(STDERR, "dashboard revenue must follow the selling report when report rows are available\n");
    exit(1);
}
$summary = mikhmon_dashboard_income_summary(new DashboardIncomeFallbackApiStub($counterValues), 'jun/13/2026', $reportSales);
if ($summary['month_count'] !== 2 || $summary['month_total'] !== 700.0) {
    fwrite(STDERR, "dashboard monthly revenue must stop at the current router day\n");
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

$zeroedCounterValues = array(
    'mikhmon-income-day-jun2026-05-count.txt' => '0',
    'mikhmon-income-day-jun2026-05-total.txt' => '0',
    'mikhmon-income-month-jun2026-count.txt' => '0',
    'mikhmon-income-month-jun2026-total.txt' => '0',
);
$zeroedCountersApi = new DashboardIncomeFallbackApiStub(
    $zeroedCounterValues,
    array(),
    array(
        array('name' => '01-JOUR', 'on-login' => ':put (",remc,200,1d,200,,Disable,");'),
        array('name' => '05-JOURS', 'on-login' => ':put (",remc,500,5d,500,,Disable,");'),
    ),
    array(
        array('name' => 'u1', 'profile' => '01-JOUR', 'comment' => 'jun/06/2026 12:00:00'),
        array('name' => 'u2', 'profile' => '05-JOURS', 'comment' => 'jun/10/2026 08:00:00'),
    )
);
$summary = mikhmon_dashboard_income_summary($zeroedCountersApi, 'jun/05/2026');
if ($summary['today_count'] !== 2 || $summary['today_total'] !== 700.0
    || $summary['month_count'] !== 2 || $summary['month_total'] !== 700.0) {
    fwrite(STDERR, "dashboard must recover from zeroed cache files after refresh\n");
    exit(1);
}
foreach ($zeroedCountersApi->queries as $query) {
    if ($query[0] === '/system/script/print') {
        fwrite(STDERR, "dashboard zero-cache recovery must avoid script scans that break RouterOS follow-up reads\n");
        exit(1);
    }
}

foreach ($availableCountersApi->queries as $query) {
    if ($query[0] === '/system/script/print' || $query[0] === '/ip/hotspot/user/print') {
        fwrite(STDERR, "dashboard must not run fallback scans when counters contain data\n");
        exit(1);
    }
}

$indexedSalesFallbackApi = new DashboardIncomeFallbackApiStub(
    array(),
    array(
        array(
            'name' => 'jun/06/2026-|-10:00:00-|-u1-|-500-|-10.0.0.2-|-AA-|-5d-|-05-JOURS-|-vc-1',
            'source' => 'jun/06/2026',
            'owner' => 'jun2026',
            'comment' => 'mikhmon',
        ),
        array(
            'name' => 'jun/05/2026-|-11:00:00-|-u2-|-200-|-10.0.0.3-|-BB-|-1d-|-01-JOUR-|-vc-2',
            'source' => 'jun/05/2026',
            'owner' => 'jun2026',
            'comment' => 'mikhmon',
        ),
    )
);
$rows = mikhmon_fetch_sales_by_day($indexedSalesFallbackApi, 'jun/06/2026');
if (count($rows) !== 1 || mikhmon_parse_sale_script($rows[0])['user'] !== 'u1') {
    fwrite(STDERR, "daily reports must fall back to comment=mikhmon when source indexes are empty\n");
    exit(1);
}
$rows = mikhmon_fetch_sales_by_month($indexedSalesFallbackApi, 'jun2026');
if (count($rows) !== 2) {
    fwrite(STDERR, "monthly reports must fall back to comment=mikhmon when owner indexes are empty\n");
    exit(1);
}
foreach ($indexedSalesFallbackApi->queries as $query) {
    if ($query[0] === '/system/script/print'
        && (isset($query[1]['?source']) || isset($query[1]['?owner']))) {
        fwrite(STDERR, "report helpers must avoid RouterOS source/owner filters because empty filters break follow-up reads\n");
        exit(1);
    }
}

$usedUsersReportApi = new DashboardIncomeFallbackApiStub(
    array(),
    array(),
    array(
        array('name' => '01-JOUR', 'on-login' => ':put (",remc,200,1d,200,,Disable,");'),
        array('name' => '05-JOURS', 'on-login' => ':put (",remc,500,5d,500,,Disable,");'),
    ),
    array(
        array('name' => 'u1', 'profile' => '01-JOUR', 'comment' => 'jun/07/2026 08:00:00'),
        array('name' => 'u2', 'profile' => '05-JOURS', 'comment' => 'jun/11/2026 09:30:00'),
        array('name' => 'future', 'profile' => '01-JOUR', 'comment' => 'jun/08/2026 08:00:00'),
    )
);
$rows = mikhmon_fetch_sales_by_day($usedUsersReportApi, 'jun/06/2026');
if (count($rows) !== 2) {
    fwrite(STDERR, "daily reports must be reconstructed from used hotspot users when sale scripts are missing\n");
    exit(1);
}
$summary = mikhmon_income_summary_from_scripts($rows, 'jun/06/2026', 'jun2026');
if ($summary['today_count'] !== 2 || $summary['today_total'] !== 700.0) {
    fwrite(STDERR, "reconstructed daily report rows must keep profile prices\n");
    exit(1);
}

$emptyUsedUsersDayApi = new DashboardIncomeFallbackApiStub(
    array(),
    array(
        array(
            'name' => 'jun/07/2026-|-12:00:00-|-legacy-|-999-|-10.0.0.4-|-CC-|-1d-|-01-JOUR-|-vc-legacy',
            'source' => 'jun/07/2026',
            'owner' => 'jun2026',
            'comment' => 'mikhmon',
        ),
    ),
    array(array('name' => '01-JOUR', 'on-login' => ':put (",remc,200,1d,200,,Disable,");')),
    array(array('name' => 'u1', 'profile' => '01-JOUR', 'comment' => 'jun/07/2026 08:00:00'))
);
$rows = mikhmon_fetch_sales_by_day($emptyUsedUsersDayApi, 'jun/07/2026');
if (count($rows) !== 0) {
    fwrite(STDERR, "an empty reconstructed day must stay empty instead of mixing in legacy scripts\n");
    exit(1);
}
foreach ($emptyUsedUsersDayApi->queries as $query) {
    if ($query[0] === '/system/script/print') {
        fwrite(STDERR, "empty reconstructed days must not scan scripts because large script scans break follow-up RouterOS reads\n");
        exit(1);
    }
}

echo "dashboard_income_fallback_test passed\n";
