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
    'DELETE_DOCKERHUB_EXISTING_TAGS="${DELETE_DOCKERHUB_EXISTING_TAGS:-1}"',
    'MANIFEST_TAGS="${MANIFEST_TAGS:-latest}"',
    'MIN_COMPRESSED_MB="${MIN_COMPRESSED_MB:-10}"',
    'MAX_COMPRESSED_MB="${MAX_COMPRESSED_MB:-13}"',
    'SIZE_CHECK_PLATFORMS="${SIZE_CHECK_PLATFORMS:-linux/arm64 linux/arm/v6 linux/arm/v7}"',
    'measure_compressed_size',
    'enforce_compressed_size',
    'delete_existing_dockerhub_tags',
    'delete_dockerhub_tags',
    'cleanup_intermediate_dockerhub_tags',
    'hub.docker.com/v2/repositories',
    'linux/amd64|amd64',
    'linux/arm64|arm64',
    'linux/s390x|s390x',
    'linux/arm/v6|armv6',
    'linux/arm/v7|armv7',
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
