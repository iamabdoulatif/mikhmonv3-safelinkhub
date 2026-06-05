<?php

$root = dirname(__DIR__);
$portal = file_get_contents($root . '/css/mikhmon-portal.css');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$compat = file_get_contents($root . '/include/mikhmon_compat.php');
$seller = file_get_contents($root . '/sellers.php');
$manager = file_get_contents($root . '/manager.php');

if ($portal === false || $responsive === false || $compat === false || $seller === false || $manager === false) {
    fwrite(STDERR, "could not read responsive navbar CSS files\n");
    exit(1);
}

if (preg_match('/\.seller-portal\s+#cpage,\s*\.manager-portal\s+#cpage\s*\{[^}]*display:\s*block\s*!important/is', $portal)) {
    fwrite(STDERR, "seller and manager mobile navbar must not force the page title visible\n");
    exit(1);
}

$css = $responsive . "\n" . $portal;
$checks = array(
    'portal hides seller and manager page title on mobile' => '/\.seller-portal\s+#cpage,\s*\.manager-portal\s+#cpage\s*\{[^}]*display:\s*none\s*!important/is',
    'global mobile navbar uses flex row' => '/#navbar\.navbar\s*\{[^}]*display:\s*flex/is',
    'mobile hamburger keeps fixed touch width' => '/#navbar\s+\.navbar-left\s+#openNav\s*\{[^}]*width:\s*44px/is',
    'mobile page title is hidden globally' => '/#navbar\s+\.navbar-left\s+#cpage\s*\{[^}]*display:\s*none\s*!important/is',
    'mobile navbar right side can shrink' => '/#navbar\s+\.navbar-right\s*\{[^}]*display:\s*flex[^}]*flex:\s*1 1 auto[^}]*min-width:\s*0/is',
    'session selector is capped on phones' => '/#navbar\s+\.navbar-right\s+\.connect\.optfa\s*\{[^}]*max-width:\s*130px/is',
    'seller and manager actions use three equal mobile columns' => '/\.seller-portal\s+#navbar\s+\.navbar-right,\s*\.manager-portal\s+#navbar\s+\.navbar-right\s*\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\)/is',
    'portal actions can wrap their labels on phones' => '/\.seller-portal\s+#navbar\s+\.portal-nav-action,\s*\.manager-portal\s+#navbar\s+\.portal-nav-action\s*\{[^}]*white-space:\s*normal/is',
    'portal hamburger column stays compact on phones' => '/\.seller-portal\s+#navbar\s+\.navbar-left,\s*\.manager-portal\s+#navbar\s+\.navbar-left\s*\{[^}]*flex:\s*0 0 50px/is',
);

foreach ($checks as $label => $pattern) {
    if (!preg_match($pattern, $css)) {
        fwrite(STDERR, $label . " missing from mobile navbar responsive CSS\n");
        exit(1);
    }
}

foreach (array($compat, $seller, $manager) as $contents) {
    if (strpos($contents, 'portal-nav-action') === false) {
        fwrite(STDERR, "portal navbar action hook missing\n");
        exit(1);
    }
}

echo "mobile_navbar_responsive_test passed\n";
