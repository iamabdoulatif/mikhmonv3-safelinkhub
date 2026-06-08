<?php

$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile.mikrotik');

$requiredRuntimeEntries = [
    'FROM --platform=$TARGETPLATFORM php:7.4-cli-alpine3.16',
    'ARG TARGETPLATFORM',
    'ENV PHP_CLI_SERVER_WORKERS="4"',
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
