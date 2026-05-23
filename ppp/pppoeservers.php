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

$servers = $API->comm("/interface/pppoe-server/server/print");
$TotalReg = is_array($servers) ? count($servers) : 0;
$countservers = $API->comm("/interface/pppoe-server/server/print", array("count-only" => ""));
?>
<div class="row">
<div class="col-12">
<div class="card">
  <div class="card-header align-middle">
    <h3><i class="fa fa-server"></i> <?= mikhmon_ppp_label('_pppoe_servers', 'PPPoE Servers') ?>
      &nbsp; | &nbsp; <a href="./?ppp=add-server&session=<?= urlencode($session) ?>" title="Add PPPoE Server"><i class="fa fa-plus-square"></i> <?= isset($_add) ? $_add : 'Add' ?></a>
    </h3>
  </div>
  <div class="card-body overflow">
    <table id="tFilter" class="table table-bordered table-hover text-nowrap ppp-responsive-table">
      <thead>
        <tr>
          <th class="text-center"><?= (int)$countservers ?> <?= mikhmon_ppp_count_unit($countservers) ?></th>
          <th><?= mikhmon_ppp_label('_ppp_service_name', 'Service Name') ?></th>
          <th><?= mikhmon_ppp_label('_interface', 'Interface') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_default_profile', 'Default Profile') ?></th>
          <th>Authentication</th>
          <th>One Session Per Host</th>
          <th>MTU/MRU</th>
          <th><?= mikhmon_ppp_label('_status', 'Status') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $TotalReg; $i++): ?>
        <?php
          $row = $servers[$i];
          $id = mikhmon_ppp_get($row, '.id');
          $serviceName = mikhmon_ppp_get($row, 'service-name', mikhmon_ppp_get($row, 'interface'));
          $disabled = mikhmon_ppp_get($row, 'disabled', 'false');
        ?>
        <tr>
          <td class="text-center" data-label="<?= mikhmon_ppp_label('_action', 'Action') ?>">
            <span class="ppp-row-actions">
            <form method="post" action="./?<?= $disabled === 'true' ? 'enable-pppoe-server' : 'disable-pppoe-server' ?>=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link" title="<?= $disabled === 'true' ? 'Enable' : 'Disable' ?> PPPoE server" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa <?= $disabled === 'true' ? 'fa-check-square text-green' : 'fa-minus-square text-orange' ?>"></i>
              </button>
            </form>
            <form method="post" action="./?remove-pppoe-server=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;" onsubmit="return confirm('Remove PPPoE server <?= htmlspecialchars($serviceName, ENT_QUOTES) ?>?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link text-danger" title="Remove PPPoE server" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa fa-trash"></i>
              </button>
            </form>
            <a href="./?pppoe-server=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" title="Edit PPPoE server"><i class="fa fa-edit"></i></a>
            </span>
          </td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_service_name', 'Service Name') ?>"><b><?= htmlspecialchars($serviceName) ?></b></td>
          <td data-label="<?= mikhmon_ppp_label('_interface', 'Interface') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'interface')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_default_profile', 'Default Profile') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'default-profile')) ?></td>
          <td data-label="Authentication"><?= htmlspecialchars(mikhmon_ppp_get($row, 'authentication')) ?></td>
          <td data-label="One Session Per Host"><?= htmlspecialchars(mikhmon_ppp_get($row, 'one-session-per-host')) ?></td>
          <td data-label="MTU/MRU"><?= htmlspecialchars(mikhmon_ppp_get($row, 'max-mtu')) ?> / <?= htmlspecialchars(mikhmon_ppp_get($row, 'max-mru')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_status', 'Status') ?>"><?= $disabled === 'true' ? '<span class="text-orange">' . mikhmon_ppp_label('_ppp_disabled', 'Disabled') . '</span>' : '<span class="text-green">' . mikhmon_ppp_label('_ppp_enabled', 'Enabled') . '</span>' ?></td>
        </tr>
        <?php endfor; ?>
        <?php if ($TotalReg === 0): ?>
        <tr><td colspan="8" class="text-center ppp-empty" data-label=""><i class="fa fa-info-circle"></i> <?= mikhmon_ppp_label('_pppoe_no_server', 'No PPPoE server configured.') ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div>
