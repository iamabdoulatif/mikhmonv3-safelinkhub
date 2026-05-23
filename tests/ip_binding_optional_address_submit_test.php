<?php
session_id('ip-binding-optional-address-submit-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['mikhmon'] = 'mikhmon';
$_SESSION['_csrf'] = 'ip-binding-optional-address-submit-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    '_csrf' => 'ip-binding-optional-address-submit-token',
    'add_ip_binding_duration' => '1',
    'binding_mac' => 'aa:bb:cc:dd:ee:ff',
    'binding_address' => '',
    'binding_to_address' => '',
    'binding_server' => 'all',
    'binding_type' => 'bypassed',
    'binding_profile' => '1D',
    'binding_duration' => '1d',
    'binding_note' => 'sans adresse',
);

$session = 'BAM-TECH';
$currency = 'XOF';
$cekindo = array('indo' => array());
$_ip_bindings = 'IP Bindings';
$_name = 'Name';
$_profile = 'Profile';

require_once __DIR__ . '/../include/mikhmon_compat.php';

class IpBindingOptionalAddressSubmitApiStub {
    public $calls = array();

    public function comm($path, $params = array()) {
        $this->calls[] = array($path, $params);
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(array('name' => '1D', 'on-login' => mikhmon_build_on_login_script('rem', '100', '1d', '100', 'Disable', '', '')));
        }
        if ($path === '/ip/hotspot/print') {
            return array();
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

$API = new IpBindingOptionalAddressSubmitApiStub();

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

if (array_key_exists('address', $bindingAdd) || array_key_exists('to-address', $bindingAdd)) {
    fwrite(STDERR, 'empty address fields must not be sent to RouterOS' . PHP_EOL);
    exit(1);
}

if (strpos($schedulerAdd['on-event'], 'mac-address="AA:BB:CC:DD:EE:FF"') === false) {
    fwrite(STDERR, 'scheduler should remove binding by MAC when address is empty' . PHP_EOL);
    exit(1);
}

if (strpos($schedulerAdd['on-event'], ' and address=') !== false) {
    fwrite(STDERR, 'scheduler must not include address filter when address is empty' . PHP_EOL);
    exit(1);
}

echo "ip_binding_optional_address_submit_test passed\n";
