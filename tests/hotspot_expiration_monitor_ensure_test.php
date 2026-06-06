<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class HotspotExpirationMonitorEnsureApiStub
{
    public $calls = array();

    public function comm($path, $params = array())
    {
        $this->calls[] = array($path, $params);
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(
                array(
                    '.id' => '*1',
                    'name' => '01-JOUR',
                    'on-login' => mikhmon_build_on_login_script(
                        'remc',
                        '500',
                        '1d',
                        '500',
                        'Disable',
                        mikhmon_build_record_script('500', '1d', '01-JOUR'),
                        ''
                    ),
                ),
                array(
                    '.id' => '*2',
                    'name' => 'Illimite',
                    'on-login' => ':put (",0,0,,0,,Disable,")',
                ),
            );
        }
        if ($path === '/system/scheduler/print') {
            return array();
        }
        return array();
    }
}

$api = new HotspotExpirationMonitorEnsureApiStub();
$ensured = mikhmon_ensure_expiration_profile_monitors($api);
if ($ensured !== 1) {
    fwrite(STDERR, "exactly one expiring profile monitor must be ensured\n");
    exit(1);
}

$schedulerAdds = array();
foreach ($api->calls as $call) {
    if ($call[0] === '/system/scheduler/add') {
        $schedulerAdds[] = $call[1];
    }
}

if (count($schedulerAdds) !== 1) {
    fwrite(STDERR, "missing scheduler add for already upgraded duration profile\n");
    exit(1);
}

$scheduler = $schedulerAdds[0];
if ($scheduler['name'] !== '01-JOUR'
    || $scheduler['interval'] !== '00:00:30'
    || $scheduler['comment'] !== 'Monitor Profile 01-JOUR'
    || strpos($scheduler['on-event'], 'profile="01-JOUR"') === false) {
    fwrite(STDERR, "expiration monitor scheduler payload is invalid\n");
    exit(1);
}

class HotspotExpirationMonitorCurrentApiStub extends HotspotExpirationMonitorEnsureApiStub
{
    public function comm($path, $params = array())
    {
        $this->calls[] = array($path, $params);
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(
                array(
                    '.id' => '*1',
                    'name' => '01-JOUR',
                    'on-login' => mikhmon_build_on_login_script(
                        'remc',
                        '500',
                        '1d',
                        '500',
                        'Disable',
                        mikhmon_build_record_script('500', '1d', '01-JOUR'),
                        ''
                    ),
                ),
            );
        }
        if ($path === '/system/scheduler/print') {
            return array(array(
                '.id' => '*scheduler',
                'name' => '01-JOUR',
                'interval' => '30s',
                'disabled' => 'false',
                'comment' => 'Monitor Profile 01-JOUR',
                'on-event' => mikhmon_build_expire_monitor_script('01-JOUR', 'remove'),
            ));
        }
        return array();
    }
}

$currentApi = new HotspotExpirationMonitorCurrentApiStub();
$currentEnsured = mikhmon_ensure_expiration_profile_monitors($currentApi);
if ($currentEnsured !== 0) {
    fwrite(STDERR, "current RouterOS-normalized monitor interval must not be rewritten\n");
    exit(1);
}

foreach ($currentApi->calls as $call) {
    if ($call[0] === '/system/scheduler/set') {
        fwrite(STDERR, "current monitor scheduler must not be reset on every dashboard refresh\n");
        exit(1);
    }
}

echo "hotspot_expiration_monitor_ensure_test passed\n";
