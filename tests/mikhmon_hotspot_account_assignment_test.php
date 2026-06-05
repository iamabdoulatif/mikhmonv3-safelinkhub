<?php
$root = dirname(__DIR__);
$helper = $root . '/include/hotspot_account_assignment.php';

if (!is_file($helper)) {
    fwrite(STDERR, 'hotspot account assignment helper is missing' . PHP_EOL);
    exit(1);
}

require_once $helper;

$users = array(
    array('.id' => '*1', 'name' => 'diallo', 'password' => 'secret1', 'profile' => 'default', 'comment' => 'Diallo Market'),
    array('.id' => '*2', 'name' => 'aicha', 'password' => 'secret2', 'profile' => '01-JOUR', 'comment' => 'Aicha'),
    array('.id' => '*3', 'name' => 'manager-1', 'profile' => 'default', 'comment' => 'Manager Zone'),
    array('.id' => '*4', 'name' => 'vendeur01', 'password' => 'secret4', 'profile' => 'default', 'comment' => 'Existing Seller'),
    array('.id' => '*5', 'name' => '', 'password' => 'secret5', 'profile' => 'default'),
    array('.id' => '*6', 'name' => 'recovery', 'password' => 'recovery-pass', 'profile' => 'default', 'comment' => 'Recovery Shop | MIKHMON_ACCOUNT role=gerant session=ALB-TECH account=recovery'),
);
$sellers = array(
    'vendeur01' => array('password' => 'x', 'name' => 'Existing Seller', 'session' => 'ALB-TECH', 'commission' => 10),
);
$managers = array(
    'manager2' => array('password' => 'x', 'name' => 'Manager Two', 'session' => 'ALB-TECH'),
);
$ipBindings = array(
    array('.id' => '*b1', 'comment' => 'mikhmon-ipbinding|profile=1H|validity=1h|Abou kei'),
    array('.id' => '*b2', 'comment' => 'Papa tchegbe'),
    array('.id' => '*b3', 'comment' => 'Diallo Market'),
    array('.id' => '*b4', 'comment' => ''),
    array('.id' => '*b5', 'comment' => 'bema | MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=bema'),
    array('.id' => '*b6', 'comment' => 'mikhmon-ipbinding|profile=1H|validity=1h|Nabala | MIKHMON_ACCOUNT role=gerant session=ALB-TECH account=Nabala'),
);
$routerUsers = array(
    array('.id' => '*r1', 'name' => 'router_seller', 'group' => 'mikhmon-vendeur', 'comment' => mikhmon_hotspot_assignment_comment('Router Seller', 'ALB-TECH', 'seller', 'router_seller', password_hash('router-secret', PASSWORD_DEFAULT))),
    array('.id' => '*r2', 'name' => 'router_manager', 'group' => 'mikhmon-revendeur', 'comment' => mikhmon_hotspot_assignment_comment('Router Manager', 'ALB-TECH', 'manager', 'router_manager', password_hash('manager-secret', PASSWORD_DEFAULT))),
);

$candidates = mikhmon_hotspot_default_account_candidates($users, $sellers, $managers);

if (count($candidates) !== 3) {
    fwrite(STDERR, 'only unassigned profile=default hotspot users must be listed' . PHP_EOL);
    exit(1);
}

if ($candidates[0]['account_key'] !== 'diallo' || $candidates[0]['password'] !== 'secret1' || $candidates[0]['display_name'] !== 'Diallo Market') {
    fwrite(STDERR, 'first hotspot default user must keep its name, password and display label' . PHP_EOL);
    exit(1);
}

if ($candidates[1]['account_key'] !== 'manager1' || $candidates[1]['password'] !== 'manager-1') {
    fwrite(STDERR, 'hotspot usernames must be normalized and fall back to username as password when password is empty' . PHP_EOL);
    exit(1);
}

if ($candidates[2]['account_key'] !== 'recovery' || $candidates[2]['display_name'] !== 'Recovery Shop') {
    fwrite(STDERR, 'old hotspot assignment footprints must not pollute the display label' . PHP_EOL);
    exit(1);
}

$sellerRecord = mikhmon_hotspot_account_record($candidates[0], 'ALB-TECH', 'seller', 'manual-pass-123');
if ($sellerRecord['var'] !== 'sellers_data' || $sellerRecord['key'] !== 'diallo' || $sellerRecord['record']['password'] !== 'manual-pass-123' || $sellerRecord['record']['commission'] !== 10) {
    fwrite(STDERR, 'seller assignment record must target sellers_data and keep the manually entered password' . PHP_EOL);
    exit(1);
}

