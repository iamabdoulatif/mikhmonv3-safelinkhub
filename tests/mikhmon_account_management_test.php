<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

if (!function_exists('mikhmon_delete_assignment_line_in_file')) {
  fwrite(STDERR, 'account config helper must delete one assignment line without re-including stale config' . PHP_EOL);
  exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'mikhmon-accounts-');
file_put_contents($tmp, "<?php\n" . '$sellers_data = array();' . "\n");

mikhmon_replace_assignment_line_in_file($tmp, 'sellers_data', 'alpha', array(
  'password' => 'old-pass',
  'name' => 'Alpha Seller',
  'session' => 'ALB-TECH',
  'commission' => 15,
));
mikhmon_replace_assignment_line_in_file($tmp, 'sellers_data', 'beta', array(
  'password' => 'beta-pass',
  'name' => 'Beta Seller',
  'session' => 'ALB-TECH',
  'commission' => 10,
));

if (!mikhmon_delete_assignment_line_in_file($tmp, 'sellers_data', 'alpha')) {
  fwrite(STDERR, 'account config helper failed to delete an existing account' . PHP_EOL);
  @unlink($tmp);
  exit(1);
}

$sellers_data = array();
include $tmp;
if (isset($sellers_data['alpha']) || !isset($sellers_data['beta'])) {
  fwrite(STDERR, 'deleted account must be removed from config while other accounts remain' . PHP_EOL);
  @unlink($tmp);
  exit(1);
}

mikhmon_replace_assignment_line_in_file($tmp, 'sellers_data', 'gamma', array(
  'password' => 'new-pass',
  'name' => 'Gamma Seller',
  'session' => 'ALB-TECH',
  'commission' => 10,
), 'beta');

$sellers_data = array();
include $tmp;
@unlink($tmp);

if (isset($sellers_data['beta']) || !isset($sellers_data['gamma'])) {
  fwrite(STDERR, 'renaming an account must replace the old identifier with the new one' . PHP_EOL);
  exit(1);
}

if ($sellers_data['gamma']['name'] !== 'Gamma Seller' || $sellers_data['gamma']['password'] !== 'new-pass') {
  fwrite(STDERR, 'renaming an account must also update display name and password' . PHP_EOL);
  exit(1);
}

$page = file_get_contents(__DIR__ . '/../settings/manage_sellers.php');
$needles = array(
  'update_seller_account',
  'update_manager_account',
  'new_seller_user',
  'new_manager_user',
  'edit_seller_',
  'edit_manager_',
);

foreach ($needles as $needle) {
  if (strpos($page, $needle) === false) {
    fwrite(STDERR, 'manage sellers page missing account edit support: ' . $needle . PHP_EOL);
    exit(1);
  }
}

echo "mikhmon_account_management_test passed\n";
