<?php
$root = dirname(__DIR__);
$files = array(
    'hotspot/quickuser.php',
    'hotspot/quickprint.php',
    'hotspot/listquickprint.php',
);

foreach ($files as $file) {
    $path = $root . '/' . $file;
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "Unable to read $file\n");
        exit(1);
    }

    if (strpos($source, 'mikhmon_currency_uses_integer_amounts') !== false
        && strpos($source, 'mikhmon_compat.php') === false) {
        fwrite(STDERR, "$file uses mikhmon helpers without loading mikhmon_compat.php\n");
        exit(1);
    }
}

echo "quickprint_compat_include_test passed\n";
