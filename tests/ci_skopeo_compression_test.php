<?php
$script = file_get_contents(__DIR__ . '/../tools/ci-build-push-flattened.sh');
if ($script === false) {
    fwrite(STDERR, 'could not read CI image script' . PHP_EOL);
    exit(1);
}

$checks = array(
    '--dest-compress',
    '--dest-compress-format gzip',
    '--dest-compress-level 9',
    '--dest-force-compress-format',
    'PUSH_IMAGES="${PUSH_IMAGES:-1}"',
    'MANIFEST_TAGS="${MANIFEST_TAGS:-latest v1}"',
    'MIN_COMPRESSED_MB="${MIN_COMPRESSED_MB:-11}"',
    'MAX_COMPRESSED_MB="${MAX_COMPRESSED_MB:-13}"',
    'SIZE_CHECK_PLATFORMS="${SIZE_CHECK_PLATFORMS:-linux/arm64 linux/arm/v6 linux/arm/v7}"',
    'measure_compressed_size',
    'enforce_compressed_size',
    'linux/amd64|amd64',
    'linux/arm64|arm64 hap-ax2 ax2',
    'linux/s390x|s390x',
    'linux/arm/v6|armv6 arm32v6',
    'linux/arm/v7|armv7 arm32 hap-ax-lite',
    'docker buildx imagetools create',
    '"${IMAGE_NAME}:amd64"',
    '"${IMAGE_NAME}:armv7"',
    '"${IMAGE_NAME}:armv6"',
    '"${IMAGE_NAME}:arm64"',
    '"${IMAGE_NAME}:s390x"',
    'publish_manifest_tags',
);

foreach ($checks as $needle) {
    if (strpos($script, $needle) === false) {
        fwrite(STDERR, 'missing skopeo compression option: ' . $needle . PHP_EOL);
        exit(1);
    }
}

echo "ci_skopeo_compression_test passed\n";