$managerRecord = mikhmon_hotspot_account_record($candidates[1], 'ALB-TECH', 'manager', 'manager-pass-456');
if ($managerRecord['var'] !== 'managers_data' || $managerRecord['key'] !== 'manager1' || $managerRecord['record']['password'] !== 'manager-pass-456' || isset($managerRecord['record']['commission'])) {
    fwrite(STDERR, 'manager assignment record must target managers_data with the manually entered password and without commission' . PHP_EOL);
    exit(1);
}

$sellerComment = mikhmon_hotspot_assignment_comment('Diallo Market', 'ALB-TECH', 'seller', 'diallo');
if ($sellerComment !== 'Diallo Market | MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=diallo') {
    fwrite(STDERR, 'seller hotspot comment must keep a durable role and session footprint' . PHP_EOL);
    exit(1);
}

$managerComment = mikhmon_hotspot_assignment_comment('Old | MIKHMON_ACCOUNT role=vendeur session=OLD account=old', 'ALB-TECH', 'manager', 'manager1');
if ($managerComment !== 'Old | MIKHMON_ACCOUNT role=gerant session=ALB-TECH account=manager1') {
    fwrite(STDERR, 'hotspot assignment comment must replace an older Mikhmon footprint instead of duplicating it' . PHP_EOL);
    exit(1);
}

$plainMarkerComment = mikhmon_hotspot_assignment_comment('MIKHMON_ACCOUNT role=vendeur session=OLD account=old', 'ALB-TECH', 'seller', 'diallo');
if ($plainMarkerComment !== 'MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=diallo') {
    fwrite(STDERR, 'standalone hotspot assignment footprint must be replaced cleanly' . PHP_EOL);
    exit(1);
}

$bindingMarkerComment = mikhmon_hotspot_assignment_comment('bema', 'ALB-TECH', 'seller', 'bema');
if ($bindingMarkerComment !== 'bema | MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=bema') {
    fwrite(STDERR, 'IP Binding comments must receive the same durable assignment footprint' . PHP_EOL);
    exit(1);
}

if (mikhmon_hotspot_ip_binding_comment_name($ipBindings[4]['comment']) !== 'bema' || mikhmon_hotspot_ip_binding_comment_name($ipBindings[5]['comment']) !== 'Nabala') {
    fwrite(STDERR, 'IP Binding selector must keep showing the original comment name after a Mikhmon footprint is added' . PHP_EOL);
    exit(1);
}

if (!mikhmon_hotspot_routeros_response_ok(array()) || mikhmon_hotspot_routeros_response_ok(array('!trap' => array(array('message' => 'failure'))))) {
    fwrite(STDERR, 'RouterOS response helper must reject trap and fatal responses' . PHP_EOL);
    exit(1);
}

$identityCandidates = mikhmon_hotspot_account_identity_candidates($users, $ipBindings, $sellers, $managers);
$identityByKey = array();
foreach ($identityCandidates as $identityCandidate) {
    $identityByKey[$identityCandidate['account_key']] = $identityCandidate;
}

if (!isset($identityByKey['diallo']) || $identityByKey['diallo']['source'] !== 'hotspot_default' || $identityByKey['diallo']['select_value'] !== 'hotspot:diallo') {
    fwrite(STDERR, 'identity selector must include profile=default hotspot users' . PHP_EOL);
    exit(1);
}

if (!isset($identityByKey['Aboukei']) || $identityByKey['Aboukei']['source'] !== 'ip_binding' || $identityByKey['Aboukei']['select_value'] !== 'ipbinding:Aboukei' || $identityByKey['Aboukei']['display_name'] !== 'Abou kei') {
    fwrite(STDERR, 'identity selector must include cleaned IP Binding comments' . PHP_EOL);
    exit(1);
}

if (!isset($identityByKey['Papatchegbe']) || isset($identityByKey['DialloMarket'])) {
    fwrite(STDERR, 'identity selector must include plain IP Binding comments and avoid duplicates with hotspot default users' . PHP_EOL);
    exit(1);
}

$foundDefaultIdentity = mikhmon_hotspot_find_account_identity_candidate($users, $ipBindings, 'hotspot:diallo', $sellers, $managers);
$foundBindingIdentity = mikhmon_hotspot_find_account_identity_candidate($users, $ipBindings, 'ipbinding:Papatchegbe', $sellers, $managers);
if (!is_array($foundDefaultIdentity) || $foundDefaultIdentity['source'] !== 'hotspot_default' || !is_array($foundBindingIdentity) || $foundBindingIdentity['source'] !== 'ip_binding') {
    fwrite(STDERR, 'identity selector lookup must resolve both hotspot and IP Binding sources' . PHP_EOL);
    exit(1);
}

