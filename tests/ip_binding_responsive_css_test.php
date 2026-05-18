<?php
$css = file_get_contents(__DIR__ . '/../css/mikhmon-portal.css');
if ($css === false) {
    fwrite(STDERR, 'could not read mikhmon-portal.css' . PHP_EOL);
    exit(1);
}

$checks = array(
    'duration grid class' => '.ipbind-duration-grid',
    'mobile breakpoint' => '@media (max-width: 560px)',
    'single mobile column' => 'grid-template-columns: minmax(0, 1fr)',
    'touch table scrolling' => '-webkit-overflow-scrolling: touch',
    'full-width mobile search' => '.ipbind-search',
    'centered mobile text' => 'text-align: center;',
    'centered mobile table headers' => '.ipbind-table-wrap th',
    'centered mobile table cells' => '.ipbind-table-wrap td',
    'centered panel margin' => 'margin: 12px auto',
    'centered navbar group' => '#navbar .navbar-right',
    'centered login tabs' => 'justify-content: center;',
    'polished ip binding radius' => 'border-radius: 8px;',
);

foreach ($checks as $label => $needle) {
    if (strpos($css, $needle) === false) {
        fwrite(STDERR, $label . ' missing from responsive CSS' . PHP_EOL);
        exit(1);
    }
}

echo "ip_binding_responsive_css_test passed\n";
