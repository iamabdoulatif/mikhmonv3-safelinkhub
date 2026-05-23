<?php
/*
 * Test statique : vérifie que les fichiers CSS centralisent
 * correctement les règles responsive pour toutes les sections.
 */

$root     = dirname(__DIR__);
$portal   = file_get_contents($root . '/css/mikhmon-portal.css');
$respons  = file_get_contents($root . '/css/mikhmon-responsive.css');
$helpers  = file_get_contents($root . '/include/pppoe_helpers.php');
$fraud    = file_get_contents($root . '/settings/fraud.php');

if ($portal === false || $respons === false || $helpers === false || $fraud === false) {
    fwrite(STDERR, "could not read required files\n");
    exit(1);
}

$passed = true;

// 1. Le CSS PPP doit être dans mikhmon-responsive.css (pas inline dans pppoe_helpers.php)
if (strpos($helpers, '<style>') !== false) {
    fwrite(STDERR, "FAIL: pppoe_helpers.php contient encore un bloc <style> inline\n");
    $passed = false;
}

if (strpos($respons, '.ppp-action-bar') === false) {
    fwrite(STDERR, "FAIL: .ppp-action-bar manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

if (strpos($respons, '.ppp-responsive-table') === false) {
    fwrite(STDERR, "FAIL: .ppp-responsive-table manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

if (strpos($respons, '.ppp-form-page') === false) {
    fwrite(STDERR, "FAIL: .ppp-form-page manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

// 2. Le CSS anti-fraude doit être dans mikhmon-portal.css (pas inline dans fraud.php)
if (strpos($fraud, '<style>') !== false) {
    fwrite(STDERR, "FAIL: fraud.php contient encore un bloc <style> inline\n");
    $passed = false;
}

if (strpos($portal, '.fr-wrap') === false) {
    fwrite(STDERR, "FAIL: .fr-wrap manquant dans mikhmon-portal.css\n");
    $passed = false;
}

if (strpos($portal, '.fr-header') === false) {
    fwrite(STDERR, "FAIL: .fr-header manquant dans mikhmon-portal.css\n");
    $passed = false;
}

if (strpos($portal, '.fr-stats') === false) {
    fwrite(STDERR, "FAIL: .fr-stats manquant dans mikhmon-portal.css\n");
    $passed = false;
}

if (strpos($portal, '.fr-incident') === false) {
    fwrite(STDERR, "FAIL: .fr-incident manquant dans mikhmon-portal.css\n");
    $passed = false;
}

// 3. Vérifications tableaux globaux
if (strpos($respons, '.table-responsive') === false) {
    fwrite(STDERR, "FAIL: .table-responsive manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

if (strpos($respons, '.overflow.box-bordered') === false) {
    fwrite(STDERR, "FAIL: règle overflow.box-bordered manquante dans mikhmon-responsive.css\n");
    $passed = false;
}

// 4. Vérifications PPP responsive breakpoint
if (strpos($respons, '@media (max-width: 760px)') === false) {
    fwrite(STDERR, "FAIL: breakpoint 760px PPP manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

// 5. Vérification anti-fraude responsive (breakpoints mobiles)
if (strpos($portal, '@media (max-width: 768px)') === false) {
    fwrite(STDERR, "FAIL: breakpoint 768px anti-fraude manquant dans mikhmon-portal.css\n");
    $passed = false;
}

if (strpos($portal, '@media (max-width: 480px)') === false) {
    fwrite(STDERR, "FAIL: breakpoint 480px anti-fraude manquant dans mikhmon-portal.css\n");
    $passed = false;
}

// 6. La fonction mikhmon_ppp_responsive_css doit exister mais ne pas afficher de CSS inline
if (strpos($helpers, 'function mikhmon_ppp_responsive_css') === false) {
    fwrite(STDERR, "FAIL: fonction mikhmon_ppp_responsive_css disparue de pppoe_helpers.php\n");
    $passed = false;
}

// 7. Pas de duplication PPPoE dans le tableau de bord
$home = file_get_contents($root . '/dashboard/home.php');
if ($home === false) {
    fwrite(STDERR, "FAIL: impossible de lire dashboard/home.php\n");
    $passed = false;
} else {
    $pppoeCardCount = substr_count($home, 'dashboard-pppoe-card');
    if ($pppoeCardCount > 1) {
        fwrite(STDERR, "FAIL: dashboard/home.php contient $pppoeCardCount occurrences de dashboard-pppoe-card (max 1)\n");
        $passed = false;
    }
    // Hotspot doit apparaître avant PPPoE
    $hotspotPos = strpos($home, 'dashboard-hotspot-card');
    $pppoePos   = strpos($home, 'dashboard-pppoe-card');
    if ($hotspotPos !== false && $pppoePos !== false && $hotspotPos > $pppoePos) {
        fwrite(STDERR, "FAIL: le bloc Hotspot doit apparaître avant le bloc PPPoE dans dashboard/home.php\n");
        $passed = false;
    }
}

// 8. Réduction hauteur overflow mobile présente
if (strpos($respons, 'max-height: 60vh') === false) {
    fwrite(STDERR, "FAIL: max-height: 60vh mobile overflow manquant dans mikhmon-responsive.css\n");
    $passed = false;
}

if ($passed) {
    echo "responsive_comprehensive_test passed\n";
} else {
    exit(1);
}
