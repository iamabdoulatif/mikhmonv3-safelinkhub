<?php
require_once __DIR__ . '/../lib/routeros_api.class.php';

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($sockets === false) {
    fwrite(STDERR, "Unable to create the RouterOS API test sockets\n");
    exit(1);
}

$api = new RouterosAPI();
$api->socket = $sockets[0];
$api->connected = true;
$api->timeout = 1;
stream_set_blocking($api->socket, false);

fwrite($sockets[1], chr(2) . 'ab' . chr(5) . 'cd');

$startedAt = microtime(true);
$response = $api->read(false);
$elapsed = microtime(true) - $startedAt;
if (is_resource($api->socket)) {
    fclose($api->socket);
}
fclose($sockets[1]);

if ($response !== array('ab')) {
    fwrite(STDERR, "RouterOS read must keep completed words and discard the partial word\n");
    exit(1);
}

if ($elapsed < 0.8 || $elapsed > 2) {
    fwrite(STDERR, "RouterOS API read did not respect its timeout\n");
    exit(1);
}

if ($api->connected || $api->comm('/system/identity/print') !== array()) {
    fwrite(STDERR, "RouterOS API must close a timed out socket and reject later commands\n");
    exit(1);
}

echo "routeros_api_read_timeout_test passed\n";
