<?php
/*
 * raw_dl.php — Sert le contenu brut d'un fichier PHP (source, pas execute)
 * Utilise par RouterOS /tool/fetch pour injecter des fichiers dans le conteneur MikroTik.
 * Acces : ?f=fraud_webhook&k=<api_key>
 * Seuls les fichiers de la liste blanche sont servis.
 */
@include_once __DIR__ . '/../include/config.php';
@include_once __DIR__ . '/../include/anti_fraud.php';

$key = isset($_GET['k']) ? trim($_GET['k']) : '';
$f   = isset($_GET['f']) ? trim($_GET['f']) : '';

// Validation de la cle API
$validKey = function_exists('anti_fraud_get_api_key') ? anti_fraud_get_api_key() : '';
if ($validKey === '' || $key !== $validKey) {
    http_response_code(403);
    exit('forbidden');
}

// Liste blanche des fichiers deployables
$allowed = array(
    'fraud_webhook'  => __DIR__ . '/fraud_webhook.php',
    'fraud_api_key'  => __DIR__ . '/fraud_api_key.txt',
    'anti_fraud'     => __DIR__ . '/../include/anti_fraud.php',
    'device_monitor' => __DIR__ . '/../include/device_monitor.php',
    'ap_monitor'     => __DIR__ . '/../include/ap_monitor.php',
    'oui_lookup'     => __DIR__ . '/../include/oui_lookup.php',
);

if (!isset($allowed[$f])) {
    http_response_code(404);
    exit('not found');
}

$path = $allowed[$f];
if (!file_exists($path)) {
    http_response_code(404);
    exit('file missing');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Length: ' . filesize($path));
readfile($path);
