<?php
$root = dirname(__DIR__);

$manage = file_get_contents($root . '/settings/manage_sellers.php');
$admin = file_get_contents($root . '/admin.php');
$sessions = file_get_contents($root . '/settings/sessions.php');
$uplogo = file_get_contents($root . '/settings/uplogo.php');
$settings = file_get_contents($root . '/settings/settings.php');

$checks = array(
  'seller deletion must not use GET' => strpos($manage, "\$_GET['del_seller']") === false,
  'manager deletion must not use GET' => strpos($manage, "\$_GET['del_manager']") === false,
  'seller deletion links must not use query deletes' => strpos($manage, 'del_seller=') === false,
  'manager deletion links must not use query deletes' => strpos($manage, 'del_manager=') === false,
  'session deletion must be a POST action' => strpos($admin, "isset(\$_POST['remove_session'])") !== false,
  'logo deletion must be a POST action' => strpos($admin, "isset(\$_POST['remove_logo'])") !== false,
  'sessions page must render a remove_session form' => strpos($sessions, 'name="remove_session"') !== false,
  'logo page must render a remove_logo form' => strpos($uplogo, 'name="remove_logo"') !== false,
  'settings save must call csrf_guard' => strpos($settings, "isset(\$_POST['save'])") !== false && strpos($settings, 'csrf_guard();') !== false,
  'settings form must render csrf_field' => strpos($settings, 'csrf_field()') !== false,
);

foreach ($checks as $label => $ok) {
  if (!$ok) {
    fwrite(STDERR, $label . PHP_EOL);
    exit(1);
  }
}

echo "mikhmon_csrf_static_test passed\n";
