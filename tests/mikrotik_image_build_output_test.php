<?php

$script = file_get_contents(__DIR__ . '/../tools/build-mikrotik-images.sh');
if ($script === false) {
    fwrite(STDERR, "could not read build-mikrotik-images.sh\n");
    exit(1);
}

$checks = array(
    'desktop output override' => 'OUT="${OUT:-$PROJECT_DIR/docker-output}"',
    'arm64 flattened archive' => 'mikhmon-flat-arm64-mikrotik.tar',
    'armv7 flattened archive' => 'mikhmon-flat-armv7-mikrotik.tar',
    'gzip archive output' => 'gzip -9 -f -k "$archive"',
);

foreach ($checks as $label => $needle) {
    if (strpos($script, $needle) === false) {
        fwrite(STDERR, $label . " missing from MikroTik image build script\n");
        exit(1);
    }
}

echo "mikrotik_image_build_output_test passed\n";
