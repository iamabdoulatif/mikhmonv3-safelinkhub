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
    'function mikhmon_hotspot_secure_ticket_length',
    'Le generateur doit imposer une longueur minimale forte aux tickets.'
);

assert_contains_text(
    $content,
    'random_int(0, $max)',
    'Le generateur doit utiliser un aleatoire cryptographique quand disponible.'
);

assert_contains_text(
    $content,
    "mikhmon_hotspot_secure_random_string('mix2', 12)",
    'Le secours unique ne doit pas etre base sur une heure devinable.'
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

assert_contains_text(
    $content,
    'mikhmon_normalize_seller_lot_comment($commt, $sessionSellers)',
    'Le generateur doit nettoyer le commentaire final du lot avant ecriture MikroTik.'
);

assert_contains_text(
    $content,
    'mikhmon_seller_is_historical($sellerData)',
    'Le generateur ne doit pas proposer les comptes vendeurs historiques dans les nouveaux lots.'
);

echo "hotspot_generate_unique_names_test passed\n";
