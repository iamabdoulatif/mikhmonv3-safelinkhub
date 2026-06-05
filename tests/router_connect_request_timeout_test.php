<?php

$admin = file_get_contents(__DIR__ . '/../admin.php');
$menu = file_get_contents(__DIR__ . '/../include/menu.php');

$expectations = array(
    'La route de connexion doit liberer le verrou de session avant l appel reseau.'
        => strpos($admin, 'session_write_close();') !== false,
    'La route de connexion doit limiter RouterOS a une seule tentative.'
        => strpos($admin, '$API->attempts = 1;') !== false,
    'La route de connexion doit supprimer le delai entre les tentatives.'
        => strpos($admin, '$API->delay = 0;') !== false,
    'La route de connexion doit fixer un timeout RouterOS court.'
        => strpos($admin, '$API->timeout = 3;') !== false,
    'Le navigateur doit interrompre une connexion trop longue.'
        => strpos($menu, 'timeout: 12000') !== false,
    'Le navigateur doit afficher un echec reseau explicite.'
        => strpos($menu, 'Connexion impossible ou délai dépassé.') !== false,
    'Le contexte settings doit rester un parametre distinct du nom de session.'
        => strpos($menu, 'split("&c=")') !== false
            && strpos($menu, 'connectUrl += "&c="') !== false,
);

foreach ($expectations as $message => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "OK\n";
