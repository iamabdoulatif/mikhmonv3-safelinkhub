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
    'MANIFEST_TAGS="${MANIFEST_TAGS:-latest v1}"',
    'docker buildx imagetools create',
    '"${IMAGE_NAME}:armv7"',
    '"${IMAGE_NAME}:arm64"',
    'publish_manifest_tags',
);

foreach ($checks as $needle) {
    if (strpos($script, $needle) === false) {
        fwrite(STDERR, 'missing skopeo compression option: ' . $needle . PHP_EOL);
        exit(1);
    }
}

echo "ci_skopeo_compression_test passed\n";
