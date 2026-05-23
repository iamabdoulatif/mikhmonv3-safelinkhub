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

$getprofiles = $API->comm("/ppp/profile/print");
$TotalReg = is_array($getprofiles) ? count($getprofiles) : 0;
$countprofiles = $API->comm("/ppp/profile/print", array("count-only" => ""));
?>
<div class="row">
<div class="col-12">
<div class="card">
  <div class="card-header align-middle">
    <h3><i class="fa fa-list"></i> <?= mikhmon_ppp_label('_ppp_profiles', 'PPP Profiles') ?>
      &nbsp; | &nbsp; <a href="./?ppp=add-profile&session=<?= urlencode($session) ?>" title="Add PPP Profile"><i class="fa fa-plus-square"></i> <?= isset($_add) ? $_add : 'Add' ?></a>
    </h3>
  </div>
  <div class="card-body overflow">
    <table id="tFilter" class="table table-bordered table-hover text-nowrap ppp-responsive-table">
      <thead>
        <tr>
          <th class="text-center"><?= (int)$countprofiles ?> <?= mikhmon_ppp_count_unit($countprofiles) ?></th>
          <th><?= mikhmon_ppp_label('_name', 'Name') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_rate_limit', 'Rate Limit') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_dns_server', 'DNS Server') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_only_one', 'Only One') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_use_encryption', 'Encryption') ?></th>
          <th><?= mikhmon_ppp_label('_comment', 'Comment') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $TotalReg; $i++): ?>
        <?php
          $row = $getprofiles[$i];
          $id = mikhmon_ppp_get($row, '.id');
          $name = mikhmon_ppp_get($row, 'name');
        ?>
        <tr>
          <td class="text-center" data-label="<?= mikhmon_ppp_label('_action', 'Action') ?>">
            <span class="ppp-row-actions">
            <form method="post" action="./?remove-pprofile=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;" onsubmit="return confirm('Remove PPP profile <?= htmlspecialchars($name, ENT_QUOTES) ?>?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link text-danger" title="Remove <?= htmlspecialchars($name) ?>" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa fa-minus-square"></i>
              </button>
            </form>
            <a href="./?ppp=edit-profile&profile=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" title="Edit <?= htmlspecialchars($name) ?>"><i class="fa fa-edit"></i></a>
            </span>
          </td>
          <td data-label="<?= mikhmon_ppp_label('_name', 'Name') ?>"><b><?= htmlspecialchars($name) ?></b></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_local_address', 'Local Address') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'local-address')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_remote_address', 'Remote Address') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'remote-address')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_rate_limit', 'Rate Limit') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'rate-limit')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_dns_server', 'DNS Server') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'dns-server')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_only_one', 'Only One') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'only-one')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_use_encryption', 'Encryption') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'use-encryption')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_comment', 'Comment') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'comment')) ?></td>
        </tr>
        <?php endfor; ?>
        <?php if ($TotalReg === 0): ?>
        <tr><td colspan="9" class="text-center ppp-empty" data-label=""><i class="fa fa-info-circle"></i> <?= mikhmon_ppp_label('_ppp_no_profile', 'No PPP profile.') ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div>
