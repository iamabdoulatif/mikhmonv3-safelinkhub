<?php
$root = dirname(__DIR__);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$failures = array();

foreach ($rii as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
        continue;
    }

    $path = $fileInfo->getPathname();
    $relative = str_replace($root . '/', '', $path);
    if (strpos($relative, 'tests/') === 0 || $relative === 'include/mikhmon_compat.php') {
        continue;
    }

    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }

    $usesMoneyHelper = preg_match('/mikhmon_(format_money_amount|parse_money_amount|currency_uses_integer_amounts)\s*\(/', $source);
    if ($usesMoneyHelper && strpos($source, 'mikhmon_compat.php') === false) {
        $failures[] = $relative;
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "Files use mikhmon money helpers without loading mikhmon_compat.php:\n");
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "mikhmon_helper_include_test passed\n";
