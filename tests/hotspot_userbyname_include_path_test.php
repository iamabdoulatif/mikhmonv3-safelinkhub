<?php
session_id('hotspot-userbyname-include-path-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SERVER['REQUEST_URI'] = '/mikhmon/?hotspot-user=*204&session=ALBAMBAWY';
$_GET = array(
    'hotspot-user' => '*204',
    'session' => 'ALBAMBAWY',
);
$_POST = array();

$_SESSION['mikhmon'] = 'mikhmon';
$_SESSION['timezone'] = 'UTC';
$_SESSION['ubp'] = '';
$_SESSION['ubc'] = '';
$_SESSION['hua'] = '';
$_SESSION['ubn'] = '*204';

$session = 'ALBAMBAWY';
$hotspotuser = '*204';
$currency = 'XOF';
$cekindo = array('indo' => array());
$hotspotname = 'SafelinkHub';
$dnsname = 'login.safelinkhub.io';
$qrbt = 'disable';

require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../lib/formatbytesbites.php';

class HotspotUserByNameApiStub {
    public function comm($path, $params = array()) {
        if ($path === '/ip/hotspot/user/profile/print' && empty($params)) {
            return array(array(
                'name' => 'default',
                'on-login' => ',,100,1d,150,,Disable',
            ));
        }

        if ($path === '/ip/hotspot/print') {
            return array(array('name' => 'all'));
        }

        if ($path === '/ip/hotspot/user/print') {
            return array(array(
                '.id' => '*204',
                'server' => 'all',
                'name' => 'client204',
                'password' => 'client204',
                'mac-address' => '',
                'profile' => 'default',
                'uptime' => '0s',
                'disabled' => 'false',
                'limit-uptime' => '',
                'limit-bytes-total' => '0',
                'bytes-out' => '0',
                'bytes-in' => '0',
                'comment' => 'vc-test',
            ));
        }

        if ($path === '/ip/hotspot/user/profile/print' && isset($params['?name'])) {
            return array(array(
                'name' => 'default',
                'on-login' => ',,100,1d,150,,Disable',
            ));
        }

        if ($path === '/system/scheduler/print') {
            return array(array(
                'start-date' => 'may/17/2026',
                'start-time' => '12:00:00',
                'next-run' => 'may/18/2026 12:00:00',
            ));
        }

        return array();
    }
}

$API = new HotspotUserByNameApiStub();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        fwrite(STDERR, $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL);
    }
});

ob_start();
include __DIR__ . '/../hotspot/userbyname.php';
$html = ob_get_clean();

if (!function_exists('mikhmon_format_money_amount')) {
    fwrite(STDERR, "hotspot/userbyname.php did not load mikhmon_compat.php\n");
    exit(1);
}

if (strpos($html, 'name="profile"') === false || strpos($html, 'client204') === false) {
    fwrite(STDERR, "hotspot user detail form did not render\n");
    exit(1);
}

echo "hotspot_userbyname_include_path_test passed\n";
