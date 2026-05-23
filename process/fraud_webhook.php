<?php
/*
 * fraud_webhook.php — Récepteur de données anti-fraude depuis MikroTik.
 *
 * MikroTik appelle cet endpoint via /tool/fetch (HTTP POST) toutes les N minutes.
 * Aucune session PHP n'est requise — authentification par clé API uniquement.
 *
 * POST fields attendus :
 *   key     (string)  — clé secrète (anti_fraud_get_api_key())
 *   session (string)  — nom de la session MIKHMON (ex: "ROUTEUR-1")
 *   source  (string)  — identifiant du routeur (ex: "ROUTEUR-1")
 *   active  (string)  — "user|MAC|IP,user|MAC|IP,"
 *   cookies (string)  — "user|MAC,user|MAC,"
 *   logs    (string)  — "logline1;logline2;"
 */

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

/* ── Sécurité : limiter aux POST uniquement ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/../include/anti_fraud.php';

// Accepte aussi les données brutes en JSON (alternative au form-data)
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
$postData = $_POST;
if (strpos($contentType, 'application/json') !== false) {
    $body = (array)json_decode(file_get_contents('php://input'), true);
    if (!empty($body)) $postData = $body;
}

/* ── Validation de la clé API ───────────────────────────────────────────── */
$key = isset($postData['key']) ? trim($postData['key']) : '';
// Validation normale : variable d'environnement MIKHMON_FRAUD_API_KEY puis logs/fraud_api_key.txt.
// Compatibilite ancienne image : process/fraud_api_key.txt reste accepte, mais ne bloque plus logs/env.
$_localKeyFile = __DIR__ . '/fraud_api_key.txt';
$_keyOk = ($key !== '' && anti_fraud_validate_key($key));
if (!$_keyOk && file_exists($_localKeyFile)) {
    $_localKey = trim((string)@file_get_contents($_localKeyFile));
    if (strlen($_localKey) >= 32 && $key !== '' && hash_equals($_localKey, $key)) {
        $_keyOk = true;
    }
}
if (!$_keyOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_key']);
    exit;
}

/* ── Récupération des champs ────────────────────────────────────────────── */
$source  = isset($postData['source'])  ? trim($postData['source'])  : 'mikrotik';
$session = isset($postData['session']) ? trim($postData['session']) : '';
$postData['source'] = $source ?: 'mikrotik';

/* ── Traitement ─────────────────────────────────────────────────────────── */
$mode = isset($postData['mode']) ? trim($postData['mode']) : '';

try {
    if ($mode === 'devices') {
        /* ── Surveillance TV/PC : stocke dans logs/hotspot_devices.json ── */
        require_once __DIR__ . '/../include/device_monitor.php';
        $count = device_monitor_process($postData);
        echo json_encode([
            'ok'        => true,
            'mode'      => 'devices',
            'count'     => $count,
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => $postData['source'],
        ]);
    } elseif ($mode === 'rogue_dhcp') {
        /* ── Alerte DHCP rogue : stocke dans logs/rogue_dhcp.json ─────── */
        require_once __DIR__ . '/../include/ap_monitor.php';
        $count = ap_rogue_process_webhook($postData);
        echo json_encode([
            'ok'        => true,
            'mode'      => 'rogue_dhcp',
            'count'     => $count,
            'total'     => count(ap_rogue_load()),
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => $postData['source'],
        ]);
    } else {
        /* ── Anti-fraude classique : logs/fraud.json ─────────────────── */
        $newCount = anti_fraud_process_webhook($postData);
        echo json_encode([
            'ok'        => true,
            'mode'      => 'fraud',
            'new'       => $newCount,
            'total'     => count(anti_fraud_load()),
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => $postData['source'],
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
