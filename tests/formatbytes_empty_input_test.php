<?php
require_once __DIR__ . '/../lib/formatbytesbites.php';

if (formatBytes('', 2) !== '0 Byte') {
    fwrite(STDERR, "formatBytes should treat empty values as 0 Byte\n");
    exit(1);
}

if (formatBytes2('', 2) !== '0Byte') {
    fwrite(STDERR, "formatBytes2 should treat empty values as 0Byte\n");
    exit(1);
}

if (formatBites('', 2) !== '0 bps') {
    fwrite(STDERR, "formatBites should treat empty values as 0 bps\n");
    exit(1);
}

echo "formatbytes_empty_input_test passed\n";
