<?php
$root = dirname(__DIR__);
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$home = file_get_contents($root . '/dashboard/home.php');
$aload = file_get_contents($root . '/dashboard/aload.php');

if ($responsive === false || $home === false || $aload === false) {
    fwrite(STDERR, "FAIL: impossible de lire les fichiers du dashboard\n");
    exit(1);
}

$checks = array(
    'mobile summary row uses nowrap flex layout' => '/@media\s+screen\s+and\s+\(max-width:\s*749px\)[^{]*\{.*?\.dashboard-top-row\s+\.box-group,\s*#r_4\s+\.box-group\s*\{[^}]*flex-wrap:\s*nowrap/is',
    'mobile summary icon stays narrow on the left' => '/\.dashboard-top-row\s+\.box-group-icon,\s*#r_4\s+\.box-group-icon\s*\{[^}]*flex:\s*0\s+0\s+48px[^}]*text-align:\s*left/is',
    'mobile summary text consumes remaining width' => '/\.dashboard-top-row\s+\.box-group-area,\s*#r_4\s+\.box-group-area\s*\{[^}]*flex:\s*1\s+1\s+auto[^}]*min-width:\s*0[^}]*text-align:\s*left/is',
);

foreach ($checks as $label => $pattern) {
    if (!preg_match($pattern, $responsive)) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

foreach (array('dashboard/home.php' => $home, 'dashboard/aload.php' => $aload) as $path => $contents) {
    if (strpos($contents, 'class="row dashboard-top-row"') === false) {
        fwrite(STDERR, "FAIL: $path doit identifier la ligne de synthèse mobile\n");
        exit(1);
    }
}

echo "dashboard_summary_mobile_layout_test passed\n";
