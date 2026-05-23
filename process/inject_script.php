<?php
/*
 * inject_script.php — Déploie automatiquement le script MIKHMON-DeviceMonitor
 * sur MikroTik via l'API RouterOS.
 *
 * POST: session, action (inject|remove|status)
 */
session_start();
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['mikhmon'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../include/anti_fraud.php';
require_once __DIR__ . '/../include/device_monitor.php';
require_once __DIR__ . '/../include/ap_monitor.php';

$session = isset($_POST['session']) ? trim($_POST['session']) : '';
$action  = isset($_POST['action'])  ? trim($_POST['action'])  : 'status';
$rogueInterface = isset($_POST['interface']) ? trim($_POST['interface']) : 'bridge';

@include_once __DIR__ . '/../include/config.php';
if ($session !== '') {
    @include __DIR__ . '/../include/readcfg.php';
}

const SCRIPT_NAME        = 'MIKHMON-DeviceMonitor';
const SCHED_NAME         = 'MIKHMON-DeviceMonitor-Task';
const SCHED_INTERVAL     = '00:10:00';
const ROGUE_COMMENT      = 'MIKHMON-RogueDHCP';
const ANTIFR_SCRIPT_NAME = 'MIKHMON-AntiFraud';
const ANTIFR_SCHED_NAME  = 'MIKHMON-AntiFraud-Task';
const ANTIFR_SCHED_INTERVAL = '00:05:00';

/* ── Connexion MikroTik ─────────────────────────────────────────────────── */
function _inj_connect($session) {
    if (empty($session)) return null;
    @include __DIR__ . '/../include/config.php';
    @include __DIR__ . '/../include/readcfg.php';
    @include_once __DIR__ . '/../lib/routeros_api.class.php';
    if (empty($iphost) || empty($userhost) || empty($passwdhost)) return null;
    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) return $API;
    } catch (Exception $e) {}
    return null;
}

function _inj_rows_by_name($API, $path, $name) {
    $rows = $API->comm($path . '/print');
    $out = array();
    foreach ((array)$rows as $row) {
        if (isset($row['name']) && (string)$row['name'] === (string)$name) {
            $out[] = $row;
        }
    }
    return $out;
}

function _inj_first_by_name($API, $path, $name) {
    $rows = _inj_rows_by_name($API, $path, $name);
    return !empty($rows) ? $rows[0] : null;
}

function _inj_wait_first_by_name($API, $path, $name, $tries = 5) {
    for ($i = 0; $i < $tries; $i++) {
        $row = _inj_first_by_name($API, $path, $name);
        if ($row) return $row;
        usleep(250000);
    }
    return null;
}

/* ── Webhook URL dynamique ──────────────────────────────────────────────── */
$mikhmonBasePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'])), '/\\');
if ($mikhmonBasePath === '/' || $mikhmonBasePath === '.') $mikhmonBasePath = '';
$webhookInfo = device_monitor_build_webhook_url($mikhmonBasePath, $iphost ?? '', $dnsname ?? '');
$webhookUrl = $webhookInfo['url'];
$webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
$webhookWarning = !empty($webhookInfo['warnings']) ? implode(' ', $webhookInfo['warnings']) : '';
$webhookMeta = array(
    'webhook_url' => $webhookUrl,
    'recommended_url' => $webhookInfo['recommended_url'] ?? $webhookUrl,
    'recommended_host' => $webhookInfo['recommended_host'] ?? $webhookHost,
    'current_url' => $webhookInfo['current_url'] ?? '',
    'browser_recommended_url' => $webhookInfo['browser_recommended_url'] ?? '',
    'recommendation' => $webhookInfo['recommendation'] ?? '',
    'warning' => $webhookWarning,
);