$bindingRecord = mikhmon_hotspot_account_record($foundBindingIdentity, 'ALB-TECH', 'seller', 'binding-pass');
if ($bindingRecord['key'] !== 'Papatchegbe' || $bindingRecord['record']['name'] !== 'Papa tchegbe' || $bindingRecord['record']['password'] !== 'binding-pass') {
    fwrite(STDERR, 'IP Binding identity must create a local account record from its comment' . PHP_EOL);
    exit(1);
}

$restored = mikhmon_hotspot_restored_account_records($users, $ipBindings, 'ALB-TECH', array(), array(), $routerUsers);
if (!isset($restored['managers']['recovery']) || $restored['managers']['recovery']['name'] !== 'Recovery Shop' || $restored['managers']['recovery']['password'] !== 'recovery-pass') {
    fwrite(STDERR, 'hotspot user footprints must restore manager accounts for a recreated session' . PHP_EOL);
    exit(1);
}
if (!isset($restored['sellers']['bema']) || $restored['sellers']['bema']['name'] !== 'bema' || $restored['sellers']['bema']['password'] !== 'bema' || $restored['sellers']['bema']['commission'] !== 10) {
    fwrite(STDERR, 'IP Binding footprints must restore seller accounts for a recreated session' . PHP_EOL);
    exit(1);
}
if (!isset($restored['managers']['Nabala']) || $restored['managers']['Nabala']['name'] !== 'Nabala') {
    fwrite(STDERR, 'tagged IP Binding comments must restore manager accounts with the visible comment name' . PHP_EOL);
    exit(1);
}
if (!isset($restored['sellers']['router_seller']) || strpos($restored['sellers']['router_seller']['password'], '$') !== 0 || !password_verify('router-secret', $restored['sellers']['router_seller']['password'])) {
    fwrite(STDERR, 'limited RouterOS seller accounts must be restorable with a non-reversible Mikhmon password verifier' . PHP_EOL);
    exit(1);
}
if (!isset($restored['managers']['router_manager']) || !password_verify('manager-secret', $restored['managers']['router_manager']['password'])) {
    fwrite(STDERR, 'limited RouterOS manager accounts must be restorable with a non-reversible Mikhmon password verifier' . PHP_EOL);
    exit(1);
}

$clearedBindingComment = mikhmon_hotspot_clear_assignment_comment('bema | MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=bema', 'ALB-TECH', 'seller', 'bema');
if ($clearedBindingComment !== 'bema') {
    fwrite(STDERR, 'deleting a seller must remove its exact Mikhmon footprint from IP Binding comments' . PHP_EOL);
    exit(1);
}

$keptOtherSessionComment = mikhmon_hotspot_clear_assignment_comment('bema | MIKHMON_ACCOUNT role=vendeur session=OTHER account=bema', 'ALB-TECH', 'seller', 'bema');
if ($keptOtherSessionComment !== 'bema | MIKHMON_ACCOUNT role=vendeur session=OTHER account=bema') {
    fwrite(STDERR, 'deleting a seller must not remove footprints from another session' . PHP_EOL);
    exit(1);
}

$clearedBindings = array(
    array('.id' => '*b5', 'comment' => $clearedBindingComment),
);
$restoredAfterClear = mikhmon_hotspot_restored_account_records(array(), $clearedBindings, 'ALB-TECH', array(), array());
if (isset($restoredAfterClear['sellers']['bema'])) {
    fwrite(STDERR, 'deleted accounts must not be restored again after the MikroTik footprint is cleared' . PHP_EOL);
    exit(1);
}

class MikhmonHotspotAssignmentFakeApi
{
    public $calls = array();

    public function comm($path, $params = array())
    {
        $this->calls[] = array($path, $params);
        return array();
    }
}

