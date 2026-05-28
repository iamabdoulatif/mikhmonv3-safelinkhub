<?php
$root = dirname(__DIR__);

function assert_contains_text($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
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

assert_matches_text(
    '/function\s+mikhmon_hotspot_fast_generate_threshold\s*\(\)\s*\{[^}]*return\s+20\s*;/s',
    $compat,
    'Le seuil du mode rapide doit etre centralise a 20 tickets.'
);

assert_matches_text(
    '/function\s+mikhmon_hotspot_fast_generate_chunk_size\s*\(\)\s*\{[^}]*return\s+150\s*;/s',
    $compat,
    'La taille des morceaux batch doit etre centralisee a 150 tickets.'
);

assert_contains_text(
    $generator,
    'function mikhmon_hotspot_add_users_fast',
    'Le generateur doit avoir un chemin rapide pour les gros lots.'
);

assert_contains_text(
    $generator,
    '/system/script/add',
    'Le chemin rapide doit creer un script RouterOS temporaire.'
);

assert_contains_text(
    $generator,
    '/system/script/run',
    'Le chemin rapide doit executer le script RouterOS temporaire.'
);

assert_contains_text(
    $generator,
    "array('.id' => \$scriptId)",
    'RouterOS attend .id pour executer un script via API, pas numbers.'
);

assert_contains_text(
    $generator,
    '/system/script/remove',
    'Le chemin rapide doit nettoyer le script RouterOS temporaire.'
);

assert_contains_text(
    $generator,
    'mikhmon_hotspot_add_users_slow',
    'L ancien ajout unitaire doit rester disponible comme repli.'
);

assert_contains_text(
    $generator,
    'if (!$addedFast) {',
    'Le generateur doit basculer sur le repli si le mode rapide ne demarre pas.'
);

assert_contains_text(
    $generator,
    'mikhmon_routeros_response_error',
    'Le mode rapide doit verifier les erreurs RouterOS avant de continuer.'
);

assert_contains_text(
    $generator,
    'array_chunk($users, $chunkSize)',
    'Le mode rapide doit couper les gros lots en morceaux pour eviter les scripts trop lourds.'
);

assert_contains_text(
    $generator,
    'array_slice($users, $chunkIndex * $chunkSize)',
    'Si un morceau batch echoue, le generateur doit reprendre seulement les utilisateurs restants en mode unitaire.'
);

if (substr_count($generator, '"/ip/hotspot/user/add"') + substr_count($generator, "'/ip/hotspot/user/add'") !== 1) {
    fwrite(STDERR, "L ajout direct /ip/hotspot/user/add doit etre regroupe dans un seul helper de repli.\n");
    exit(1);
}

echo "hotspot_generate_fast_batch_test passed\n";
