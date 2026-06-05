<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$record = mikhmon_build_record_script('20', '5m', '05-MINS');
$lock = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
$onLogin = mikhmon_build_on_login_script('remc', '20', '5m', '0', 'Enable', $record, $lock);
$paidRemoveOnLogin = mikhmon_build_on_login_script('rem', '20', '5m', '0', 'Disable', $record, '');
$freeRemoveOnLogin = mikhmon_build_on_login_script('rem', '0', '5m', '0', 'Disable', $record, '');
$monitor = mikhmon_build_expire_monitor_script('05-MINS', 'remove');

$checks = array(
  'on-login sets expiration comment' => '/ip hotspot user set comment="$expKey"',
  'on-login creates exact user scheduler' => 'comment="mikhmon-user-expire"',
  'on-login semicolon before expiry scheduler' => '/sys sch remove [find where name="$user" and comment="mikhmon-temp-expire"];:local es',
  'on-login uses ISO start-date for ROS7 compat' => 'start-date=$edf',
  'temporary scheduler uses normalized clock date' => 'start-date=$dateKey',
  'on-login avoids RouterOS 7.9 nil date comparison' => ':if ([:pick $clockDate 4 5] = "-")',
  'on-login avoids arithmetic directly inside pick' => ':local ets ($es + 1);:local et [:pick $expKey $ets [:len $expKey]]',
  'on-login converts legacy date to ISO for scheduler' => ':if ([:pick $ed 3 4] = "/")',
  'on-login ISO conversion builds yyyy-mm-dd' => ':set edf ($xy . "-" . $xmm . "-" . $xd)',
  'on-login embeds user in expire event' => '/ip hotspot user remove [find where name=\\"" . $user . "\\"]',
  'record script quotes source for ROS7 compat' => 'source="$dateKey"',
  'profile monitor parses month names explicitly' => ':if ($month = "may") do={ :set expmm "05";};',
  'profile monitor checks slash date slices' => '[:pick $comment 3 4] = "/"',
  'profile monitor compares parsed dates' => ':if (($expdate < $nowdate) or ($expdate = $nowdate and $exptime <= $nowtime))',
  'profile monitor supports RouterOS ISO clock' => '[:pick $date 4 5] = "-"',
  'profile monitor supports RouterOS legacy clock' => '[:pick $date 3 4] = "/"',
  'profile monitor removes active session' => '/ip hotspot active remove [find where user=$name]',
  'keeps ticket MAC lock' => 'set mac-address=$mac',
);

foreach ($checks as $label => $needle) {
  $haystack = (strpos($label, 'profile monitor') === 0) ? $monitor : $onLogin;
  if (strpos($haystack, $needle) === false) {
    fwrite(STDERR, $label . ' missing: ' . $needle . PHP_EOL);
    exit(1);
  }
}

if (strpos($onLogin, 'mikhmon-temp-expire') === false) {
  fwrite(STDERR, 'on-login must still use the temporary scheduler to calculate RouterOS next-run' . PHP_EOL);
  exit(1);
}

if (strpos($onLogin, '[:pick $expKey ($expSep + 1)') !== false || strpos($onLogin, '[:pick $expKey ($es + 1)') !== false) {
  fwrite(STDERR, 'on-login must not use arithmetic directly inside RouterOS :pick arguments' . PHP_EOL);
  exit(1);
}

if (strpos($paidRemoveOnLogin, '/system script add') === false || strpos($paidRemoveOnLogin, 'comment=mikhmon') === false) {
  fwrite(STDERR, 'paid Remove profiles must record sales for the dashboard' . PHP_EOL);
  exit(1);
}

if (strpos($freeRemoveOnLogin, '/system script add') !== false) {
  fwrite(STDERR, 'free Remove profiles must not create revenue records' . PHP_EOL);
  exit(1);
}

if (strlen($onLogin) > 4096) {
  fwrite(STDERR, 'on-login script must stay within MikroTik profile script length limits, got ' . strlen($onLogin) . PHP_EOL);
  exit(1);
}

echo "mikhmon_on_login_expiry_test passed\n";
