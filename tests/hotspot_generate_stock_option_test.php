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
    'Stock général',
    'Le generateur doit proposer une option de stock general sans vendeur.'
);

assert_contains_text(
    $content,
    'distribuer plus tard',
    'Le libelle doit expliquer que le lot pourra etre distribue plus tard.'
);

assert_contains_text(
    $content,
    'value=""<?= $selectedSellerId === \'\' ? \' selected\' : \'\' ?>',
    'L option sans vendeur doit etre selectionnable explicitement.'
);

assert_not_contains_text(
    $content,
    'name="seller_id"<?= !empty($sessionSellers) ? \' required="1"\' : \' disabled\' ?>',
    'Le choix du vendeur ne doit plus etre obligatoire.'
);

assert_not_contains_text(
    $content,
    '$generationError = isset($_transfer_select_vendor) ? strip_tags($_transfer_select_vendor) : \'Select a vendor.\';',
    'Le backend ne doit plus bloquer la generation sans vendeur.'
);

echo "hotspot_generate_stock_option_test passed\n";
