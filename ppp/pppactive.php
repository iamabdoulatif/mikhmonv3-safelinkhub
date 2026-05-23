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

$getpppactive = $API->comm("/ppp/active/print");
$TotalReg = is_array($getpppactive) ? count($getpppactive) : 0;
$countpppactive = $API->comm("/ppp/active/print", array("count-only" => ""));
?>
<div class="row">
<div class="col-12">
<div class="card">
  <div class="card-header">
    <h3><i class="fa fa-link"></i> <?= mikhmon_ppp_label('_ppp_active', 'PPP Active') ?> <?= (int)$countpppactive ?> <?= mikhmon_ppp_count_unit($countpppactive) ?></h3>
  </div>
  <div class="card-body overflow">
    <table id="tFilter" class="table table-bordered table-hover text-nowrap ppp-responsive-table">
      <thead>
        <tr>
          <th></th>
          <th><?= mikhmon_ppp_label('_name', 'Name') ?></th>
          <th><?= mikhmon_ppp_label('_ppp_service', 'Service') ?></th>
          <th>Address</th>
          <th><?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?></th>
          <th class="text-right">Uptime</th>
          <th class="text-right">Bytes In</th>
          <th class="text-right">Bytes Out</th>
          <th>Encoding</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $TotalReg; $i++): ?>
        <?php
          $row = $getpppactive[$i];
          $id = mikhmon_ppp_get($row, '.id');
          $name = mikhmon_ppp_get($row, 'name');
        ?>
        <tr>
          <td class="text-center" data-label="<?= mikhmon_ppp_label('_action', 'Action') ?>">
            <form method="post" action="./?remove-pactive=<?= urlencode($id) ?>&session=<?= urlencode($session) ?>" style="display:inline;" onsubmit="return confirm('Disconnect PPP active session <?= htmlspecialchars($name, ENT_QUOTES) ?>?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn-link text-danger" title="<?= mikhmon_ppp_label('_ppp_disconnect', 'Disconnect') ?> <?= htmlspecialchars($name) ?>" style="border:0;background:transparent;padding:0;cursor:pointer;">
                <i class="fa fa-minus-square"></i>
              </button>
            </form>
          </td>
          <td data-label="<?= mikhmon_ppp_label('_name', 'Name') ?>"><b><?= htmlspecialchars($name) ?></b></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_service', 'Service') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'service')) ?></td>
          <td data-label="Address"><?= htmlspecialchars(mikhmon_ppp_get($row, 'address')) ?></td>
          <td data-label="<?= mikhmon_ppp_label('_ppp_caller_id', 'Caller ID') ?>"><?= htmlspecialchars(mikhmon_ppp_get($row, 'caller-id')) ?></td>
          <td class="text-right" data-label="Uptime"><?= htmlspecialchars(formatDTM(mikhmon_ppp_get($row, 'uptime', '0s'))) ?></td>
          <td class="text-right" data-label="Bytes In"><?= htmlspecialchars(formatBytes(mikhmon_ppp_get($row, 'bytes-in', '0'), 2)) ?></td>
          <td class="text-right" data-label="Bytes Out"><?= htmlspecialchars(formatBytes(mikhmon_ppp_get($row, 'bytes-out', '0'), 2)) ?></td>
          <td data-label="Encoding"><?= htmlspecialchars(mikhmon_ppp_get($row, 'encoding')) ?></td>
        </tr>
        <?php endfor; ?>
        <?php if ($TotalReg === 0): ?>
        <tr><td colspan="9" class="text-center ppp-empty" data-label=""><i class="fa fa-info-circle"></i> <?= mikhmon_ppp_label('_ppp_no_active', 'No active PPP session.') ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div>
