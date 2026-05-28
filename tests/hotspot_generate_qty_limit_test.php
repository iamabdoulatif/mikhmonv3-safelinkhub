<?php
$root = dirname(__DIR__);

function assert_contains_text($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assert_not_contains_text($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assert_matches_text($pattern, $haystack, $message)
{
    if (!preg_match($pattern, $haystack)) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$compat = file_get_contents($root . '/include/mikhmon_compat.php');
$generator = file_get_contents($root . '/hotspot/generateuser.php');
$manager = file_get_contents($root . '/manager.php');

assert_matches_text(
    '/function\s+mikhmon_generate_ticket_limit\s*\(\)\s*\{[^}]*return\s+1000\s*;/s',
    $compat,
    'La limite de generation doit etre centralisee a 1000 tickets.'
);

assert_contains_text(
    $generator,
    '$maxGenerateQty = mikhmon_generate_ticket_limit();',
    'Le generateur admin doit utiliser la limite centralisee.'
);
assert_contains_text(
    $generator,
    '$qty = max(1, (int) $_POST[\'qty\']);',
    'La quantite doit etre convertie en entier positif avant les boucles.'
);
assert_contains_text(
    $generator,
    'if ($qty > $maxGenerateQty)',
    'Le generateur doit bloquer les lots qui depassent la limite cote serveur.'
);
assert_contains_text(
    $generator,
    'if ($qty > 500 && (int)$userl < 6)',
    'Le generateur doit refuser les gros lots avec une longueur de code trop courte.'
);
assert_contains_text(
    $generator,
    'La génération est limitée à',
    'Le generateur doit afficher une erreur claire quand la limite est depassee.'
);
assert_contains_text(
    $generator,
    'Pour générer plus de 500 tickets',
    'Le generateur doit expliquer la longueur minimale pour les lots de 501 a 1000 tickets.'
);
assert_contains_text(
    $generator,
    'max="<?= (int)$maxGenerateQty ?>"',
    'Le formulaire admin doit exposer la limite 1000.'
);
assert_contains_text(
    $manager,
    'max="<?= (int)mikhmon_generate_ticket_limit() ?>"',
    'Le formulaire gerant doit exposer la limite 1000.'
);
assert_contains_text(
    $generator,
    "ini_set('max_execution_time', 600);",
    'La generation de gros lots doit avoir un delai PHP adapte.'
);

assert_not_contains_text($generator, 'max="500"', 'Le generateur admin ne doit plus limiter a 500.');
assert_not_contains_text($manager, 'max="500"', 'Le generateur gerant ne doit plus limiter a 500.');

echo "OK\n";