$fakeApi = new MikhmonHotspotAssignmentFakeApi();
$clearOk = mikhmon_hotspot_clear_account_footprints(
    $fakeApi,
    array(array('.id' => '*u1', 'comment' => 'Recovery Shop | MIKHMON_ACCOUNT role=gerant session=ALB-TECH account=recovery')),
    array(array('.id' => '*b5', 'comment' => 'bema | MIKHMON_ACCOUNT role=vendeur session=ALB-TECH account=bema')),
    'ALB-TECH',
    'seller',
    'bema'
);
if (!$clearOk || count($fakeApi->calls) !== 1 || $fakeApi->calls[0][0] !== '/ip/hotspot/ip-binding/set' || $fakeApi->calls[0][1]['comment'] !== 'bema') {
    fwrite(STDERR, 'seller deletion must clear only the matching IP Binding footprint in MikroTik' . PHP_EOL);
    exit(1);
}

$fakeApiManager = new MikhmonHotspotAssignmentFakeApi();
$clearManagerOk = mikhmon_hotspot_clear_account_footprints(
    $fakeApiManager,
    array(array('.id' => '*u1', 'comment' => 'Recovery Shop | MIKHMON_ACCOUNT role=gerant session=ALB-TECH account=recovery')),
    $ipBindings,
    'ALB-TECH',
    'manager',
    'recovery'
);
if (!$clearManagerOk || count($fakeApiManager->calls) !== 1 || $fakeApiManager->calls[0][0] !== '/ip/hotspot/user/set' || $fakeApiManager->calls[0][1]['comment'] !== 'Recovery Shop') {
    fwrite(STDERR, 'manager deletion must clear only the matching hotspot user footprint in MikroTik' . PHP_EOL);
    exit(1);
}

$provisionApi = new MikhmonHotspotAssignmentFakeApi();
$provisionedSeller = mikhmon_hotspot_provision_account($provisionApi, 'hotspot_user', 'ALB-TECH', 'seller', 'seller_new', 'seller-pass', 'Seller New');
if (!$provisionedSeller['ok'] || $provisionApi->calls[0][0] !== '/ip/hotspot/user/add') {
    fwrite(STDERR, 'seller creation must provision a new MikroTik Hotspot user' . PHP_EOL);
    exit(1);
}
$sellerAdd = $provisionApi->calls[0][1];
if ($sellerAdd['name'] !== 'seller_new' || $sellerAdd['password'] !== 'seller-pass' || $sellerAdd['profile'] !== 'default' || strpos($sellerAdd['comment'], 'role=vendeur') === false || strpos($sellerAdd['comment'], 'auth=') === false) {
    fwrite(STDERR, 'new seller Hotspot user must use the portal credentials and seller footprint' . PHP_EOL);
    exit(1);
}

$bindingApi = new MikhmonHotspotAssignmentFakeApi();
$provisionedManager = mikhmon_hotspot_provision_account($bindingApi, 'ip_binding', 'ALB-TECH', 'manager', 'manager_new', 'manager-pass', 'Manager New', 'aa:bb:cc:dd:ee:ff', '10.10.0.44');
if (!$provisionedManager['ok'] || $bindingApi->calls[0][0] !== '/ip/hotspot/ip-binding/add') {
    fwrite(STDERR, 'manager creation must provision a new MikroTik IP Binding' . PHP_EOL);
    exit(1);
}
$bindingAdd = $bindingApi->calls[0][1];
if ($bindingAdd['mac-address'] !== 'AA:BB:CC:DD:EE:FF' || $bindingAdd['address'] !== '10.10.0.44' || $bindingAdd['type'] !== 'bypassed' || strpos($bindingAdd['comment'], 'role=gerant') === false) {
    fwrite(STDERR, 'new manager IP Binding must be bypassed and carry the manager footprint' . PHP_EOL);
    exit(1);
}

$invalidBinding = mikhmon_hotspot_provision_account(new MikhmonHotspotAssignmentFakeApi(), 'ip_binding', 'ALB-TECH', 'seller', 'bad_mac', 'pass', 'Bad Mac', 'invalid');
if ($invalidBinding['ok']) {
    fwrite(STDERR, 'IP Binding creation must reject invalid MAC addresses' . PHP_EOL);
    exit(1);
}

$routerSellerApi = new MikhmonHotspotAssignmentFakeApi();
$routerSeller = mikhmon_hotspot_provision_account($routerSellerApi, 'router_user', 'ALB-TECH', 'seller', 'seller_router', 'seller-pass', 'Seller Router', '', '10.10.0.0/24');
if (!$routerSeller['ok'] || count($routerSellerApi->calls) !== 3 || $routerSellerApi->calls[1][0] !== '/user/group/add' || $routerSellerApi->calls[2][0] !== '/user/add') {
    fwrite(STDERR, 'seller RouterOS account must create its limited group and router user' . PHP_EOL);
    exit(1);
}
$sellerGroup = $routerSellerApi->calls[1][1];
$sellerRouterUser = $routerSellerApi->calls[2][1];
if ($sellerGroup['name'] !== 'mikhmon-vendeur' || $sellerGroup['policy'] !== 'read,test,winbox,password,web' || $sellerRouterUser['group'] !== 'mikhmon-vendeur' || $sellerRouterUser['address'] !== '10.10.0.0/24' || strpos($sellerRouterUser['comment'], 'auth=') === false) {
    fwrite(STDERR, 'seller RouterOS group must remain read-only and limited to its allowed address' . PHP_EOL);
    exit(1);
}

