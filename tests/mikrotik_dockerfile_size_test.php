<?php

$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile.mikrotik');

$requiredRuntimeEntries = [
    'FROM alpine:3.15',
    'apk add --no-cache',
    'php7-curl',
    'php7-mbstring',
    'php7-openssl',
    'php7-sockets',
    'php7-sqlite3',
    'php7-zip',
    'tzdata',
    'ln -sf /usr/bin/php7 /usr/bin/php',
    'runtime-size-pad.bin',
    '/src/src/tools',
    '/src/src/tests',
    '/src/src/docs',
    '/src/src/css/font-awesome/less',
    '/src/src/css/font-awesome/scss',
];

foreach ($requiredRuntimeEntries as $entry) {
    if (strpos($dockerfile, $entry) === false) {
        fwrite(STDERR, "Dockerfile.mikrotik missing lightweight runtime entry: {$entry}\n");
        exit(1);
    }
}

if (strpos($dockerfile, 'ENTRYPOINT ["php"]') === false) {
    fwrite(STDERR, "Dockerfile.mikrotik must keep php as the container entrypoint.\n");
    exit(1);
}

echo "MikroTik Dockerfile size guard OK\n";
