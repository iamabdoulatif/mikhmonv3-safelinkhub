<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/accounting_notifications.php';

$sellers = array(
  'alpha' => array('name' => 'Alpha', 'commission' => 10),
  'beta' => array('name' => 'Beta', 'commission' => 20),
);

$sales = array(
  array('date' => 'may/01/2026', 'time' => '08:00:00', 'user' => 'A1', 'price' => '1000', 'profile' => '1H', 'comment' => 'lot-alpha'),
  array('date' => 'may/01/2026', 'time' => '10:00:00', 'user' => 'B1', 'price' => '2 000', 'profile' => '2H', 'comment' => 'beta'),
  array('date' => 'may/02/2026', 'time' => '09:00:00', 'user' => 'A2', 'price' => '1500', 'profile' => '1H', 'comment' => 'lot-alpha'),
  array('date' => 'may/04/2026', 'time' => '11:00:00', 'user' => 'A3', 'price' => '5000', 'profile' => '1H', 'comment' => 'lot-alpha'),
);

$period = mikhmon_accounting_period_summary($sales, $sellers, '2026-05-01', '2026-05-03');

if ($period['total']['count'] !== 3) {
  fwrite(STDERR, 'period count expected 3 got ' . $period['total']['count'] . PHP_EOL);
  exit(1);
}

if ($period['total']['revenue'] !== 4500.0) {
  fwrite(STDERR, 'period revenue expected 4500 got ' . $period['total']['revenue'] . PHP_EOL);
  exit(1);
}

if ($period['total']['commission'] !== 650.0) {
  fwrite(STDERR, 'period commission expected 650 got ' . $period['total']['commission'] . PHP_EOL);
  exit(1);
}

if (!isset($period['days']['may/03/2026']) || $period['days']['may/03/2026']['total']['count'] !== 0) {
  fwrite(STDERR, 'empty day must be included in the accounting cut' . PHP_EOL);
  exit(1);
}

if ($period['days']['may/01/2026']['sellers']['alpha']['revenue'] !== 1000.0) {
  fwrite(STDERR, 'may/01 alpha revenue expected 1000' . PHP_EOL);
  exit(1);
}

if ($period['days']['may/01/2026']['sellers']['beta']['commission'] !== 400.0) {
  fwrite(STDERR, 'may/01 beta commission expected 400' . PHP_EOL);
  exit(1);
}

$alphaOnly = mikhmon_accounting_period_summary($sales, $sellers, '2026-05-01', '2026-05-03', 'alpha');
if ($alphaOnly['total']['count'] !== 2 || isset($alphaOnly['days']['may/01/2026']['sellers']['beta'])) {
  fwrite(STDERR, 'seller filter must keep only the selected vendor' . PHP_EOL);
  exit(1);
}

$bounds = mikhmon_accounting_month_bounds('may2026');
if ($bounds['from'] !== '2026-05-01' || $bounds['to'] !== '2026-05-31') {
  fwrite(STDERR, 'month bounds expected 2026-05-01 to 2026-05-31' . PHP_EOL);
  exit(1);
}

$leapBounds = mikhmon_accounting_month_bounds('feb2024');
if ($leapBounds['from'] !== '2024-02-01' || $leapBounds['to'] !== '2024-02-29') {
  fwrite(STDERR, 'month bounds must handle leap years without PHP calendar extension' . PHP_EOL);
  exit(1);
}

if (strpos(file_get_contents(__DIR__ . '/../include/mikhmon_compat.php'), 'cal_days_in_month') !== false) {
  fwrite(STDERR, 'month bounds must not require the optional PHP calendar extension' . PHP_EOL);
  exit(1);
}

if (mikhmon_accounting_settlement_time('09:15') !== '09:15:00') {
  fwrite(STDERR, 'settlement time must normalize HH:MM to HH:MM:SS' . PHP_EOL);
  exit(1);
}

if (mikhmon_accounting_settlement_time('bad', '18:30:22') !== '18:30:22') {
  fwrite(STDERR, 'invalid settlement time must use the fallback time' . PHP_EOL);
  exit(1);
}

$notice = mikhmon_accounting_notification_text('Alpha', '2026-05-01', '2026-05-03', '09:15', '2026-05-04', '2026-05-31', '18:30');
if (strpos($notice, 'Alpha') === false || strpos($notice, '2026-05-01 au 2026-05-03') === false || strpos($notice, '09:15:00') === false || strpos($notice, '2026-05-04 au 2026-05-31') === false || strpos($notice, '18:30:00') === false) {
  fwrite(STDERR, 'accounting notification text must include seller, current period/time, and next period/time' . PHP_EOL);
  exit(1);
}

$targets = mikhmon_accounting_notification_targets($period, $sellers);
sort($targets);
if ($targets !== array('alpha', 'beta')) {
  fwrite(STDERR, 'accounting notification targets must include sellers present in the summary' . PHP_EOL);
  exit(1);
}

$managerPage = file_get_contents(__DIR__ . '/../manager.php');
$adminPage = file_get_contents(__DIR__ . '/../settings/manage_sellers.php');
if (strpos($managerPage, "\$managerAllowedActions = array('dashboard', 'tickets', 'logout')") === false) {
  fwrite(STDERR, 'manager page must restrict accounting to admin-facing tools only' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'ms-section-accounting') === false) {
  fwrite(STDERR, 'admin sellers page must expose the accounting section' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'Heure du compte') === false || strpos($adminPage, 'acct_settled_at') === false) {
  fwrite(STDERR, 'admin sellers page must expose the accounting settlement time' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'admin_send_accounting_notice') === false) {
  fwrite(STDERR, 'admin sellers page must expose vendor accounting notification action' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'json_encode($session') === false || strpos($adminPage, "action: 'delete_day_sales'") === false) {
  fwrite(STDERR, 'admin delete day sales buttons must pass the router session to the AJAX endpoint' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'id="acct-delete-date"') === false || strpos($adminPage, 'deleteSelectedAccountingDate') === false) {
  fwrite(STDERR, 'admin accounting page must expose a date selector for deleting accounting dates' . PHP_EOL);
  exit(1);
}
$accountingAction = file_get_contents(__DIR__ . '/../process/accounting_action.php');
if (strpos($accountingAction, '$session = preg_replace') === false) {
  fwrite(STDERR, 'accounting AJAX actions must sanitize and use the posted router session' . PHP_EOL);
  exit(1);
}

$sellerPage = file_get_contents(__DIR__ . '/../sellers.php');
if (strpos($sellerPage, 'accounting_notifications_for_seller') === false) {
  fwrite(STDERR, 'seller page must display accounting notifications' . PHP_EOL);
  exit(1);
}

echo "mikhmon_accounting_period_test passed\n";
