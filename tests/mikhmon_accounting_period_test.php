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

if ($period['total']['commission'] !== 450.0) {
  fwrite(STDERR, 'period commission expected 450 got ' . $period['total']['commission'] . PHP_EOL);
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

if ($period['days']['may/01/2026']['sellers']['beta']['commission'] !== 200.0 || $period['days']['may/01/2026']['sellers']['beta']['commission_rate'] !== 10) {
  fwrite(STDERR, 'may/01 beta commission must be fixed at 10 percent' . PHP_EOL);
  exit(1);
}

$alphaOnly = mikhmon_accounting_period_summary($sales, $sellers, '2026-05-01', '2026-05-03', 'alpha', '08:30', '08:30');
if ($alphaOnly['total']['count'] !== 1 || $alphaOnly['total']['revenue'] !== 1500.0 || isset($alphaOnly['days']['may/01/2026']['sellers']['alpha'])) {
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

$notice = mikhmon_accounting_notification_text('Alpha', '2026-05-01', '2026-05-03', '09:15', '2026-05-04', '2026-05-31', '18:30', array(
  'revenue' => 4500,
  'commission' => 450,
  'net' => 4050,
  'currency' => 'XOF',
  'cekindo' => array(),
));
if (strpos($notice, 'Alpha') === false
    || strpos($notice, '2026-05-01 au 2026-05-03') === false
    || strpos($notice, 'vente totale') === false
    || strpos($notice, 'XOF 4 500') === false
    || strpos($notice, 'commission de 10%') === false
    || strpos($notice, 'XOF 450') === false
    || strpos($notice, 'verser') === false
    || strpos($notice, 'XOF 4 050') === false) {
  fwrite(STDERR, 'accounting notification text must include seller totals, 10 percent commission, and net due' . PHP_EOL);
  exit(1);
}

$targets = mikhmon_accounting_notification_targets($period, $sellers);
sort($targets);
if ($targets !== array('alpha', 'beta')) {
  fwrite(STDERR, 'accounting notification targets must include sellers present in the summary' . PHP_EOL);
  exit(1);
}

$historicalSellers = mikhmon_accounting_historical_sellers($sales, 'ALB-TECH', array(
  'alpha' => $sellers['alpha'],
));
if (!isset($historicalSellers['beta']) || $historicalSellers['beta']['session'] !== 'ALB-TECH' || empty($historicalSellers['beta']['historical'])) {
  fwrite(STDERR, 'sales from sellers missing in local config must remain visible as historical sellers' . PHP_EOL);
  exit(1);
}
if (isset($historicalSellers['alpha'])) {
  fwrite(STDERR, 'historical seller recovery must not duplicate configured sellers' . PHP_EOL);
  exit(1);
}

$managerPage = file_get_contents(__DIR__ . '/../manager.php');
$adminPage = file_get_contents(__DIR__ . '/../settings/manage_sellers.php');
if (strpos($managerPage, "\$managerAllowedActions = array('dashboard', 'overview', 'accounting', 'tickets', 'vendors', 'logout')") === false) {
  fwrite(STDERR, 'manager page must allow manager seller accounting without admin dashboard access' . PHP_EOL);
  exit(1);
}
if (strpos($managerPage, 'Compte vendeur') === false || strpos($managerPage, 'Heure début') === false || strpos($managerPage, 'Heure fin') === false) {
  fwrite(STDERR, 'manager page must expose seller accounting by date and time range' . PHP_EOL);
  exit(1);
}
if (strpos($managerPage, 'mikhmon_accounting_notice_totals_for_targets($accountingSummary, $accountingNoticeTargets, $currency, $cekindo)') === false
    || strpos($managerPage, '$accountingNoticeTotals') === false) {
  fwrite(STDERR, 'manager accounting notifications must pass totals and currency' . PHP_EOL);
  exit(1);
}
if (strpos($managerPage, 'mikhmon_accounting_historical_sellers($getSales, $manager_session_name, $managerSellersData)') !== false
    || strpos($adminPage, 'mikhmon_accounting_historical_sellers($adminAccountingSales, $session, $adminAccountingSellersData)') !== false) {
  fwrite(STDERR, 'manager and admin accounting must not display sellers reconstructed as historical accounts' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'ms-section-accounting') === false) {
  fwrite(STDERR, 'admin sellers page must expose the accounting section' . PHP_EOL);
  exit(1);
}
if (strpos($adminPage, 'Heure début') === false || strpos($adminPage, 'Heure fin') === false || strpos($adminPage, 'acct_settled_at') === false || strpos($adminPage, 'acct_next_settled_at') === false) {
  fwrite(STDERR, 'admin sellers page must expose start and end accounting times' . PHP_EOL);
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
