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

$profileId = mikhmon_ppp_safe_id($prof);
$profileRows = $API->comm("/ppp/profile/print", array("?.id" => $profileId));
$profile = isset($profileRows[0]) ? $profileRows[0] : array();

if (isset($_POST['name'])) {
  csrf_guard();
  $payload = array(
    ".id" => $profileId,
    "name" => trim((string) $_POST['name']),
    "local-address" => trim((string) (isset($_POST['local-address']) ? $_POST['local-address'] : '')),
    "remote-address" => trim((string) (isset($_POST['remote-address']) ? $_POST['remote-address'] : '')),
    "rate-limit" => trim((string) (isset($_POST['rate-limit']) ? $_POST['rate-limit'] : '')),
    "dns-server" => trim((string) (isset($_POST['dns-server']) ? $_POST['dns-server'] : '')),
    "only-one" => mikhmon_ppp_bool(isset($_POST['only-one']) ? $_POST['only-one'] : 'no', 'no'),
    "comment" => trim((string) (isset($_POST['comment']) ? $_POST['comment'] : '')),
  );
  $encryption = trim((string) (isset($_POST['use-encryption']) ? $_POST['use-encryption'] : 'default'));
  if (in_array($encryption, array('default', 'no', 'yes', 'required'), true)) {
    $payload['use-encryption'] = $encryption;
  }
  $API->comm("/ppp/profile/set", $payload);
  mikhmon_ppp_redirect("./?ppp=profiles&session=" . urlencode($session));
}
?>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-edit"></i> Edit <?= mikhmon_ppp_label('_ppp_profiles', 'PPP Profile') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=profiles&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_name', 'Name') ?></td><td><input class="form-control" type="text" name="name" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'name')) ?>" required autofocus></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></td><td><input class="form-control" type="text" name="local-address" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'local-address')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></td><td><input class="form-control" type="text" name="remote-address" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'remote-address')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_rate_limit', 'Rate Limit') ?></td><td><input class="form-control" type="text" name="rate-limit" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'rate-limit')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_dns_server', 'DNS Server') ?></td><td><input class="form-control" type="text" name="dns-server" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'dns-server')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_only_one', 'Only One') ?></td><td><select class="form-control" name="only-one"><option value="no" <?= mikhmon_ppp_get($profile, 'only-one') === 'no' ? 'selected' : '' ?>>no</option><option value="yes" <?= mikhmon_ppp_get($profile, 'only-one') === 'yes' ? 'selected' : '' ?>>yes</option></select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_use_encryption', 'Use Encryption') ?></td><td><select class="form-control" name="use-encryption">
          <?php foreach (array('default','no','yes','required') as $option): ?>
            <option value="<?= $option ?>" <?= mikhmon_ppp_get($profile, 'use-encryption', 'default') === $option ? 'selected' : '' ?>><?= $option ?></option>
          <?php endforeach; ?>
        </select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_comment', 'Comment') ?></td><td><input class="form-control" type="text" name="comment" value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'comment')) ?>"></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
</div>
