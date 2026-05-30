<?php
$root = dirname(__DIR__);
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$sessions = file_get_contents($root . '/settings/sessions.php');

if ($responsive === false || $sessions === false) {
    fwrite(STDERR, "FAIL: impossible de lire les fichiers de la liste des routeurs\n");
    exit(1);
}

$checks = array(
    'router cards expose a dedicated layout class' => '/class="box-group router-session-box"/',
    'router card keeps icon and content on one row' => '/\.router-session-box\s*\{[^}]*flex-wrap:\s*nowrap/is',
    'router card icon stays narrow on the left' => '/\.router-session-box\s+\.box-group-icon\s*\{[^}]*flex:\s*0\s+0\s+48px[^}]*text-align:\s*left/is',
    'router card text consumes remaining width' => '/\.router-session-box\s+\.box-group-area\s*\{[^}]*flex:\s*1\s+1\s+auto[^}]*min-width:\s*0[^}]*text-align:\s*left/is',
);

foreach ($checks as $label => $pattern) {
    $contents = $label === 'router cards expose a dedicated layout class' ? $sessions : $responsive;
    if (!preg_match($pattern, $contents)) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

echo "settings_router_mobile_layout_test passed\n";
