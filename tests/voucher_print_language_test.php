<?php
$root = dirname(__DIR__);
$print = file_get_contents($root . '/voucher/print.php');

if (strpos($print, "include('../include/lang.php');") === false) {
    fwrite(STDERR, "voucher/print.php doit charger include/lang.php avant de rendre les templates.\n");
    exit(1);
}

if (strpos($print, "include('../lang/' . \$langid . '.php');") === false) {
    fwrite(STDERR, "voucher/print.php doit charger le fichier de langue actif.\n");
    exit(1);
}

$languages = array(
    'en.php' => 'Sold by',
    'fr.php' => 'Vendu par',
    'es.php' => 'Vendido por',
    'id.php' => 'Dijual oleh',
    'tl.php' => 'Ibinenta ng',
);

foreach ($languages as $file => $expected) {
    $content = file_get_contents($root . '/lang/' . $file);
    if (strpos($content, '$_sold_by') === false || strpos($content, $expected) === false) {
        fwrite(STDERR, $file . " doit definir \$_sold_by avec: " . $expected . "\n");
        exit(1);
    }
}

echo "voucher_print_language_test passed\n";
