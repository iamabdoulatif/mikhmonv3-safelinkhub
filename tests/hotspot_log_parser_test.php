<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

$prefixed = mikhmon_hotspot_log_row(array(
    'time' => 'jun/04 22:41:42',
    'message' => '->: 74:C1:7D:FA:04:33 (10.0.0.116): trying to log in by mac',
));

if ($prefixed['user'] !== '74:C1:7D:FA:04:33 (10.0.0.116)' || $prefixed['message'] !== 'log in by mac') {
    fwrite(STDERR, "prefixed hotspot log row was not parsed correctly\n");
    exit(1);
}

$plain = mikhmon_hotspot_log_row(array(
    'time' => '11:02:24',
    'message' => '01331034 (10.0.1.246): trying to log in by mac-cookie',
));

if ($plain['user'] !== '01331034 (10.0.1.246)' || $plain['message'] !== 'log in by mac-cookie') {
    fwrite(STDERR, "plain hotspot log row was not parsed correctly\n");
    exit(1);
}

echo "hotspot_log_parser_test passed\n";
