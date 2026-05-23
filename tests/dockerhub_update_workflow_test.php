<?php

$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/dockerhub.yml';
$dockerfile = file_get_contents($root . '/Dockerfile.mikrotik');
$update = file_exists($root . '/include/app_update.php')
    ? file_get_contents($root . '/include/app_update.php')
    : '';
$head = file_get_contents($root . '/include/headhtml.php');
$manager = file_get_contents($root . '/manager.php');

if (!file_exists($workflow)) {
    fwrite(STDERR, "DockerHub GitHub Actions workflow is missing\n");
    exit(1);
}

$workflowText = file_get_contents($workflow);
$checks = array(
    'dockerhub username secret' => 'DOCKERHUB_USERNAME',
    'dockerhub token secret' => 'DOCKERHUB_TOKEN',
    'flattened build script' => 'tools/ci-build-push-flattened.sh',
    'dockerhub repository' => 'latif225/mikhmonv3-safelinkhub',
    'build timestamp step' => 'BUILD_STAMP=',
);

foreach ($checks as $label => $needle) {
    if (strpos($workflowText, $needle) === false) {
        fwrite(STDERR, $label . " missing from dockerhub workflow\n");
        exit(1);
    }
}

$dockerChecks = array(
    'build version environment' => 'ENV MIKHMON_BUILD_VERSION=',
    'build stamp environment' => 'ENV MIKHMON_BUILD_STAMP=',
    'build info file' => 'build-info.json',
);

foreach ($dockerChecks as $label => $needle) {
    if (strpos($dockerfile, $needle) === false) {
        fwrite(STDERR, $label . " missing from Dockerfile.mikrotik\n");
        exit(1);
    }
}

$updateChecks = array(
    'update status helper' => 'function mikhmon_update_status',
    'DockerHub tag API' => 'hub.docker.com/v2/repositories',
    'update banner renderer' => 'function mikhmon_render_update_banner',
    'RouterOS install guidance' => '/container',
);

foreach ($updateChecks as $label => $needle) {
    if (strpos($update, $needle) === false) {
        fwrite(STDERR, $label . " missing from app update helper\n");
        exit(1);
    }
}

if (strpos($head, 'mikhmon_render_update_banner') === false) {
    fwrite(STDERR, "admin shell must render the app update banner\n");
    exit(1);
}

if (strpos($manager, 'mikhmon_render_update_banner') === false) {
    fwrite(STDERR, "manager shell must render the app update banner\n");
    exit(1);
}

echo "dockerhub_update_workflow_test passed\n";
