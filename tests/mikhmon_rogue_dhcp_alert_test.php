<?php
$root = dirname(__DIR__);
require_once $root . '/include/ap_monitor.php';

if (!function_exists('ap_rogue_build_alert_fields')) {
    fwrite(STDERR, 'missing ap_rogue_build_alert_fields helper' . PHP_EOL);
    exit(1);
}

if (!function_exists('ap_rogue_pick_bridge_valid_mac')) {
    fwrite(STDERR, 'missing ap_rogue_pick_bridge_valid_mac helper' . PHP_EOL);
    exit(1);
}

$picked = ap_rogue_pick_bridge_valid_mac(array(
    array('name' => 'DOCKER', 'mac-address' => '74:C5:0C:BE:7E:AA'),
    array('name' => 'HOTSPOT', 'mac-address' => '78:9A:18:2D:EE:57'),
), 'HOTSPOT');

if (($picked['interface'] ?? '') !== 'HOTSPOT' || ($picked['mac'] ?? '') !== '78:9A:18:2D:EE:57') {
    fwrite(STDERR, 'valid-server detection must use the HOTSPOT bridge MAC before the DOCKER bridge MAC' . PHP_EOL);
    exit(1);
}

$fields = ap_rogue_build_alert_fields(
    'http://10.10.0.1:8087/process/fraud_webhook.php',
    'abc123',
    'ALB-TECH',
    'HOTSPOT',
    '78:9A:18:2D:EE:57'
);

$expected = array(
    'interface' => 'HOTSPOT',
    'valid_server' => '78:9A:18:2D:EE:57',
    'alert_timeout' => '1h',
);

foreach ($expected as $key => $value) {
    if (($fields[$key] ?? '') !== $value) {
        fwrite(STDERR, 'unexpected DHCP alert field ' . $key . PHP_EOL);
        exit(1);
    }
}

$onAlert = $fields['on_alert'] ?? '';
$requiredOnAlertParts = array(
    ':local alertId [/ip dhcp-server alert find where comment="MIKHMON-RogueDHCP"]',
    ':local validMac "78:9A:18:2D:EE:57"',
    ':set rogueMac [/ip dhcp-server alert get $alertId unknown-server]',
    '$rogueMac != $validMac',
    'mode=rogue_dhcp',
    'interface=HOTSPOT',
    'valid_mac=78:9A:18:2D:EE:57',
    '/tool fetch url="http://10.10.0.1:8087/process/fraud_webhook.php"',
);

foreach ($requiredOnAlertParts as $needle) {
    if (strpos($onAlert, $needle) === false) {
        fwrite(STDERR, 'on-alert script missing: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (strpos($onAlert, '$"unknown-server"') !== false) {
    fwrite(STDERR, 'on-alert must read the DHCP alert unknown-server field, not $"unknown-server"' . PHP_EOL);
    exit(1);
}

if (strpos($onAlert, '/ip dhcp-server alert add') !== false) {
    fwrite(STDERR, 'on-alert field must not contain the full DHCP alert installer' . PHP_EOL);
    exit(1);
}

$installer = ap_rogue_build_script(
    'http://10.10.0.1:8087/process/fraud_webhook.php',
    'abc123',
    'ALB-TECH',
    'HOTSPOT'
);

if (strpos($installer, '/ip dhcp-server alert add') === false) {
    fwrite(STDERR, 'full installer must still create the DHCP alert for automatic deployment' . PHP_EOL);
    exit(1);
}

if (strpos($installer, '$\\"unknown-server\\"') !== false || strpos($installer, '$"unknown-server"') !== false) {
    fwrite(STDERR, 'full installer must not store $"unknown-server" in the alert script' . PHP_EOL);
    exit(1);
}

if (strpos($installer, ':local validMac') === false || strpos($installer, '$validMac') === false || strpos($installer, '\\$rogueMac != \\$validMac') === false) {
    fwrite(STDERR, 'full installer must pin the detected HOTSPOT bridge MAC inside on-alert and ignore the valid server' . PHP_EOL);
    exit(1);
}

$fraudPage = file_get_contents($root . '/settings/fraud.php');
if (strpos($fraudPage, 'IP &gt; DHCP Server &gt; Alerts') === false) {
    fwrite(STDERR, 'fraud page must point manual deployment to DHCP Server Alerts' . PHP_EOL);
    exit(1);
}

if (strpos($fraudPage, 'System &gt; Scripts') !== false || strpos($fraudPage, 'System → Scripts') !== false) {
    fwrite(STDERR, 'fraud page must not instruct DHCP Rogue manual deployment through System Scripts' . PHP_EOL);
    exit(1);
}

echo "mikhmon_rogue_dhcp_alert_test passed\n";
