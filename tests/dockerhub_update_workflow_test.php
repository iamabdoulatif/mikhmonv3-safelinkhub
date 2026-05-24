<?php

$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/dockerhub.yml';
$dockerfile = file_get_contents($root . '/Dockerfile.mikrotik');
$update = file_exists($root . '/include/app_update.php')
    ? file_get_contents($root . '/include/app_update.php')
    : '';
$head = file_get_contents($root . '/include/headhtml.php');
$manager = file_get_contents($root . '/manager.php');
$about = file_get_contents($root . '/include/about.php');

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
    'skopeo install step' => 'sudo apt-get install -y skopeo',
    'qemu setup step' => 'docker/setup-qemu-action@v3',
    'multi-arch manifest tags' => 'MANIFEST_TAGS: latest v1',
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
    'update panel renderer' => 'function mikhmon_render_update_panel',
    'RouterOS install guidance' => '/container',
    'local git build fallback' => 'mikhmon_update_local_build_stamp',
);

foreach ($updateChecks as $label => $needle) {
    if (strpos($update, $needle) === false) {
        fwrite(STDERR, $label . " missing from app update helper\n");
        exit(1);
    }
}

if (strpos($head, 'mikhmon_render_update_banner') !== false || strpos($manager, 'mikhmon_render_update_banner') !== false) {
    fwrite(STDERR, "app update notice must not render globally outside the about section\n");
    exit(1);
}

$aboutChecks = array(
    'about update helper include' => 'include_once(__DIR__ . \'/app_update.php\')',
    'about update panel' => 'mikhmon_render_update_panel',
    'github update step' => 'GitHub',
    'dockerhub update step' => 'DockerHub',
    'mikrotik update step' => 'MikroTik',
    'routeros command guidance' => '/container',
);

foreach ($aboutChecks as $label => $needle) {
    if (strpos($about, $needle) === false) {
        fwrite(STDERR, $label . " missing from about update section\n");
        exit(1);
    }
}

require_once $root . '/include/app_update.php';
$buildInfo = mikhmon_update_build_info();
if ($buildInfo['stamp'] === 'unknown' || $buildInfo['stamp'] === '') {
    fwrite(STDERR, "local build stamp must not be unknown\n");
    exit(1);
}

echo "dockerhub_update_workflow_test passed\n";
