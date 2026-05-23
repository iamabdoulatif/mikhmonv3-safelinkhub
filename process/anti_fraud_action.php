<?php
/*
 * anti_fraud_action.php
 * Gère les actions admin sur les incidents de fraude :
 *   - acknowledged / resolved           (statut incident)
 *   - block_device / unblock_device     (blocage MAC+IP sur MikroTik)
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

$action     = isset($_POST['action'])     ? trim($_POST['action'])     : '';
$user       = isset($_POST['user'])       ? trim($_POST['user'])       : '';
$status     = isset($_POST['status'])     ? trim($_POST['status'])     : '';
$session    = isset($_POST['session'])    ? trim($_POST['session'])    : '';
$deviceKey  = isset($_POST['device_key']) ? trim($_POST['device_key']) : '';
$deviceMac  = isset($_POST['device_mac']) ? strtoupper(trim($_POST['device_mac'])) : '';
$deviceIp   = isset($_POST['device_ip'])  ? trim($_POST['device_ip'])  : '';
$bindingId  = isset($_POST['binding_id']) ? trim($_POST['binding_id']) : '';
$fwId       = isset($_POST['fw_id'])      ? trim($_POST['fw_id'])      : '';

if ($user === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_user']);
    exit;
}

/* ── Helpers : connexion MikroTik depuis la config de session ─────────────── */
function _fraud_connect_mikrotik($session) {
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

/* ════════════════════════════════════════════════════════════════════════════
   ACTION : acknowledged / resolved
════════════════════════════════════════════════════════════════════════════ */
if ($action === '' && $status !== '') {
    // Rétro-compatibilité avec l'ancien appel (status directement)
    $action = $status;
}

if (in_array($action, ['acknowledged', 'resolved'], true)) {
    anti_fraud_set_status($user, $action, $_SESSION['mikhmon']);

    // Optionnel : couper les cookies/sessions si resolve + clear_cookies
    if ($action === 'resolved' && !empty($_POST['clear_cookies'])) {
        $API = _fraud_connect_mikrotik($session);
        if ($API) {
            $cookies = $API->comm('/ip/hotspot/cookie/print', ['?user' => $user]);
            if (is_array($cookies)) {
                foreach ($cookies as $c) {
                    if (!empty($c['.id'])) $API->comm('/ip/hotspot/cookie/remove', ['.id' => $c['.id']]);
                }
            }
            $active = $API->comm('/ip/hotspot/active/print', ['?user' => $user]);
            if (is_array($active)) {
                foreach ($active as $a) {
                    if (!empty($a['.id'])) $API->comm('/ip/hotspot/active/remove', ['.id' => $a['.id']]);
                }
            }
            $API->disconnect();
        }
    }
    echo json_encode(['ok' => true, 'action' => $action]);
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTION : block_device
   Bloque MAC dans /ip/hotspot/ip-binding + IP dans /ip/firewall/address-list
════════════════════════════════════════════════════════════════════════════ */
if ($action === 'block_device') {
    if ($deviceKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_device_key']);
        exit;
    }

    $API = _fraud_connect_mikrotik($session);
    if (!$API) {
        // Pas d'accès API direct — on marque le blocage "pending" dans fraud.json
        // (sans l'appliquer sur MikroTik ; un avertissement est renvoyé)
        anti_fraud_update_device_block($user, $deviceKey, true, '', '');
        echo json_encode([
            'ok'      => true,
            'applied' => false,
            'warning' => 'MikroTik inaccessible — blocage enregistré localement uniquement.',
        ]);
        exit;
    }

    $result = anti_fraud_block_device($API, $deviceMac, $deviceIp, $user);
    $API->disconnect();

    if ($result['ok'] || empty($result['errors'])) {
        anti_fraud_update_device_block(
            $user, $deviceKey, true,
            $result['binding_id'], $result['fw_id']
        );
        echo json_encode([
            'ok'         => true,
            'applied'    => true,
            'binding_id' => $result['binding_id'],
            'fw_id'      => $result['fw_id'],
            'warnings'   => $result['errors'],
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'errors' => $result['errors']]);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTION : unblock_device
   Retire les entrées IP Binding + Firewall Address List
════════════════════════════════════════════════════════════════════════════ */
if ($action === 'unblock_device') {
    if ($deviceKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_device_key']);
        exit;
    }

    $API = _fraud_connect_mikrotik($session);
    if (!$API) {
        // Marquer comme débloqué localement (sans appliquer sur MikroTik)
        anti_fraud_update_device_block($user, $deviceKey, false, '', '');
        echo json_encode([
            'ok'      => true,
            'applied' => false,
            'warning' => 'MikroTik inaccessible — déblocage enregistré localement uniquement.',
        ]);
        exit;
    }

    $result = anti_fraud_unblock_device($API, $deviceMac, $deviceIp, $bindingId, $fwId);
    $API->disconnect();

    anti_fraud_update_device_block($user, $deviceKey, false, '', '');
    echo json_encode([
        'ok'       => true,
        'applied'  => true,
        'warnings' => $result['errors'],
    ]);
    exit;
}

/* ─── Action inconnue ─────────────────────────────────────────────────────── */
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action', 'received' => $action]);
