<?php

require_once __DIR__ . '/../include/seller_ticket_helper.php';

$sellers = array(
    'ferima' => array(
        'name' => 'Ferima',
        'session' => 'Safelink',
    ),
    'ib25' => array(
        'name' => 'Ib25',
        'session' => 'Safelink',
    ),
);

$matchingComments = array(
    'ferima',
    'Ferima',
    'vc-123-06.06.26-ferima',
    'vc-123-06.06.26-Ferima',
    'lot Ferima',
    'lot_ferima',
    'lot-ferima',
    'Ferima | MIKHMON_ACCOUNT role=vendeur session=Safelink account=ferima',
    'mikhmon-ipbinding|profile=1H|validity=1h|Ferima | MIKHMON_ACCOUNT role=vendeur session=Safelink account=ferima',
);

foreach ($matchingComments as $comment) {
    if (mikhmon_comment_seller_key($comment, $sellers) !== 'ferima') {
        fwrite(STDERR, 'comment must match Ferima seller: ' . $comment . PHP_EOL);
        exit(1);
    }
}

if (mikhmon_comment_seller_key('lot-ib25', $sellers) !== 'ib25') {
    fwrite(STDERR, 'comment must still match seller key aliases' . PHP_EOL);
    exit(1);
}

if (mikhmon_comment_seller_key('lot-ferimaa', $sellers) !== '') {
    fwrite(STDERR, 'partial suffixes must not match Ferima' . PHP_EOL);
    exit(1);
}

if (mikhmon_comment_base_lot('lot-Ferima', $sellers) !== 'lot') {
    fwrite(STDERR, 'base lot must strip display-name seller suffix' . PHP_EOL);
    exit(1);
}

if (mikhmon_comment_assign_seller('lot Ferima', 'ferima', $sellers) !== 'lot-Ferima') {
    fwrite(STDERR, 'assigning an already tagged display-name lot must not duplicate seller suffix' . PHP_EOL);
    exit(1);
}

if (mikhmon_comment_assign_seller('lot', 'ferima', $sellers) !== 'lot-Ferima') {
    fwrite(STDERR, 'assigning a plain lot must append the seller display name' . PHP_EOL);
    exit(1);
}

if (mikhmon_comment_seller_key('lot-Ferima', $sellers) !== 'ferima') {
    fwrite(STDERR, 'display-name lot comments must still match the seller key' . PHP_EOL);
    exit(1);
}

echo "seller_comment_matching_test passed\n";
