<?php
session_id('ip-binding-duration-submit-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['mikhmon'] = 'mikhmon';
$_SESSION['_csrf'] = 'ip-binding-duration-submit-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    '_csrf' => 'ip-binding-duration-submit-token',
    'add_ip_binding_duration' => '1',
    'binding_mac' => 'aa:bb:cc:dd:ee:ff',
    'binding_address' => '10.10.0.44',
    'binding_to_address' => '',
    'binding_server' => 'hotspot1',
    'binding_type' => 'bypassed',
    'binding_profile' => '1H',
    'binding_duration' => '1h',
    'binding_note' => 'client test',
);

$session = 'BAM-TECH';
$currency = 'XOF';
$cekindo = array('indo' => array());
$_ip_bindings = 'IP Bindings';
$_name = 'Name';
$_profile = 'Profile';

require_once __DIR__ . '/../include/mikhmon_compat.php';

class IpBindingDurationSubmitApiStub {
    public $calls = array();

    public function comm($path, $params = array()) {
        $this->calls[] = array($path, $params);
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(array('name' => '1H', 'on-login' => mikhmon_build_on_login_script('rem', '100', '1h', '100', 'Disable', '', '')));
        }
        if ($path === '/ip/hotspot/print') {
            return array(array('name' => 'hotspot1'));
        }
        if ($path === '/ip/hotspot/ip-binding/print' && isset($params['count-only'])) {
            return 0;
        }
        if ($path === '/ip/hotspot/ip-binding/print') {
            return array();
        }
        if ($path === '/system/scheduler/print') {
            return array();
        }
        return array();
    }
}

$API = new IpBindingDurationSubmitApiStub();

ob_start();
include __DIR__ . '/../hotspot/ipbinding.php';
ob_get_clean();

$bindingAdd = null;
$schedulerAdd = null;
foreach ($API->calls as $call) {
    if ($call[0] === '/ip/hotspot/ip-binding/add') {
        $bindingAdd = $call[1];
    }
    if ($call[0] === '/system/scheduler/add') {
        $schedulerAdd = $call[1];
    }
}

if (!$bindingAdd || !$schedulerAdd) {
    fwrite(STDERR, 'binding add or scheduler add was not called' . PHP_EOL);
    exit(1);
}

if ($bindingAdd['mac-address'] !== 'AA:BB:CC:DD:EE:FF' || $bindingAdd['address'] !== '10.10.0.44') {
    fwrite(STDERR, 'binding add used unexpected mac/address' . PHP_EOL);
    exit(1);
}

if (strpos($bindingAdd['comment'], 'profile=1H') === false || strpos($bindingAdd['comment'], 'validity=1h') === false) {
    fwrite(STDERR, 'binding comment does not include profile validity metadata' . PHP_EOL);
    exit(1);
}

if ($schedulerAdd['interval'] !== '1h' || $schedulerAdd['comment'] !== 'mikhmon-ipbinding-expire') {
    fwrite(STDERR, 'scheduler add used unexpected interval/comment' . PHP_EOL);
    exit(1);
}

if (strpos($schedulerAdd['on-event'], '/ip hotspot ip-binding remove') === false) {
    fwrite(STDERR, 'scheduler on-event does not remove the binding' . PHP_EOL);
    exit(1);
}

echo "ip_binding_duration_submit_test passed\n";
