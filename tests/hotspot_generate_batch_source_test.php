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

assert_not_contains_text(
    $content,
    'implode("\\n", $lines)',
    'Le script batch ne doit pas contenir de retours ligne: RouterosAPI::write les decoupe en mots API.'
);

assert_contains_text(
    $content,
    '\'source\' => implode(\'\', $lines)',
    'Le script batch doit envoyer une source compacte avec des commandes separees par point-virgule.'
);

echo "hotspot_generate_batch_source_test passed\n";
