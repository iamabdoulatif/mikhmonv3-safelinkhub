<?php

$root = dirname(__DIR__);
$portal = file_get_contents($root . '/css/mikhmon-portal.css');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');

if ($portal === false || $responsive === false) {
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
);

foreach ($checks as $label => $pattern) {
    if (!preg_match($pattern, $css)) {
        fwrite(STDERR, $label . " missing from mobile navbar responsive CSS\n");
        exit(1);
    }
}

echo "mobile_navbar_responsive_test passed\n";
