<?php
/*
 * device_action.php — Actions sur les appareils surveillés :
 *   disconnect   → force-logout de la session hotspot
 *   block        → IP Binding blocked + Firewall address-list
 *   unblock      → retire les deux entrées
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

$action    = isset($_POST['action'])    ? trim($_POST['action'])    : '';
$mac       = strtoupper(isset($_POST['mac'])  ? trim($_POST['mac'])  : '');
$ip        = isset($_POST['ip'])        ? trim($_POST['ip'])        : '';
$session   = isset($_POST['session'])   ? trim($_POST['session'])   : '';
$bindingId = isset($_POST['binding_id'])? trim($_POST['binding_id']): '';
$fwId      = isset($_POST['fw_id'])     ? trim($_POST['fw_id'])     : '';

if ($mac === '' && $ip === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing mac/ip']);
    exit;
}

/* ── Connexion MikroTik ─────────────────────────────────────────────────── */
function _dev_connect($session) {
    if (empty($session)) return null;
    @include_once __DIR__ . '/../include/config.php';
    @include_once __DIR__ . '/../include/readcfg.php';
    @include_once __DIR__ . '/../lib/routeros_api.class.php';
    if (empty($iphost) || empty($userhost) || empty($passwdhost)) return null;
    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) return $API;
    } catch (Exception $e) {}
    return null;
}

/* ════════════════════════════════════════════════════════════════════════
   DÉCONNECTER (force-logout session hotspot)
════════════════════════════════════════════════════════════════════════ */
if ($action === 'disconnect') {
    $API = _dev_connect($session);
    if (!$API) {
        echo json_encode(['ok' => false, 'error' => 'MikroTik inaccessible']);
        exit;
    }

    $removed = 0;
    // Logout par MAC
    if ($mac !== '') {
        $active = $API->comm('/ip/hotspot/active/print', array('?mac-address' => $mac));
        if (is_array($active)) {
            foreach ($active as $a) {
                if (!empty($a['.id'])) {
                    $API->comm('/ip/hotspot/active/remove', array('.id' => $a['.id']));
                    $removed++;
                }
            }
        }
    }
    // Logout par IP si pas trouvé par MAC
    if ($removed === 0 && $ip !== '') {
        $active = $API->comm('/ip/hotspot/active/print', array('?address' => $ip));
        if (is_array($active)) {
            foreach ($active as $a) {
                if (!empty($a['.id'])) {
                    $API->comm('/ip/hotspot/active/remove', array('.id' => $a['.id']));
                    $removed++;
                }
            }
        }
    }
    $API->disconnect();

    // Mise à jour statut local
    device_monitor_update_block($mac, false, '', '');

    echo json_encode(['ok' => true, 'removed' => $removed]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   BLOQUER (IP Binding blocked + Firewall Address List)
════════════════════════════════════════════════════════════════════════ */
if ($action === 'block') {
    $API = _dev_connect($session);
    if (!$API) {
        // Blocage local uniquement
        device_monitor_update_block($mac, true, '', '');
        echo json_encode([
            'ok' => true, 'applied' => false,
            'warning' => 'MikroTik inaccessible — blocage local uniquement.',
        ]);
        exit;
    }

    $result = anti_fraud_block_device($API, $mac, $ip, 'device-monitor');
    $API->disconnect();
    device_monitor_update_block($mac, true, $result['binding_id'], $result['fw_id']);

    echo json_encode([
        'ok'         => true,
        'applied'    => $result['ok'],
        'binding_id' => $result['binding_id'],
        'fw_id'      => $result['fw_id'],
        'warnings'   => $result['errors'],
    ]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   DÉBLOQUER (retire IP Binding + Address List)
════════════════════════════════════════════════════════════════════════ */
if ($action === 'unblock') {
    $API = _dev_connect($session);
    if (!$API) {
        device_monitor_update_block($mac, false, '', '');
        echo json_encode([
            'ok' => true, 'applied' => false,
            'warning' => 'MikroTik inaccessible — déblocage local uniquement.',
        ]);
        exit;
    }

    $result = anti_fraud_unblock_device($API, $mac, $ip, $bindingId, $fwId);
    $API->disconnect();
    device_monitor_update_block($mac, false, '', '');

    echo json_encode([
        'ok'      => true,
        'applied' => true,
        'warnings'=> $result['errors'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action: ' . $action]);
