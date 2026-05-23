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

$getsecrets = $API->comm("/ppp/secret/print");
$TotalReg = is_array($getsecrets) ? count($getsecrets) : 0;
$countsecrets = $API->comm("/ppp/secret/print", array("count-only" => ""));
?>
<div class="row">
<div class="col-12">
<div class="card">
  <div class="card-header align-middle">
    <h3><i class="fa fa-lock"></i> <?= mikhmon_ppp_label('_ppp_secrets', 'PPP Secrets') ?>
      &nbsp; | &nbsp; <a href="./?ppp=addsecret&session=<?= urlencode($session) ?>" title="Add PPP Secret"><i class="fa fa-user-plus"></i> <?= isset($_add) ? $_add : 'Add' ?></a>
    </h3>
  </div>
  <div class="card-body overflow">
    <table id="tFilter" class="table table-bordered table-hover text-nowrap ppp-responsive-table">
      <thead>
        <tr>
          <th class="text-center"><?= (int)$countsecrets ?> <?= mikhmon_ppp_count_unit($countsecrets) ?></th>
          <th><?= mikhmon_ppp_label('_name', 'Name') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_service', 'Service') ?></th>
          <th><?= mikhmon_ppp_label('_profile', 'Profile') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?></th>
          <th><?= mikhmon_ppp_label('_status', 'Status') ?></th>
          <th><?= mikhmon_ppp_label('_comment', 'Comment') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $TotalReg; $i++): ?>
        <?php
          $row = $getsecrets[$i];
          $id = mikhmon_ppp_get($row, '.id');
          $name = mikhmon_ppp_get($row, 'name');
          $disabled = mikhmon_ppp_get($row, 'disabled', 'false');
        ?>
        <tr>
          <td class="text-center" data-label="<?= mikhmon_ppp_label('_action', 'Action') ?>">
            <span class="ppp-row-actions">
            <form method="post" action="./?<?= $disabled === 'true' ? 'enable-pppsecret' : 'disable-pppsecret' ?>=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link" title="<?= $disabled === 'true' ? mikhmon_ppp_label('_ppp_enabled', 'Enable') : mikhmon_ppp_label('_ppp_disabled', 'Disable') ?> <?= htmlspecialchars($name) ?>" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa <?= $disabled === 'true' ? 'fa-check-square text-green' : 'fa-minus-square text-orange' ?>"></i>
              </button>
            </form>
            <form method="post" action="./?remove-pppsecret=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;" onsubmit="return confirm('Remove PPP secret <?= htmlspecialchars($name, ENT_QUOTES) ?>?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link text-danger" title="Remove <?= htmlspecialchars($name) ?>" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa fa-trash"></i>
              </button>
            </form>
            <a href="./?secret=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" title="Edit <?= htmlspecialchars($name) ?>"><i class="fa fa-edit"></i></a>
            </span>
          </td>
          <td data-label="<?= mikhmon_ppp_label('_name', 'Name') ?>"><b><?= htmlspecialchars($name) ?></b></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_service', 'Service') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'service')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_profile', 'Profile') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'profile')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'local-address')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'remote-address')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'caller-id')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_status', 'Status') ?>"><?= $disabled === 'true' ? '<span class="text-orange">' . mikhmon_ppp_label('_ppp_disabled', 'Disabled') . '</span>' : '<span class="text-green">' . mikhmon_ppp_label('_ppp_enabled', 'Enabled') . '</span>' ?></td>
          <td data-label="<?= mikhmon_ppp_label('_comment', 'Comment') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'comment')) ?></td>
        </tr>
        <?php endfor; ?>
        <?php if ($TotalReg === 0): ?>
        <tr><td colspan="9" class="text-center ppp-empty" data-label=""><i class="fa fa-info-circle"></i> <?= mikhmon_ppp_label('_ppp_no_secret', 'No PPP secret.') ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div>
