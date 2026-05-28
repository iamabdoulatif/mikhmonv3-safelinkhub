<?php
$file = dirname(__DIR__) . '/hotspot/generateuser.php';
$content = file_get_contents($file);

function assert_contains_text($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_contains_text(
    $content,
    'function mikhmon_hotspot_existing_user_name_map',
    'Le generateur doit charger les noms deja presents sur MikroTik avant un lot.'
);

assert_contains_text(
    $content,
    'function mikhmon_hotspot_unique_credentials',
    'Le generateur doit garantir un identifiant unique avant ajout.'
);

assert_contains_text(
    $content,
    '$usedHotspotNames = mikhmon_hotspot_existing_user_name_map($API);',
    'La generation doit comparer le lot aux utilisateurs deja presents.'
);

assert_contains_text(
    $content,
    'mikhmon_hotspot_unique_fallback_name',
    'La generation doit avoir un secours unique si la combinaison choisie produit trop de doublons.'
);

assert_contains_text(
    $content,
    'mikhmon_hotspot_accept_unique_name',
    'Chaque nom retenu doit etre marque comme utilise avant le prochain ticket.'
);

echo "hotspot_generate_unique_names_test passed\n";
