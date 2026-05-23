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

$profiles = $API->comm("/ppp/profile/print");

if (isset($_POST['name'])) {
  csrf_guard();
  $payload = array(
    "name" => trim((string) $_POST['name']),
    "password" => (string) $_POST['password'],
    "service" => mikhmon_ppp_service(isset($_POST['service']) ? $_POST['service'] : 'pppoe'),
    "profile" => trim((string) $_POST['profile']),
    "disabled" => mikhmon_ppp_bool(isset($_POST['disabled']) ? $_POST['disabled'] : 'no', 'no'),
  );
  foreach (array('local-address', 'remote-address', 'caller-id', 'comment') as $field) {
    $value = trim((string) (isset($_POST[$field]) ? $_POST[$field] : ''));
    if ($value !== '') {
      $payload[$field] = $value;
    }
  }
  $API->comm("/ppp/secret/add", $payload);
  mikhmon_ppp_redirect("./?ppp=secrets&session=" . urlencode($session));
}
?>
<script>
function PassPPPSecret(){
  var x = document.getElementById('pppSecretPass');
  x.type = x.type === 'password' ? 'text' : 'password';
}
</script>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-user-plus"></i> Add <?= mikhmon_ppp_label('_ppp_secrets', 'PPP Secret') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=secrets&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_name', 'Name') ?></td><td><input class="form-control" type="text" name="name" required autofocus></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_password', 'Password') ?></td><td>
          <div class="input-group">
            <div class="input-group-11 col-box-10"><input class="group-item group-item-l" id="pppSecretPass" type="password" name="password" autocomplete="new-password" required></div>
            <div class="input-group-1 col-box-2"><div class="group-item group-item-r pd-2p5 text-center"><input title="Show/Hide Password" type="checkbox" onclick="PassPPPSecret()"></div></div>
          </div>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_service', 'Service') ?></td><td>
          <select class="form-control" name="service">
            <option value="pppoe">pppoe</option>
            <option value="pptp">pptp</option>
            <option value="l2tp">l2tp</option>
            <option value="sstp">sstp</option>
            <option value="ovpn">ovpn</option>
            <option value="any">any</option>
          </select>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_profile', 'Profile') ?></td><td>
          <select class="form-control" name="profile" required>
            <?php foreach ((array)$profiles as $profile): ?>
              <option value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'name')) ?>"><?= htmlspecialchars(mikhmon_ppp_get($profile, 'name')) ?></option>
            <?php endforeach; ?>
          </select>
        </td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></td><td><input class="form-control" type="text" name="local-address" placeholder="Optional"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></td><td><input class="form-control" type="text" name="remote-address" placeholder="IP or pool name"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?></td><td><input class="form-control" type="text" name="caller-id" placeholder="Optional client MAC for PPPoE"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_comment', 'Comment') ?></td><td><input class="form-control" type="text" name="comment"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_disabled', 'Disabled') ?></td><td><select class="form-control" name="disabled"><option value="no">no</option><option value="yes">yes</option></select></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
<div class="col-4 ppp-help-panel">
  <div class="card"><div class="card-header"><h3><i class="fa fa-book"></i> <?= mikhmon_ppp_label('_pppoe', 'PPPoE') ?></h3></div>
  <div class="card-body"><p>Un secret PPP est le compte client utilisé par PPPoE. Le profil applique les IP, DNS et limites de débit.</p></div></div>
</div>
</div>
