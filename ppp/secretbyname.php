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

$secretId = mikhmon_ppp_safe_id($secretbyname);
$profiles = $API->comm("/ppp/profile/print");
$secretRows = $API->comm("/ppp/secret/print", array("?.id" => $secretId));
$secret = isset($secretRows[0]) ? $secretRows[0] : array();

if (isset($_POST['name'])) {
  csrf_guard();
  $payload = array(
    ".id" => $secretId,
    "name" => trim((string) $_POST['name']),
    "service" => mikhmon_ppp_service(isset($_POST['service']) ? $_POST['service'] : 'pppoe'),
    "profile" => trim((string) $_POST['profile']),
    "disabled" => mikhmon_ppp_bool(isset($_POST['disabled']) ? $_POST['disabled'] : 'no', 'no'),
  );
  $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
  if ($password !== '') {
    $payload["password"] = $password;
  }
  foreach (array('local-address', 'remote-address', 'caller-id', 'comment') as $field) {
    $payload[$field] = trim((string) (isset($_POST[$field]) ? $_POST[$field] : ''));
  }
  $API->comm("/ppp/secret/set", $payload);
  mikhmon_ppp_redirect("./?ppp=secrets&session=" . urlencode($session));
}
?>
<script>
function PassPPPSecretEdit(){
  var x = document.getElementById('pppSecretPassEdit');
  x.type = x.type === 'password' ? 'text' : 'password';
}
</script>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-edit"></i> Edit <?= mikhmon_ppp_label('_ppp_secrets', 'PPP Secret') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=secrets&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_name', 'Name') ?></td><td><input class="form-control" type="text" name="name" value="<?= htmlspecialchars(mikhmon_ppp_get($secret, 'name')) ?>" required autofocus></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_password', 'Password') ?></td><td>
          <div class="input-group">
            <div class="input-group-11 col-box-10"><input class="group-item group-item-l" id="pppSecretPassEdit" type="password" name="password" autocomplete="new-password" placeholder="Leave empty to keep current password"></div>
            <div class="input-group-1 col-box-2"><div class="group-item group-item-r pd-2p5 text-center"><input title="Show/Hide Password" type="checkbox" onclick="PassPPPSecretEdit()"></div></div>
          </div>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_service', 'Service') ?></td><td>
          <select class="form-control" name="service">
            <?php foreach (array('pppoe','pptp','l2tp','sstp','ovpn','any') as $service): ?>
              <option value="<?= $service ?>" <?= mikhmon_ppp_get($secret, 'service', 'pppoe') === $service ? 'selected' : '' ?>><?= $service ?></option>
            <?php endforeach; ?>
          </select>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_profile', 'Profile') ?></td><td>
          <select class="form-control" name="profile" required>
            <?php foreach ((array)$profiles as $profile): $profileName = mikhmon_ppp_get($profile, 'name'); ?>
              <option value="<?= htmlspecialchars($profileName) ?>" <?= mikhmon_ppp_get($secret, 'profile') === $profileName ? 'selected' : '' ?>><?= htmlspecialchars($profileName) ?></option>
            <?php endforeach; ?>
          </select>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></td><td><input class="form-control" type="text" name="local-address" value="<?= htmlspecialchars(mikhmon_ppp_get($secret, 'local-address')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></td><td><input class="form-control" type="text" name="remote-address" value="<?= htmlspecialchars(mikhmon_ppp_get($secret, 'remote-address')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?></td><td><input class="form-control" type="text" name="caller-id" value="<?= htmlspecialchars(mikhmon_ppp_get($secret, 'caller-id')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_comment', 'Comment') ?></td><td><input class="form-control" type="text" name="comment" value="<?= htmlspecialchars(mikhmon_ppp_get($secret, 'comment')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_disabled', 'Disabled') ?></td><td><select class="form-control" name="disabled"><option value="no" <?= mikhmon_ppp_get($secret, 'disabled') === 'false' ? 'selected' : '' ?>>no</option><option value="yes" <?= mikhmon_ppp_get($secret, 'disabled') === 'true' ? 'selected' : '' ?>>yes</option></select></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
</div>
