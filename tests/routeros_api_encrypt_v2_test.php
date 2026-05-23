<?php
require_once __DIR__ . '/../lib/routeros_api.class.php';

$plain = "admin'123";
$encrypted = encrypt($plain);
$decrypted = decrypt($encrypted);

if ($decrypted !== $plain) {
  fwrite(STDERR, 'encrypted password did not decrypt to the original value' . PHP_EOL);
  exit(1);
}

if (function_exists('openssl_encrypt') && strpos($encrypted, 'v2:') !== 0) {
  fwrite(STDERR, 'openssl-capable installs must use v2 encryption for new secrets' . PHP_EOL);
  exit(1);
}

if (decrypt('aWNlbA==') !== '1234') {
  fwrite(STDERR, 'legacy password values must remain decryptable' . PHP_EOL);
  exit(1);
}

echo "routeros_api_encrypt_v2_test passed\n";
