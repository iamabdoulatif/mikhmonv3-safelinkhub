<?php

$root = dirname(__DIR__);
$readmePath = $root . '/dockerhub/README.md';
$workflowPath = $root . '/.github/workflows/dockerhub.yml';
$assetDir = $root . '/dockerhub/assets';

$failures = [];

$readme = file_get_contents($readmePath);
if ($readme === false) {
    $failures[] = 'DockerHub README is missing.';
    $readme = '';
}

$workflow = file_get_contents($workflowPath);
if ($workflow === false) {
    $failures[] = 'DockerHub workflow is missing.';
    $workflow = '';
}

$requiredReadmeSnippets = [
    'https://raw.githubusercontent.com/iamabdoulatif/mikhmonv3-safelinkhub/main/dockerhub/assets/admin-login-readable.png',
    'https://raw.githubusercontent.com/iamabdoulatif/mikhmonv3-safelinkhub/main/dockerhub/assets/vendor-login-readable.png',
    'https://raw.githubusercontent.com/iamabdoulatif/mikhmonv3-safelinkhub/main/dockerhub/assets/mobile-ip-binding-readable.png',
    'width="720"',
    'latif225/mikhmonv3-safelinkhub:latest',
    'latif225/mikhmonv3-safelinkhub:v1',
    'skopeo',
];

foreach ($requiredReadmeSnippets as $snippet) {
    if (strpos($readme, $snippet) === false) {
        $failures[] = "DockerHub README does not contain expected snippet: {$snippet}";
    }
}

if (strpos($readme, 'mikhmon-dockerhub-assets') !== false) {
    $failures[] = 'DockerHub README still references the old external screenshot repository.';
}

$requiredAssets = [
    'admin-login-readable.png' => [700, 900],
    'vendor-login-readable.png' => [700, 800],
    'mobile-ip-binding-readable.png' => [500, 800],
];

foreach ($requiredAssets as $asset => [$minWidth, $minHeight]) {
    $path = $assetDir . '/' . $asset;
    if (!is_file($path)) {
        $failures[] = "Missing DockerHub screenshot asset: {$asset}";
        continue;
    }

    $size = getimagesize($path);
    if ($size === false) {
        $failures[] = "DockerHub screenshot asset is not a readable image: {$asset}";
        continue;
    }

    if ($size[0] < $minWidth || $size[1] < $minHeight) {
        $failures[] = "DockerHub screenshot asset is too small: {$asset} ({$size[0]}x{$size[1]})";
    }
}

$requiredWorkflowSnippets = [
    'peter-evans/dockerhub-description@v4',
    'repository: latif225/mikhmonv3-safelinkhub',
    'readme-filepath: ./dockerhub/README.md',
    'short-description: "Mikhmon v3 pour MikroTik Hotspot',
];

foreach ($requiredWorkflowSnippets as $snippet) {
    if (strpos($workflow, $snippet) === false) {
        $failures[] = "DockerHub workflow does not contain expected snippet: {$snippet}";
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "DockerHub description checks passed." . PHP_EOL;