$routerManagerApi = new MikhmonHotspotAssignmentFakeApi();
$routerManager = mikhmon_hotspot_provision_account($routerManagerApi, 'router_user', 'ALB-TECH', 'manager', 'manager_router', 'manager-pass', 'Manager Router');
if (!$routerManager['ok'] || $routerManagerApi->calls[1][1]['name'] !== 'mikhmon-revendeur' || $routerManagerApi->calls[1][1]['policy'] !== 'read,write,test,winbox,password,web,api,rest-api') {
    fwrite(STDERR, 'manager RouterOS group must have extended rights without full administration policies' . PHP_EOL);
    exit(1);
}
foreach (array('policy', 'sensitive', 'reboot', 'ssh', 'ftp', 'sniff') as $forbiddenPolicy) {
    if (in_array($forbiddenPolicy, explode(',', $routerManagerApi->calls[1][1]['policy']), true)) {
        fwrite(STDERR, 'manager RouterOS group must not receive dangerous policy: ' . $forbiddenPolicy . PHP_EOL);
        exit(1);
    }
}

$page = file_get_contents($root . '/settings/manage_sellers.php');
$requiredPageSnippets = array(
    "include_once('./include/hotspot_account_assignment.php')",
    'assign_hotspot_account',
    'hotspot_account_password',
    'hotspot_account_role',
    '"?profile" => "default"',
    'mikhmon_hotspot_default_account_candidates',
    'mikhmon_hotspot_assignment_comment',
    'mikhmon_hotspot_provision_account',
    'mikhmon_hotspot_routeros_response_ok',
    '$hotspotIpBindingRows',
    '$routerUserRows',
    '$accountIdentityCandidates',
    'mikhmon_hotspot_restored_account_records',
    'mikhmon_hotspot_clear_account_footprints',
    'name="new_mode" id="seller-new-mode"',
    'name="nm_mode" id="manager-new-mode"',
    '<option value="router_user">Créer un compte RouterOS limité</option>',
    'name="new_user" id="seller-new-user"',
    'name="nm_user" id="manager-new-user"',
    'name="new_mac" id="seller-new-mac"',
    'name="nm_mac" id="manager-new-mac"',
    'name="new_address" id="seller-new-address"',
    'name="nm_address" id="manager-new-address"',
    'name="new_session" value="<?= htmlspecialchars($session) ?>"',
    'name="nm_session" value="<?= htmlspecialchars($session) ?>"',
    'class="identity-create-form identity-create-seller"',
    'class="identity-create-form identity-create-manager"',
    'identity-form-grid',
    'identity-form-row',
    'identity-actions',
    '@media (max-width: 640px)',
    '.identity-form-grid { grid-template-columns: 1fr; }',
    '.identity-actions .btn { width:100%;',
    "msUpdateProvisionMode('seller')",
    "msUpdateProvisionMode('manager')",
);

foreach ($requiredPageSnippets as $snippet) {
    if (strpos($page, $snippet) === false) {
        fwrite(STDERR, 'admin seller page is missing hotspot assignment integration: ' . $snippet . PHP_EOL);
        exit(1);
    }
}

if (strpos($page, 'Sélectionner un utilisateur default ou un commentaire IP Binding') !== false) {
    fwrite(STDERR, 'new seller and manager creation must no longer require a pre-existing MikroTik identity' . PHP_EOL);
    exit(1);
}

if (strpos($page, '$API_ms->comm("/user/print")') === false) {
    fwrite(STDERR, 'admin seller page must read limited RouterOS users for account reconstruction' . PHP_EOL);
    exit(1);
}

if (strpos($page, 'htmlspecialchars($sourceLabel)') !== false) {
    fwrite(STDERR, 'identity selector must display the real identifier only, not the source prefix' . PHP_EOL);
    exit(1);
}

echo "mikhmon_hotspot_account_assignment_test passed\n";
