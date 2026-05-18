<?php
/*
 * SafeLink integration helpers for Mikhmon (MAMP/local).
 */

if (!defined('MIKHMON_SAFELINK_CFG')) {
  define('MIKHMON_SAFELINK_CFG', __DIR__ . '/safelink_integration.json');
}
if (!defined('MIKHMON_SAFELINK_LOG')) {
  define('MIKHMON_SAFELINK_LOG', __DIR__ . '/../logs/safelink_webhooks.jsonl');
}

function safelink_default_config() {
  return array(
    'api_base_url' => 'https://safelinkhub.io',
    'api_key' => '',
    'webhook_secret' => '',
    'outbound_webhook_url' => '',
    'enabled' => true,
    'updated_at' => '',
  );
}

function safelink_integration_load() {
  $default = safelink_default_config();
  if (!is_file(MIKHMON_SAFELINK_CFG)) {
    return $default;
  }
  $raw = @file_get_contents(MIKHMON_SAFELINK_CFG);
  if ($raw === false || trim($raw) === '') {
    return $default;
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return $default;
  }
  return array_merge($default, $decoded);
}

function safelink_integration_save($config) {
  $default = safelink_default_config();
  $merged = array_merge($default, is_array($config) ? $config : array());
  $merged['updated_at'] = date('c');
  $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  return @file_put_contents(MIKHMON_SAFELINK_CFG, $json . PHP_EOL, LOCK_EX) !== false;
}

function safelink_clean_url($url) {
  $url = trim((string)$url);
  if ($url === '') {
    return '';
  }
  if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
  }
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return '';
  }
  return rtrim($url, '/');
}

function safelink_mask_key($key) {
  $key = trim((string)$key);
  if ($key === '') {
    return '';
  }
  if (strlen($key) <= 12) {
    return str_repeat('*', strlen($key));
  }
  return substr($key, 0, 6) . str_repeat('*', max(4, strlen($key) - 10)) . substr($key, -4);
}

function safelink_build_local_webhook_url() {
  $scheme = 'http';
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
  } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
  } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
    $scheme = $_SERVER['REQUEST_SCHEME'];
  }
  $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
  $scriptName = !empty($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/mikhmon/admin.php';
  $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
  if ($basePath === '.') {
    $basePath = '';
  }
  return $scheme . '://' . $host . $basePath . '/process/safelink_webhook.php';
}

function safelink_http_request($method, $url, $headers, $body, $timeoutSeconds) {
  $method = strtoupper((string)$method);
  $headers = is_array($headers) ? $headers : array();
  $body = $body === null ? '' : (string)$body;
  $timeout = (int)$timeoutSeconds > 0 ? (int)$timeoutSeconds : 15;

  $result = array(
    'ok' => false,
    'status' => 0,
    'body' => '',
    'error' => '',
  );

  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, $timeout));
    if ($method !== 'GET') {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $result['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
      $result['error'] = curl_error($ch);
    } else {
      $result['body'] = (string)$resp;
      $result['ok'] = ($result['status'] >= 200 && $result['status'] < 300);
    }
    curl_close($ch);
    return $result;
  }

  $opts = array(
    'http' => array(
      'method' => $method,
      'header' => implode("\r\n", $headers),
      'content' => $body,
      'timeout' => $timeout,
      'ignore_errors' => true,
    ),
  );
  $ctx = stream_context_create($opts);
  $resp = @file_get_contents($url, false, $ctx);
  $httpCode = 0;
  if (!empty($http_response_header) && preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $m)) {
    $httpCode = (int)$m[1];
  }
  $result['status'] = $httpCode;
  if ($resp === false) {
    $result['error'] = 'Request failed';
  } else {
    $result['body'] = (string)$resp;
    $result['ok'] = ($httpCode >= 200 && $httpCode < 300);
  }
  return $result;
}

function safelink_log_webhook_event($entry) {
  $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
  if ($line === false) {
    return false;
  }
  return @file_put_contents(MIKHMON_SAFELINK_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}
