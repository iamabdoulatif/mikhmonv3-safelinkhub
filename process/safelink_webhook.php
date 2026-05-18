<?php
/*
 * Endpoint de réception webhook SafeLinkHub pour Mikhmon.
 * URL type: https://votre-host/mikhmon/process/safelink_webhook.php
 */

require_once __DIR__ . '/../include/safelink_integration.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
  http_response_code(405);
  echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
  exit;
}

$cfg = safelink_integration_load();
if (empty($cfg['enabled'])) {
  http_response_code(503);
  echo json_encode(array('ok' => false, 'error' => 'integration_disabled'));
  exit;
}

$raw = file_get_contents('php://input');
$signature = '';
if (!empty($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
  $signature = trim($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
}
$eventName = !empty($_SERVER['HTTP_X_WEBHOOK_EVENT']) ? trim($_SERVER['HTTP_X_WEBHOOK_EVENT']) : '';

$isValidSignature = true;
if (!empty($cfg['webhook_secret'])) {
  $expected = hash_hmac('sha256', (string)$raw, $cfg['webhook_secret']);
  $isValidSignature = hash_equals($expected, $signature);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
  $decoded = array('raw' => $raw);
}

$entry = array(
  'time' => date('c'),
  'event' => $eventName,
  'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
  'signature_valid' => $isValidSignature,
  'payload' => $decoded,
);
safelink_log_webhook_event($entry);

if (!$isValidSignature) {
  http_response_code(401);
  echo json_encode(array('ok' => false, 'error' => 'invalid_signature'));
  exit;
}

http_response_code(200);
echo json_encode(array('ok' => true, 'received' => true));
