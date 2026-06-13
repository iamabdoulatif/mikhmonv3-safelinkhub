<?php

$admin = file_get_contents(__DIR__ . '/../admin.php');
$menu = file_get_contents(__DIR__ . '/../include/menu.php');
$manager = file_get_contents(__DIR__ . '/../manager.php');
$sellers = file_get_contents(__DIR__ . '/../sellers.php');

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
    'Le dashboard gerant doit charger RouterOS avec une configuration stable pour afficher les ventes.'
        => strpos($manager, '$managerShouldLoadRouterData = true;') !== false
            && strpos($manager, 'mikhmon_configure_routeros_api($API);') !== false
            && strpos($manager, '&& $managerShouldLoadRouterData') !== false,
    'Le dashboard gerant ne doit pas lire toute la table des tickets au chargement.'
        => strpos($manager, '$managerShouldLoadFullTicketData = ($action !== \'dashboard\');') !== false
            && strpos($manager, '$unusedAll = $managerShouldLoadFullTicketData') !== false,
    'Le dashboard vendeur doit charger RouterOS avec une configuration stable pour afficher les ventes.'
        => strpos($sellers, ": ((\$idbl !== '' || \$idhr !== '') ? 'sales' : 'dashboard');") !== false
            && strpos($sellers, '$sellerShouldLoadRouterData = true;') !== false
            && strpos($sellers, 'mikhmon_configure_routeros_api($API);') !== false
            && strpos($sellers, '&& $sellerShouldLoadRouterData') !== false,
    'Le dashboard vendeur ne doit pas lire toute la table des tickets au chargement.'
        => strpos($sellers, '$sellerShouldLoadFullTicketData = ($action !== \'dashboard\');') !== false
            && strpos($sellers, 'if ($sellerShouldLoadFullTicketData)') !== false,
    'Le timeout RouterOS global doit rester court pour eviter les pages bloquees.'
        => strpos(file_get_contents(__DIR__ . '/../include/mikhmon_compat.php'), '$api->timeout = 12;') !== false,
);

foreach ($expectations as $message => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "OK\n";
