<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class HotspotExpirationMigrationApiStub
{
    public $calls = array();

    public function comm($path, $params = array())
    {
        $this->calls[] = array($path, $params);
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(
                array(
                    '.id' => '*1',
                    'name' => '01-HEURE',
                    'on-login' => ':put (",remc,100,1h,100,,Disable,"); legacy',
                ),
                array(
                    '.id' => '*2',
                    'name' => '01-JOUR',
                    'on-login' => mikhmon_build_on_login_script(
                        'remc',
                        '200',
                        '1d',
                        '200',
                        'Disable',
                        mikhmon_build_record_script('200', '1d', '01-JOUR'),
                        ''
                    ),
                ),
                array(
                    '.id' => '*3',
                    'name' => 'Illimite',
                    'on-login' => ':put (",remc,0,Illimite,0,,Disable,"); legacy',
                ),
            );
        }
        if ($path === '/system/scheduler/print') {
            return array(array('.id' => '*scheduler'));
        }
        return array();
    }
}

$api = new HotspotExpirationMigrationApiStub();
$updated = mikhmon_upgrade_legacy_expiration_profiles($api);
if ($updated !== 1) {
    fwrite(STDERR, "exactly one legacy duration profile must be upgraded\n");
    exit(1);
}

$profileSets = array();
$schedulerSets = array();
foreach ($api->calls as $call) {
    if ($call[0] === '/ip/hotspot/user/profile/set') {
        $profileSets[] = $call[1];
    }
    if ($call[0] === '/system/scheduler/set') {
        $schedulerSets[] = $call[1];
    }
}

if (count($profileSets) !== 1 || $profileSets[0]['.id'] !== '*1'
    || strpos($profileSets[0]['on-login'], 'mikhmon-user-expire') === false) {
    fwrite(STDERR, "legacy duration profile must receive the exact expiration scheduler script\n");
    exit(1);
}
if (count($schedulerSets) !== 1 || $schedulerSets[0]['interval'] !== '00:00:30'
    || strpos($schedulerSets[0]['on-event'], 'profile="01-HEURE"') === false) {
    fwrite(STDERR, "legacy duration profile monitor must be refreshed every 30 seconds\n");
    exit(1);
}

echo "hotspot_expiration_profile_migration_test passed\n";
