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

$interfaces = $API->comm("/interface/print");
$profiles = $API->comm("/ppp/profile/print");

if (isset($_POST['interface'])) {
  csrf_guard();
  $payload = array(
    "interface" => trim((string) $_POST['interface']),
    "default-profile" => trim((string) $_POST['default-profile']),
    "disabled" => mikhmon_ppp_bool(isset($_POST['disabled']) ? $_POST['disabled'] : 'no', 'no'),
    "one-session-per-host" => mikhmon_ppp_bool(isset($_POST['one-session-per-host']) ? $_POST['one-session-per-host'] : 'yes', 'yes'),
  );
  foreach (array('service-name', 'max-mtu', 'max-mru', 'authentication', 'keepalive-timeout') as $field) {
    $value = trim((string) (isset($_POST[$field]) ? $_POST[$field] : ''));
    if ($value !== '') {
      $payload[$field] = $value;
    }
  }
  $API->comm("/interface/pppoe-server/server/add", $payload);
  mikhmon_ppp_redirect("./?ppp=servers&session=" . urlencode($session));
}
?>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-server"></i> Add <?= mikhmon_ppp_label('_pppoe_servers', 'PPPoE Server') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=servers&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_interface', 'Interface') ?></td><td><select class="form-control" name="interface" required>
          <?php foreach ((array)$interfaces as $ifaceRow): ?>
            <option value="<?= htmlspecialchars(mikhmon_ppp_get($ifaceRow, 'name')) ?>"><?= htmlspecialchars(mikhmon_ppp_get($ifaceRow, 'name')) ?></option>
          <?php endforeach; ?>
        </select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_service_name', 'Service Name') ?></td><td><input class="form-control" type="text" name="service-name" placeholder="Optional"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_default_profile', 'Default Profile') ?></td><td><select class="form-control" name="default-profile" required>
          <?php foreach ((array)$profiles as $profile): ?>
            <option value="<?= htmlspecialchars(mikhmon_ppp_get($profile, 'name')) ?>"><?= htmlspecialchars(mikhmon_ppp_get($profile, 'name')) ?></option>
          <?php endforeach; ?>
        </select></td></tr>
        <tr><td class="align-middle">Authentication</td><td><input class="form-control" type="text" name="authentication" value="pap,chap,mschap1,mschap2"></td></tr>
        <tr><td class="align-middle">Max MTU</td><td><input class="form-control" type="number" name="max-mtu" value="1480"></td></tr>
        <tr><td class="align-middle">Max MRU</td><td><input class="form-control" type="number" name="max-mru" value="1480"></td></tr>
        <tr><td class="align-middle">Keepalive Timeout</td><td><input class="form-control" type="number" name="keepalive-timeout" value="10"></td></tr>
        <tr><td class="align-middle">One Session Per Host</td><td><select class="form-control" name="one-session-per-host"><option value="yes">yes</option><option value="no">no</option></select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_disabled', 'Disabled') ?></td><td><select class="form-control" name="disabled"><option value="no">no</option><option value="yes">yes</option></select></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
<div class="col-4 ppp-help-panel">
  <div class="card"><div class="card-header"><h3><i class="fa fa-book"></i> <?= mikhmon_ppp_label('_pppoe_servers', 'PPPoE Server') ?></h3></div>
  <div class="card-body"><p>Le serveur PPPoE écoute sur une interface MikroTik et applique un profil par défaut aux clients qui se connectent.</p></div></div>
</div>
</div>
