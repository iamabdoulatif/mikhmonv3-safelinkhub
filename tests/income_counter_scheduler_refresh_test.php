<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class IncomeCounterSchedulerRefreshApiStub
{
    public $calls = array();
    public $current = false;

    public function comm($path, $params = array())
    {
        $this->calls[] = array($path, $params);
        if ($path === '/system/scheduler/print') {
            if ($this->current) {
                return array(array(
                    '.id' => '*cache',
                    'name' => 'mikhmon-income-cache',
                    'interval' => '15m',
                    'on-event' => mikhmon_income_counter_scheduler_source(),
                    'disabled' => 'false',
                ));
            }
            return array(array('.id' => '*cache', 'name' => 'mikhmon-income-cache'));
        }
        return array();
    }
}

$api = new IncomeCounterSchedulerRefreshApiStub();
if (!mikhmon_ensure_income_counter_scheduler($api)) {
    fwrite(STDERR, "existing income cache scheduler must be refreshed\n");
    exit(1);
}

$set = null;
foreach ($api->calls as $call) {
    if ($call[0] === '/system/scheduler/set') {
        $set = $call[1];
        break;
    }
}
if (!$set || $set['.id'] !== '*cache' || $set['interval'] !== '15m'
    || $set['on-event'] !== mikhmon_income_counter_scheduler_source()) {
    fwrite(STDERR, "income cache scheduler must be updated to the current source every 15 minutes\n");
    exit(1);
}

$currentApi = new IncomeCounterSchedulerRefreshApiStub();
$currentApi->current = true;
if (!mikhmon_ensure_income_counter_scheduler($currentApi)) {
    fwrite(STDERR, "current income cache scheduler must be accepted\n");
    exit(1);
}
foreach ($currentApi->calls as $call) {
    if ($call[0] === '/system/scheduler/set') {
        fwrite(STDERR, "current income cache scheduler must not be reset on every dashboard refresh\n");
        exit(1);
    }
}

echo "income_counter_scheduler_refresh_test passed\n";
