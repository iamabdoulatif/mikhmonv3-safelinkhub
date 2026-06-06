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

function assert_not_contains_text($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_contains_text(
    $content,
    'include/seller_ticket_helper.php',
    'Le generateur doit charger le helper de commentaires vendeurs.'
);

assert_contains_text(
    $content,
    '$adcomment = mikhmon_comment_assign_seller($adcomment, $sellerId, $sessionSellers);',
    'La generation doit assigner le vendeur avec son nom affiche via mikhmon_comment_assign_seller().'
);

assert_not_contains_text(
    $content,
    '$sellerSuffix = "-" . strtolower($sellerId);',
    'La generation ne doit pas reimplementer un suffixe base uniquement sur l identifiant vendeur.'
);

assert_contains_text(
    $content,
    'hotspot=users&comment=" . rawurlencode($commt) . "&session=" . rawurlencode($session)',
    'Apres generation, le generateur doit ouvrir la liste filtree sur le lot cree pour impression/controle.'
);

echo "hotspot_generate_seller_assignment_test passed\n";