$API = _inj_connect($session);
if (!$API) {
    echo json_encode(['ok' => false, 'error' => 'MikroTik inaccessible']);
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   STATUS : vérifie si le script est déjà déployé
════════════════════════════════════════════════════════════════════════ */
if ($action === 'status') {
    $scripts = _inj_rows_by_name($API, '/system/script', SCRIPT_NAME);
    $scheds  = _inj_rows_by_name($API, '/system/scheduler', SCHED_NAME);
    $API->disconnect();
    echo json_encode(array_merge([
        'ok'             => true,
        'script_exists'  => is_array($scripts) && !empty($scripts),
        'sched_exists'   => is_array($scheds)  && !empty($scheds),
        'script_name'    => SCRIPT_NAME,
        'sched_name'     => SCHED_NAME,
        'sched_interval' => SCHED_INTERVAL,
    ], $webhookMeta));
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   ROGUE DHCP : status / inject / remove via /ip dhcp-server alert
════════════════════════════════════════════════════════════════════════ */
if (in_array($action, array('rogue_status', 'rogue_inject', 'rogue_remove'), true)) {
    if ($rogueInterface === '') $rogueInterface = 'bridge';
    $errors = array();

    if ($action === 'rogue_remove') {
        try {
            $rows = $API->comm('/ip/dhcp-server/alert/print', array('?comment' => ROGUE_COMMENT));
            foreach ((array)$rows as $row) {
                if (!empty($row['.id'])) $API->comm('/ip/dhcp-server/alert/remove', array('.id' => $row['.id']));
            }
        } catch (Exception $e) { $errors[] = $e->getMessage(); }
        $API->disconnect();
        echo json_encode(array('ok' => empty($errors), 'removed' => true, 'errors' => $errors));
        exit;
    }

    $validMac = '';
    $bridgeName = 'bridge';
    try {
        $bridges = $API->comm('/interface/bridge/print');
        $bridgePick = function_exists('ap_rogue_pick_bridge_valid_mac')
            ? ap_rogue_pick_bridge_valid_mac($bridges, $rogueInterface)
            : array('interface' => '', 'mac' => '');
        if (!empty($bridgePick['interface']) && !empty($bridgePick['mac'])) {
            $bridgeName = $bridgePick['interface'];
            $validMac = $bridgePick['mac'];
        }
    } catch (Exception $e) { $errors[] = 'bridge: ' . $e->getMessage(); }

    $ifaceExists = false;
    try {
        $ifaceRows = $API->comm('/interface/print', array('?name' => $rogueInterface));
        $ifaceExists = is_array($ifaceRows) && !empty($ifaceRows);
    } catch (Exception $e) {}
    if ((!$ifaceExists || $rogueInterface === 'bridge') && $bridgeName !== '') $rogueInterface = $bridgeName;

    if ($action === 'rogue_status') {
        $rows = array();
        try { $rows = $API->comm('/ip/dhcp-server/alert/print', array('?comment' => ROGUE_COMMENT)); } catch (Exception $e) {}
        $API->disconnect();
        echo json_encode(array_merge(array(
            'ok' => true,
            'installed' => is_array($rows) && !empty($rows),
            'valid_mac' => $validMac,
            'interface' => $rogueInterface,
        ), $webhookMeta));
        exit;
    }

    if ($validMac === '') $errors[] = 'MAC du bridge MikroTik introuvable';

    if (empty($errors)) {
        $apiKey = anti_fraud_get_api_key();
        $onAlert = function_exists('ap_rogue_build_on_alert')
            ? ap_rogue_build_on_alert($webhookUrl, $apiKey, $session, $rogueInterface, $validMac)
            : ':local alertId [/ip dhcp-server alert find where comment="' . ROGUE_COMMENT . '"]; '
                . ':local rogueMac ""; '
                . ':if ([:len $alertId] > 0) do={ :set rogueMac [/ip dhcp-server alert get $alertId unknown-server] }; '
                . ':if ([:len $rogueMac] = 0) do={ :set rogueMac "unknown" }; '
                . ':local postData ""; '
                . ':set postData ("key=' . addcslashes($apiKey, '\\"') . '&session=' . addcslashes($session, '\\"') . '&source=' . addcslashes($session, '\\"') . '&mode=rogue_dhcp&interface=' . addcslashes($rogueInterface, '\\"') . '&valid_mac=' . addcslashes($validMac, '\\"') . '&rogue_mac=" . $rogueMac); '
                . '/tool fetch url="' . addcslashes($webhookUrl, '\\"') . '" http-method=post http-data=$postData output=none; '
                . ':log warning ("[MIKHMON-RogueDHCP] serveur DHCP rogue MAC=" . $rogueMac)';
        try {
            $rows = $API->comm('/ip/dhcp-server/alert/print', array('?comment' => ROGUE_COMMENT));
            foreach ((array)$rows as $row) {
                if (!empty($row['.id'])) $API->comm('/ip/dhcp-server/alert/remove', array('.id' => $row['.id']));
            }
            $API->comm('/ip/dhcp-server/alert/add', array(
                'interface' => $rogueInterface,
                'valid-server' => $validMac,
                'alert-timeout' => '1h',
                'on-alert' => $onAlert,
                'comment' => ROGUE_COMMENT,
            ));
        } catch (Exception $e) { $errors[] = 'dhcp-alert: ' . $e->getMessage(); }
    }

    $API->disconnect();
    echo json_encode(array_merge(array(
        'ok' => empty($errors),
        'installed' => empty($errors),
        'interface' => $rogueInterface,
        'valid_mac' => $validMac,
        'errors' => $errors,
    ), $webhookMeta));
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   ANTI-FRAUD : status / inject / remove
════════════════════════════════════════════════════════════════════════ */
if (in_array($action, array('antifr_status', 'antifr_inject', 'antifr_remove'), true)) {
    $errors = array();

    if ($action === 'antifr_status') {
        $scripts = _inj_rows_by_name($API, '/system/script', ANTIFR_SCRIPT_NAME);
        $scheds  = _inj_rows_by_name($API, '/system/scheduler', ANTIFR_SCHED_NAME);
        $API->disconnect();
        echo json_encode(array_merge(array(
            'ok'             => true,
            'script_exists'  => is_array($scripts) && !empty($scripts),
            'sched_exists'   => is_array($scheds)  && !empty($scheds),
            'script_name'    => ANTIFR_SCRIPT_NAME,
            'sched_name'     => ANTIFR_SCHED_NAME,
            'sched_interval' => ANTIFR_SCHED_INTERVAL,
        ), $webhookMeta));
        exit;
    }

    if ($action === 'antifr_remove') {
        try {
            $s = _inj_rows_by_name($API, '/system/script', ANTIFR_SCRIPT_NAME);
            if (is_array($s) && !empty($s[0]['.id'])) {
                $API->comm('/system/script/remove', array('.id' => $s[0]['.id']));
            }
        } catch (Exception $e) { $errors[] = 'script: ' . $e->getMessage(); }
        try {
            $t = _inj_rows_by_name($API, '/system/scheduler', ANTIFR_SCHED_NAME);
            if (is_array($t) && !empty($t[0]['.id'])) {
                $API->comm('/system/scheduler/remove', array('.id' => $t[0]['.id']));
            }
        } catch (Exception $e) { $errors[] = 'scheduler: ' . $e->getMessage(); }
        $API->disconnect();
        echo json_encode(array('ok' => true, 'removed' => true, 'errors' => $errors));
        exit;
    }

    // antifr_inject
    $apiKey  = anti_fraud_get_api_key();
    $content = anti_fraud_build_script($webhookUrl, $apiKey, $session);

    $scriptAction = '';
    try {
        $existing = _inj_first_by_name($API, '/system/script', ANTIFR_SCRIPT_NAME);
        if (is_array($existing) && !empty($existing)) {
            $API->comm('/system/script/set', array(
                '.id'    => $existing['.id'],
                'source' => $content,
                'policy' => 'read,write,test,ftp',
            ));
            $scriptAction = 'updated';
        } else {
            $API->comm('/system/script/add', array(
                'name'   => ANTIFR_SCRIPT_NAME,
                'source' => $content,
                'policy' => 'read,write,test,ftp',
            ));
            $scriptAction = 'created';
        }
    } catch (Exception $e) { $errors[] = 'script: ' . $e->getMessage(); }

    $schedAction = '';
    try {
        $existSched = _inj_first_by_name($API, '/system/scheduler', ANTIFR_SCHED_NAME);
        if (is_array($existSched) && !empty($existSched)) {
            $API->comm('/system/scheduler/set', array(
                '.id'      => $existSched['.id'],
                'interval' => ANTIFR_SCHED_INTERVAL,
                'on-event' => ANTIFR_SCRIPT_NAME,
                'policy'   => 'read,write,test,ftp',
            ));
            $schedAction = 'updated';
        } else {
            $API->comm('/system/scheduler/add', array(
                'name'       => ANTIFR_SCHED_NAME,
                'interval'   => ANTIFR_SCHED_INTERVAL,
                'on-event'   => ANTIFR_SCRIPT_NAME,
                'policy'     => 'read,write,test,ftp',
                'start-time' => 'startup',
                'comment'    => 'MIKHMON Anti-Fraud -- auto-deployed',
            ));
            $schedAction = 'created';
        }
        if (!_inj_wait_first_by_name($API, '/system/scheduler', ANTIFR_SCHED_NAME)) {
            $errors[] = 'scheduler: non créé sur MikroTik';
        }
    } catch (Exception $e) { $errors[] = 'scheduler: ' . $e->getMessage(); }

    $API->disconnect();
    echo json_encode(array_merge(array(
        'ok'          => empty($errors),
        'script'      => $scriptAction,
        'scheduler'   => $schedAction,
        'script_name' => ANTIFR_SCRIPT_NAME,
        'sched_name'  => ANTIFR_SCHED_NAME,
        'errors'      => $errors,
    ), $webhookMeta));
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   INJECT : crée ou met à jour le script + scheduler
════════════════════════════════════════════════════════════════════════ */
if ($action === 'inject') {
    $apiKey  = anti_fraud_get_api_key();
    $content = device_monitor_build_script($webhookUrl, $apiKey, $session);
    $errors  = array();

    // ── Script ───────────────────────────────────────────────────────────
    $scriptAction = '';
    try {
        $existing = _inj_first_by_name($API, '/system/script', SCRIPT_NAME);
        if (is_array($existing) && !empty($existing)) {
            $API->comm('/system/script/set', array(
                '.id'    => $existing['.id'],
                'source' => $content,
                'policy' => 'read,write,test,ftp',
            ));
            $scriptAction = 'updated';
        } else {
            $API->comm('/system/script/add', array(
                'name'   => SCRIPT_NAME,
                'source' => $content,
                'policy' => 'read,write,test,ftp',
            ));
            $scriptAction = 'created';
        }
    } catch (Exception $e) {
        $errors[] = 'script: ' . $e->getMessage();
    }

    // ── Scheduler ────────────────────────────────────────────────────────
    $schedAction = '';
    try {
        $existSched = _inj_first_by_name($API, '/system/scheduler', SCHED_NAME);
        if (is_array($existSched) && !empty($existSched)) {
            $API->comm('/system/scheduler/set', array(
                '.id'      => $existSched['.id'],
                'interval' => SCHED_INTERVAL,
                'on-event' => SCRIPT_NAME,
                'policy'   => 'read,write,test,ftp',
            ));
            $schedAction = 'updated';
        } else {
            $API->comm('/system/scheduler/add', array(
                'name'       => SCHED_NAME,
                'interval'   => SCHED_INTERVAL,
                'on-event'   => SCRIPT_NAME,
                'policy'     => 'read,write,test,ftp',
                'start-time' => 'startup',
                'comment'    => 'MIKHMON Device Monitor — auto-deployed',
            ));
            $schedAction = 'created';
        }
        if (!_inj_wait_first_by_name($API, '/system/scheduler', SCHED_NAME)) {
            $errors[] = 'scheduler: non créé sur MikroTik';
        }
    } catch (Exception $e) {
        $errors[] = 'scheduler: ' . $e->getMessage();
    }

    $API->disconnect();

    echo json_encode(array_merge(array(
        'ok'           => empty($errors),
        'script'       => $scriptAction,
        'scheduler'    => $schedAction,
        'script_name'  => SCRIPT_NAME,
        'sched_name'   => SCHED_NAME,
        'errors'       => $errors,
    ), $webhookMeta));
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   REMOVE : supprime le script + scheduler
════════════════════════════════════════════════════════════════════════ */
if ($action === 'remove') {
    $errors = array();
    try {
        $s = _inj_rows_by_name($API, '/system/script', SCRIPT_NAME);
        if (is_array($s) && !empty($s[0]['.id'])) {
            $API->comm('/system/script/remove', array('.id' => $s[0]['.id']));
        }
    } catch (Exception $e) { $errors[] = 'script: ' . $e->getMessage(); }
    try {
        $t = _inj_rows_by_name($API, '/system/scheduler', SCHED_NAME);
        if (is_array($t) && !empty($t[0]['.id'])) {
            $API->comm('/system/scheduler/remove', array('.id' => $t[0]['.id']));
        }
    } catch (Exception $e) { $errors[] = 'scheduler: ' . $e->getMessage(); }
    $API->disconnect();
    echo json_encode(array('ok' => true, 'removed' => true, 'errors' => $errors));
    exit;
}

$API->disconnect();
http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'unknown_action'));
