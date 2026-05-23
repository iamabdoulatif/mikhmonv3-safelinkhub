<?php
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once(__DIR__ . '/../include/csrf.php');
include_once(__DIR__ . '/../include/pppoe_helpers.php');
mikhmon_ppp_responsive_css();

if (isset($_POST['name'])) {
  csrf_guard();
  $payload = array("name" => trim((string) $_POST['name']));
  foreach (array('local-address', 'remote-address', 'rate-limit', 'dns-server', 'comment') as $field) {
    $value = trim((string) (isset($_POST[$field]) ? $_POST[$field] : ''));
    if ($value !== '') {
      $payload[$field] = $value;
    }
  }
  $payload['only-one'] = mikhmon_ppp_bool(isset($_POST['only-one']) ? $_POST['only-one'] : 'no', 'no');
  $encryption = trim((string) (isset($_POST['use-encryption']) ? $_POST['use-encryption'] : 'default'));
  if (in_array($encryption, array('default', 'no', 'yes', 'required'), true)) {
    $payload['use-encryption'] = $encryption;
  }
  $API->comm("/ppp/profile/add", $payload);
  mikhmon_ppp_redirect("./?ppp=profiles&session=" . urlencode($session));
}
?>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-plus-square"></i> Add <?= mikhmon_ppp_label('_ppp_profiles', 'PPP Profile') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=profiles&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_name', 'Name') ?></td><td><input class="form-control" type="text" name="name" required autofocus></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></td><td><input class="form-control" type="text" name="local-address" placeholder="Example: 10.10.10.1"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></td><td><input class="form-control" type="text" name="remote-address" placeholder="IP pool or address"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_rate_limit', 'Rate Limit') ?></td><td><input class="form-control" type="text" name="rate-limit" placeholder="Example: 5M/5M"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_dns_server', 'DNS Server') ?></td><td><input class="form-control" type="text" name="dns-server" placeholder="Example: 8.8.8.8,1.1.1.1"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_only_one', 'Only One') ?></td><td><select class="form-control" name="only-one"><option value="no">no</option><option value="yes">yes</option></select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_use_encryption', 'Use Encryption') ?></td><td><select class="form-control" name="use-encryption"><option value="default">default</option><option value="no">no</option><option value="yes">yes</option><option value="required">required</option></select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_comment', 'Comment') ?></td><td><input class="form-control" type="text" name="comment"></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
<div class="col-4 ppp-help-panel">
  <div class="card"><div class="card-header"><h3><i class="fa fa-book"></i> <?= mikhmon_ppp_label('_ppp_profiles', 'PPP Profile') ?></h3></div>
  <div class="card-body"><p>Le profil PPP définit les IP clients, DNS, débit et règles appliquées aux secrets PPPoE.</p></div></div>
</div>
</div>
