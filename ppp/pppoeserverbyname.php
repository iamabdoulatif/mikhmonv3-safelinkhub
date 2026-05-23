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

$serverId = mikhmon_ppp_safe_id($pppoeserverbyname);
$interfaces = $API->comm("/interface/print");
$profiles = $API->comm("/ppp/profile/print");
$serverRows = $API->comm("/interface/pppoe-server/server/print", array("?.id" => $serverId));
$server = isset($serverRows[0]) ? $serverRows[0] : array();

if (isset($_POST['interface'])) {
  csrf_guard();
  $payload = array(
    ".id" => $serverId,
    "interface" => trim((string) $_POST['interface']),
    "service-name" => trim((string) (isset($_POST['service-name']) ? $_POST['service-name'] : '')),
    "default-profile" => trim((string) $_POST['default-profile']),
    "authentication" => trim((string) (isset($_POST['authentication']) ? $_POST['authentication'] : '')),
    "max-mtu" => trim((string) (isset($_POST['max-mtu']) ? $_POST['max-mtu'] : '')),
    "max-mru" => trim((string) (isset($_POST['max-mru']) ? $_POST['max-mru'] : '')),
    "keepalive-timeout" => trim((string) (isset($_POST['keepalive-timeout']) ? $_POST['keepalive-timeout'] : '')),
    "one-session-per-host" => mikhmon_ppp_bool(isset($_POST['one-session-per-host']) ? $_POST['one-session-per-host'] : 'yes', 'yes'),
    "disabled" => mikhmon_ppp_bool(isset($_POST['disabled']) ? $_POST['disabled'] : 'no', 'no'),
  );
  $API->comm("/interface/pppoe-server/server/set", $payload);
  mikhmon_ppp_redirect("./?ppp=servers&session=" . urlencode($session));
}
?>
<div class="row ppp-form-page">
<div class="col-8 ppp-main-panel">
<div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-edit"></i> Edit <?= mikhmon_ppp_label('_pppoe_servers', 'PPPoE Server') ?></h3></div>
  <div class="card-body">
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <div class="ppp-action-bar">
        <a class="btn bg-warning" href="./?ppp=servers&session=<?= urlencode($session) ?>"><i class="fa fa-close"></i> <?= isset($_close) ? $_close : 'Close' ?></a>
        <button type="submit" onclick="loader()" class="btn bg-primary"><i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?></button>
      </div>
      <table class="table ppp-form-table">
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_interface', 'Interface') ?></td><td><select class="form-control" name="interface" required>
          <?php foreach ((array)$interfaces as $ifaceRow): $ifaceName = mikhmon_ppp_get($ifaceRow, 'name'); ?>
            <option value="<?= htmlspecialchars($ifaceName) ?>" <?= mikhmon_ppp_get($server, 'interface') === $ifaceName ? 'selected' : '' ?>><?= htmlspecialchars($ifaceName) ?></option>
          <?php endforeach; ?>
        </select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_service_name', 'Service Name') ?></td><td><input class="form-control" type="text" name="service-name" value="<?= htmlspecialchars(mikhmon_ppp_get($server, 'service-name')) ?>"></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_default_profile', 'Default Profile') ?></td><td><select class="form-control" name="default-profile" required>
          <?php foreach ((array)$profiles as $profile): $profileName = mikhmon_ppp_get($profile, 'name'); ?>
            <option value="<?= htmlspecialchars($profileName) ?>" <?= mikhmon_ppp_get($server, 'default-profile') === $profileName ? 'selected' : '' ?>><?= htmlspecialchars($profileName) ?></option>
          <?php endforeach; ?>
        </select></td></tr>
        <tr><td class="align-middle">Authentication</td><td><input class="form-control" type="text" name="authentication" value="<?= htmlspecialchars(mikhmon_ppp_get($server, 'authentication')) ?>"></td></tr>
        <tr><td class="align-middle">Max MTU</td><td><input class="form-control" type="number" name="max-mtu" value="<?= htmlspecialchars(mikhmon_ppp_get($server, 'max-mtu', '1480')) ?>"></td></tr>
        <tr><td class="align-middle">Max MRU</td><td><input class="form-control" type="number" name="max-mru" value="<?= htmlspecialchars(mikhmon_ppp_get($server, 'max-mru', '1480')) ?>"></td></tr>
        <tr><td class="align-middle">Keepalive Timeout</td><td><input class="form-control" type="number" name="keepalive-timeout" value="<?= htmlspecialchars(mikhmon_ppp_get($server, 'keepalive-timeout', '10')) ?>"></td></tr>
        <tr><td class="align-middle">One Session Per Host</td><td><select class="form-control" name="one-session-per-host"><option value="yes" <?= mikhmon_ppp_get($server, 'one-session-per-host') === 'yes' ? 'selected' : '' ?>>yes</option><option value="no" <?= mikhmon_ppp_get($server, 'one-session-per-host') === 'no' ? 'selected' : '' ?>>no</option></select></td></tr>
        <tr><td class="align-middle"><?= mikhmon_ppp_label('_ppp_disabled', 'Disabled') ?></td><td><select class="form-control" name="disabled"><option value="no" <?= mikhmon_ppp_get($server, 'disabled') === 'false' ? 'selected' : '' ?>>no</option><option value="yes" <?= mikhmon_ppp_get($server, 'disabled') === 'true' ? 'selected' : '' ?>>yes</option></select></td></tr>
      </table>
    </form>
  </div>
</div>
</div>
</div>
