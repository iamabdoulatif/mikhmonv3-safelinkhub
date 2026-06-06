<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// hide all error
error_reporting(0);
ini_set('max_execution_time', 300);
include_once('./include/csrf.php');
include_once('./include/mikhmon_compat.php');
include_once('./include/sellers_config.php');
include_once('./include/seller_ticket_helper.php');
include_once('./include/transfer_log.php');

$isManagerTicketViewer = !empty($_SESSION['manager_username']) && empty($_SESSION['mikhmon']);

if (!isset($_SESSION["mikhmon"]) && !$isManagerTicketViewer) {
  header("Location:../admin.php?id=login");
} else {
  $batchDistributionMsg = '';
  $batchDistributionError = '';
  $batchDistributionInput = array();
  $sessionSellers = array();
  if (!empty($sellers_data) && is_array($sellers_data)) {
    foreach ($sellers_data as $sellerKey => $sellerData) {
      $sellerSession = isset($sellerData['session']) ? trim($sellerData['session']) : '';
      if ($sellerSession === '' || $sellerSession === $session) {
        $sessionSellers[$sellerKey] = $sellerData;
      }
    }
  }

  if (!$isManagerTicketViewer && isset($_POST['split_batch'])) {
    csrf_guard();
    $sourceComment = trim(isset($_POST['source_comment']) ? $_POST['source_comment'] : '');
    $sourceProfile = trim(isset($_POST['source_profile']) ? $_POST['source_profile'] : '');
    $postedVendorQty = (isset($_POST['batch_vendor_qty']) && is_array($_POST['batch_vendor_qty'])) ? $_POST['batch_vendor_qty'] : array();
    $distributionPlan = array();
    $requestedTotal = 0;

    foreach ($postedVendorQty as $sellerKey => $qty) {
      $sellerKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$sellerKey);
      $qty = max(0, (int)$qty);
      $batchDistributionInput[$sellerKey] = $qty;
      if ($qty > 0 && isset($sessionSellers[$sellerKey])) {
        $distributionPlan[$sellerKey] = $qty;
        $requestedTotal += $qty;
      }
    }

    if ($sourceComment === '') {
      $batchDistributionError = '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-top:10px;"><i class="fa fa-ban"></i> ' . (isset($_select_batch_first) ? $_select_batch_first : 'Select a batch comment first.') . '</div>';
    } elseif (empty($distributionPlan)) {
      $batchDistributionError = '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-top:10px;"><i class="fa fa-ban"></i> ' . (isset($_split_batch_qty_required) ? $_split_batch_qty_required : 'Enter at least one quantity to distribute.') . '</div>';
    } else {
      $sourceUsers = $API->comm("/ip/hotspot/user/print", array(
        "?comment" => $sourceComment,
        "?uptime" => "0s",
      ));
      if (!is_array($sourceUsers)) {
        $sourceUsers = array();
      }
      if ($sourceProfile !== '') {
        $sourceUsers = array_values(array_filter($sourceUsers, function ($user) use ($sourceProfile) {
          return isset($user['profile']) && $user['profile'] === $sourceProfile;
        }));
      }
      usort($sourceUsers, function ($left, $right) {
        return strnatcmp(isset($left['name']) ? $left['name'] : '', isset($right['name']) ? $right['name'] : '');
      });

      $availableTotal = count($sourceUsers);
      if ($availableTotal === 0) {
        $batchDistributionError = '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-top:10px;"><i class="fa fa-ban"></i> ' . (isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets available.') . '</div>';
      } elseif ($requestedTotal > $availableTotal) {
        $batchDistributionError = '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-top:10px;"><i class="fa fa-ban"></i> ' . (isset($_split_batch_overflow) ? $_split_batch_overflow : 'Requested quantity exceeds available stock for this batch.') . '</div>';
      } else {
        $sourceProfilesForLog = mikhmon_collect_profiles_from_users($sourceUsers);
        $loggedSourceProfile = $sourceProfile !== '' ? $sourceProfile : (!empty($sourceProfilesForLog) ? implode(', ', $sourceProfilesForLog) : 'default');
        $cursor = 0;
        $createdGroups = array();
        foreach ($distributionPlan as $sellerKey => $qty) {
          $targetComment = mikhmon_comment_assign_seller($sourceComment, $sellerKey, $sellers_data);
          $done = 0;
          while ($done < $qty && $cursor < $availableTotal) {
            $targetUser = $sourceUsers[$cursor];
            $API->comm("/ip/hotspot/user/set", array(
              ".id" => $targetUser['.id'],
              "comment" => $targetComment,
            ));
            $done++;
            $cursor++;
          }

          if ($done > 0) {
            $createdGroups[] = array(
              'seller_key' => $sellerKey,
              'seller_name' => isset($sessionSellers[$sellerKey]['name']) ? $sessionSellers[$sellerKey]['name'] : $sellerKey,
              'comment' => $targetComment,
              'qty' => $done,
            );
            if (function_exists('log_transfer')) {
              log_transfer(
                $sourceComment,
                $sourceComment,
                $sellerKey,
                isset($sessionSellers[$sellerKey]['name']) ? $sessionSellers[$sellerKey]['name'] : $sellerKey,
                $loggedSourceProfile,
                $done,
                'admin',
                $_SESSION['mikhmon']
              );
            }
          }
        }

        $remainingTotal = $availableTotal - $cursor;
        if ($remainingTotal <= 0) {
          $comm = '';
          if ($sourceProfile !== '') {
            $prof = $sourceProfile;
          }
        }

        $batchLinks = array();
        foreach ($createdGroups as $group) {
          $targetCommentUrl = urlencode($group['comment']);
          $batchLinks[] =
            '<div style="margin-top:6px;"><b>' . htmlspecialchars($group['seller_name']) . '</b> : <b>' . (int)$group['qty'] . '</b> ' .
            '<code>' . htmlspecialchars($group['comment']) . '</code> ' .
            '<a href="./?hotspot=users&comment=' . $targetCommentUrl . '&session=' . urlencode($session) . '">' . (isset($_open_group) ? $_open_group : 'Open') . '</a> | ' .
            '<a target="_blank" href="./voucher/print.php?id=' . $targetCommentUrl . '&qr=no&session=' . urlencode($session) . '">' . (isset($_print_group) ? $_print_group : 'Print') . '</a> | ' .
            '<a target="_blank" href="./voucher/print.php?id=' . $targetCommentUrl . '&qr=yes&session=' . urlencode($session) . '">QR</a> | ' .
            '<a target="_blank" href="./voucher/print.php?id=' . $targetCommentUrl . '&small=yes&session=' . urlencode($session) . '">Small</a></div>';
        }

        $batchDistributionMsg = '<div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-top:10px;">' .
          '<i class="fa fa-check-circle"></i> ' . (isset($_split_batch_done) ? $_split_batch_done : 'Batch distributed successfully.') .
          ' <b>' . htmlspecialchars($sourceComment) . '</b> (' . $requestedTotal . '/' . $availableTotal . ')' .
          (!empty($batchLinks) ? '<div style="margin-top:8px;">' . implode('', $batchLinks) . '</div>' : '') .
          ($remainingTotal > 0 ? '<div style="margin-top:8px;"><small>' . (isset($_remaining_qty) ? $_remaining_qty : 'Remaining') . ': <b>' . $remainingTotal . '</b></small></div>' : '') .
          '</div>';
      }
    }
  }

  if ($prof == "all") {
    $getuser = $API->comm("/ip/hotspot/user/print");
    $TotalReg = count($getuser);

    $counttuser = $API->comm("/ip/hotspot/user/print", array(
      "count-only" => ""
    ));

  } elseif ($prof != "all") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?profile" => "$prof",
    ));
    $TotalReg = count($getuser);

    $counttuser = $API->comm("/ip/hotspot/user/print", array(
      "count-only" => "",
      "?profile" => "$prof",
    ));

  }
  if ($comm != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?comment" => "$comm",
    //"?uptime" => "00:00:00"
    ));
    $TotalReg = count($getuser);

    $counttuser = $API->comm("/ip/hotspot/user/print", array(
      "count-only" => "",
      "?comment" => "$comm",
    ));
    
  }
  $exp = $_GET['exp'];
  if ($exp != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?limit-uptime" => "1s",
    ));
    
    $counttuser = $API->comm("/ip/hotspot/user/print", array(
      "count-only" => "",
      "?limit-uptime" => "1s",
    ));
    
  }
  $getprofile = $API->comm("/ip/hotspot/user/profile/print");
  $TotalReg2 = count($getprofile);
  $defaultProfileName = 'default';
  if (is_array($getprofile)) {
    foreach ($getprofile as $profileItem) {
      $profileName = isset($profileItem['name']) ? trim($profileItem['name']) : '';
      if (strcasecmp($profileName, 'default') === 0) {
        $defaultProfileName = $profileName;
        break;
      }
    }
  }

  $commentAllUsers = array();
  $commentUnusedUsers = array();
  $commentUnusedCount = 0;
  $commentSourceProfile = $prof != "all" ? $prof : '';
  $commentSourceProfileLabel = $commentSourceProfile;
  if ($comm != "") {
    if (is_array($getuser)) {
      $commentAllUsers = $getuser;
    } else {
      $commentAllUsers = $API->comm("/ip/hotspot/user/print", array(
        "?comment" => "$comm",
      ));
      if (!is_array($commentAllUsers)) {
        $commentAllUsers = array();
      }
    }

    $commentUnusedUsers = $API->comm("/ip/hotspot/user/print", array(
      "?comment" => "$comm",
      "?uptime" => "0s",
    ));
    if (!is_array($commentUnusedUsers)) {
      $commentUnusedUsers = array();
    }
    usort($commentUnusedUsers, function ($left, $right) {
      return strnatcmp(isset($left['name']) ? $left['name'] : '', isset($right['name']) ? $right['name'] : '');
    });
    $commentUnusedCount = count($commentUnusedUsers);

    $commentProfiles = mikhmon_collect_profiles_from_users(!empty($commentAllUsers) ? $commentAllUsers : $commentUnusedUsers);
    if (count($commentProfiles) === 1) {
      $commentSourceProfile = $commentProfiles[0];
      $commentSourceProfileLabel = $commentProfiles[0];
    } elseif (count($commentProfiles) > 1) {
      $commentSourceProfile = $prof != "all" ? $prof : '';
      $commentSourceProfileLabel = implode(', ', $commentProfiles);
    } elseif ($commentSourceProfile !== '') {
      $commentSourceProfileLabel = $commentSourceProfile;
    } else {
      $commentSourceProfileLabel = $defaultProfileName;
      $commentSourceProfile = $defaultProfileName;
    }
  }
  if ($commentSourceProfileLabel === '') {
    $commentSourceProfileLabel = $commentSourceProfile !== '' ? $commentSourceProfile : $defaultProfileName;
  }
  $hasSessionSellers = !empty($sessionSellers);
  $batchSplitReady = ($comm != "" && $commentUnusedCount > 0 && $hasSessionSellers);
  $batchSplitShouldOpen = ($batchDistributionError != '' || !empty($batchDistributionInput) || !$batchSplitReady);
}
?>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
    <h3><i class="fa fa-users"></i> <?= $_users ?>
      <span style="font-size: 14px">
        <?php
        if ($prof != "all" && mikhmon_count_only_result($counttuser) === 0) {
          echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "';</script>";
        } ?>
        <?php if ($isManagerTicketViewer): ?>
         &nbsp; | &nbsp; <a href="./manager.php?action=tickets" title="Retour gérant"><i class="fa fa-briefcase"></i> Gérant</a>
        &nbsp; | &nbsp; <a href="./?hotspot-user=generate&session=<?= $session; ?>" title="Generate User"><i class="fa fa-users"></i> <?= $_generate ?></a>
        <?php else: ?>
         &nbsp; | &nbsp; <a href="./?hotspot-user=add&session=<?= $session; ?>" title="Add User"><i class="fa fa-user-plus"></i> <?= $_add ?></a>
        &nbsp; | &nbsp; <a href="./?hotspot-user=generate&session=<?= $session; ?>" title="Generate User"><i class="fa fa-users"></i> <?= $_generate ?></a>
         &nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=script" title="Download User List as Mikrotik Script"><i class="fa fa-download"></i> Script</a>&nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=csv" title="Download User List as CSV"><i class="fa fa-download"></i> CSV</a>
        <?php endif; ?>
        </span>  &nbsp;
        <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small>
    </h3>
    
</div>
<div class="card-body">
  <div class="row">
   <div class="col-6 pd-t-5 pd-b-5">
  <div class="input-group">
    <div class="input-group-4 col-box-4">
      <input id="filterTable" type="text" style="padding:5.8px;" class="group-item group-item-l" placeholder="<?= $_search ?>">
    </div>
    <div class="input-group-4 col-box-4">
      <select style="padding:5px;" class="group-item group-item-m" onchange="location = this.value; loader()" title="Filter by Profile">
        <option><?= $_profile ?> </option>
        <option value="./?hotspot=users&profile=all&session=<?= $session; ?>"><?= $_show_all ?></option>
      <?php
      for ($i = 0; $i < $TotalReg2; $i++) {
        $profile = $getprofile[$i];
        echo "<option value='./?hotspot=users&profile=" . $profile['name'] . "&session=" . $session . "'>" . $profile['name'] . "</option>";
      }
      ?>
    </select>
  </div>
  <div class="input-group-4 col-box-4">
    <select style="padding:5px;" class="group-item group-item-r" id="comment" name="comment" onchange="location = './?hotspot=users&comment='+ this.value +'&session=<?= $session;?>';">
    <?php
    if ($comm != "") {
    } else {
      echo "<option value=''>".$_comment."</option>";
    }
    $TotalReg = count($getuser);
    for ($i = 0; $i < $TotalReg; $i++) {
      $ucomment = $getuser[$i]['comment'];
      $uprofile = $getuser[$i]['profile'];
      $acomment .= ",".$ucomment."#". $uprofile;
    }

    $ocomment=  explode(",",$acomment);
    
    $comments=array_count_values($ocomment) ;
    foreach ($comments as $tcomment=>$value) {

      if (preg_match('/^[a-z]{2}-[0-9]{3}-/i', explode("#", $tcomment)[0])) {
       
        echo "<option value='" . explode("#",$tcomment)[0] . "' >". explode("#",$tcomment)[0]." ".explode("#",$tcomment)[1]. " [".$value. "]</option>";
       }
 
    }

    ?>
    </select>
  </div>
  </div>
</div>

  <div class="col-6">
    <?php if (!$isManagerTicketViewer && $comm != "") { ?>
  <button class="btn bg-red" onclick="if(confirm('Are you sure to delete username by comment (<?= $comm; ?>)?')){loadpage('./?remove-hotspot-user-by-comment=<?= $comm; ?>&session=<?= $session; ?>');loader();}else{}" title="Remove user by comment <?= $comm; ?>">  <i class="fa fa-trash"></i> <?= $_by_comment ?></button>
    <?php ; }else if (!$isManagerTicketViewer && $exp == "1"){ ?>
  <button class="btn bg-red" onclick="if(confirm('Are you sure to delete users?')){loadpage('./?remove-hotspot-user-expired=1&session=<?= $session; ?>');loader();}else{}" title="Remove user expired">  <i class="fa fa-trash"></i> Expired Users</button>
      <?php } ?>
  <script>
    function printV(a,b){
    var comm = document.getElementById('comment').value;
    var url = "./voucher/print.php?id="+comm+"&"+a+"="+b+"&session=<?= $session; ?>";
    if (comm === "" ){
      <?php if (mikhmon_currency_uses_integer_amounts($currency, $cekindo)) { ?>
      alert('Silakan pilih salah satu Comment terlebih dulu!');
      <?php
    } else { ?>
      alert('Please choose one of the Comments first!');
      <?php
    } ?>
    }else{
      var win = window.open(url, '_blank');
      win.focus();
    }}
  </script>
  <button class="btn bg-primary" title='Print' onclick="printV('qr','no');"><i class="fa fa-print"></i> <?= $_print_default ?></button>
  <button class="btn bg-primary" title='Print QR' onclick="printV('qr','yes');"><i class="fa fa-print"></i> <?= $_print_qr ?></button>
  <button class="btn bg-primary" title='Print Small'onclick="printV('small','yes');"><i class="fa fa-print"></i> <?= $_print_small ?></button>
  <?php if (!$isManagerTicketViewer): ?>
  <button class="btn bg-success" type="button" onclick="toggleBatchSplit();"><i class="fa fa-random"></i> <?= isset($_distribute_lot) ? $_distribute_lot : 'Distribute Batch' ?></button>
  <?php endif; ?>
  </div>
</div>
<?php if ($batchDistributionError != '') { echo $batchDistributionError; } ?>
<?php if ($batchDistributionMsg != '') { echo $batchDistributionMsg; } ?>
<?php if (!$isManagerTicketViewer): ?>
<div id="batchSplitCard" class="card box-bordered mr-t-10 portal-admin-shell" style="display:<?= $batchSplitShouldOpen ? 'block' : 'none' ?>;">
  <div class="card-header">
    <h4><i class="fa fa-random"></i> <?= isset($_distribute_lot) ? $_distribute_lot : 'Distribute Batch' ?></h4>
  </div>
  <div class="card-body">
    <?php if (!$hasSessionSellers) { ?>
    <div class="bg-warning" style="padding:10px 14px;border-left:4px solid #f39c12;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_seller) ? $_seller : 'Vendor' ?></b><br>
      <small><?= isset($_split_batch_add_seller_first) ? $_split_batch_add_seller_first : 'Create at least one vendor on this session before distributing a batch.' ?></small>
    </div>
    <?php } elseif ($comm == "") { ?>
    <div class="bg-warning" style="padding:10px 14px;border-left:4px solid #f39c12;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_select_batch_first) ? $_select_batch_first : 'Select a batch comment first.' ?></b><br>
      <small><?= isset($_split_batch_pick_comment_help) ? $_split_batch_pick_comment_help : 'Choose a batch comment from the list above to split it between vendors and print each sub-batch separately.' ?></small>
    </div>
    <?php } else { ?>
    <div class="bg-light" style="padding:10px 14px;border-left:4px solid #27ae60;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_source_lot) ? $_source_lot : 'Source batch' ?></b>: <code><?= htmlspecialchars($comm) ?></code><br>
      <b><?= $_profile ?></b>: <?= htmlspecialchars($commentSourceProfileLabel) ?><br>
      <b><?= isset($_available_qty) ? $_available_qty : 'Available tickets' ?></b>: <span id="batchAvailableQty"><?= (int)$commentUnusedCount ?></span><br>
      <small><?= isset($_redistribute_hint) ? $_redistribute_hint : 'Enter quantities by vendor. Each sub-batch can then be opened and printed independently.' ?></small>
    </div>
    <?php if ($commentUnusedCount <= 0) { ?>
    <div class="bg-warning" style="padding:10px 14px;border-left:4px solid #f39c12;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets available.' ?></b><br>
      <small><?= isset($_split_batch_no_stock_hint) ? $_split_batch_no_stock_hint : 'This batch no longer has unused tickets. Choose another batch or generate new stock.' ?></small>
    </div>
    <?php } else { ?>
    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="source_comment" value="<?= htmlspecialchars($comm) ?>">
      <input type="hidden" name="source_profile" value="<?= htmlspecialchars($commentSourceProfile) ?>">
      <div class="table-responsive portal-table-wrap">
        <table class="table table-bordered portal-table-min-sm">
          <thead>
            <tr>
              <th><?= isset($_seller) ? $_seller : 'Vendor' ?></th>
              <th style="width:140px;"><?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></th>
              <th><?= isset($_comment) ? $_comment : 'Comment' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessionSellers as $sellerKey => $sellerData): ?>
            <tr>
              <td><b><?= htmlspecialchars($sellerData['name']) ?></b><br><small><code><?= htmlspecialchars($sellerKey) ?></code></small></td>
              <td>
                <input
                  class="form-control batch-vendor-qty"
                  type="number"
                  min="0"
                  max="<?= (int)$commentUnusedCount ?>"
                  name="batch_vendor_qty[<?= htmlspecialchars($sellerKey) ?>]"
                  value="<?= isset($batchDistributionInput[$sellerKey]) ? (int)$batchDistributionInput[$sellerKey] : 0 ?>"
                  oninput="updateBatchSplitTotals()"
                >
              </td>
              <td><code><?= htmlspecialchars(mikhmon_comment_assign_seller($comm, $sellerKey, $sellers_data)) ?></code></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-bottom:12px;">
        <b><?= isset($_assigned_total) ? $_assigned_total : 'Assigned' ?></b>: <span id="batchAssignedQty">0</span>
        &nbsp; | &nbsp;
        <b><?= isset($_remaining_qty) ? $_remaining_qty : 'Remaining' ?></b>: <span id="batchRemainingQty"><?= (int)$commentUnusedCount ?></span>
      </div>
      <button type="submit" name="split_batch" class="btn bg-success">
        <i class="fa fa-check"></i> <?= isset($_distribute_lot) ? $_distribute_lot : 'Distribute Batch' ?>
      </button>
    </form>
    <?php } ?>
    <?php } ?>
  </div>
</div>
<script>
function toggleBatchSplit() {
  var card = document.getElementById('batchSplitCard');
  if (!card) return;
  card.style.display = card.style.display === 'none' ? 'block' : 'none';
}
function updateBatchSplitTotals() {
  var qtyInputs = document.querySelectorAll('.batch-vendor-qty');
  var totalAssigned = 0;
  for (var i = 0; i < qtyInputs.length; i++) {
    totalAssigned += parseInt(qtyInputs[i].value || '0', 10);
  }
  var available = parseInt(document.getElementById('batchAvailableQty').textContent || '0', 10);
  document.getElementById('batchAssignedQty').textContent = totalAssigned;
  document.getElementById('batchRemainingQty').textContent = available - totalAssigned;
}
updateBatchSplitTotals();
</script>
<?php endif; ?>
<div class="overflow mr-t-10 box-bordered portal-table-wrap" style="max-height: 75vh">
<table id="dataTable" class="table table-bordered table-hover text-nowrap portal-table-min-lg">
  <thead>
  <tr>
    <th style="min-width:50px;" class="align-middle text-center" id="cuser"><?= mikhmon_count_only_result($counttuser); ?></th>
    <th style="min-width:50px;" class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Server</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_name ?></th>
    <th>Print</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_profile ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Mac Address</th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_uptime_user ?></th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> Bytes In</th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> Bytes Out</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_comment ?></th>
    </tr>
  </thead>
  <tbody id="tbody">
<?php
for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  $uid = $userdetails['.id'];
  $userver = $userdetails['server'];
  $uname = $userdetails['name'];
  $upass = $userdetails['password'];
  $uprofile = $userdetails['profile'];
  $umacadd = $userdetails['mac-address'];
  $uuptime = formatDTM($userdetails['uptime']);
  $ubytesi = formatBytes($userdetails['bytes-in'], 2);
  $ubyteso = formatBytes($userdetails['bytes-out'], 2);

  $ucomment = $userdetails['comment'];
  $udisabled = $userdetails['disabled'];
  $utimelimit = $userdetails['limit-uptime'];
  if ($utimelimit == '1s') {
    $utimelimit = ' expired';
  } else {
    $utimelimit = ' ' . $utimelimit;
  }
  $udatalimit = $userdetails['limit-bytes-total'];
  if ($udatalimit == '') {
    $udatalimit = '';
  } else {
    $udatalimit = ' ' . formatBytes($udatalimit, 2);
  }

  echo "<tr>";
  ?>
  <td style='text-align:center;'>
  <?php
  if ($isManagerTicketViewer) {
    echo '<span class="text-muted" title="Lecture seule"><i class="fa fa-eye"></i></span></td>';
  } else {
    echo '<i class="fa fa-minus-square text-danger pointer" onclick="if(confirm(\'Are you sure to delete username (' . $uname . ')?\')){loadpage(\'./?remove-hotspot-user=' . $uid . '&session=' . $session . '\')}else{}" title="Remove ' . $uname . '"></i>&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp';
    if ($udisabled == "true") {
      $uriprocess = "'./?enable-hotspot-user=" . $uid . "&session=" . $session."'";
      echo '<span class="text-warning pointer" title="Enable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-lock "></i></span></td>';
    } else {
      $uriprocess = "'./?disable-hotspot-user=" . $uid . "&session=" . $session."'";
      echo '<span class="pointer" title="Disable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-unlock "></i></span></td>';
    }
  }
  echo "<td>" . $userver . "</td>";
  if ($uname == $upass) {
    $usermode = "vc";
  } else {
    $usermode = "up";
  }
  $popup = "javascript:window.open('./voucher/print.php?user=" . $usermode . "-" . $uname . "&qr=no&session=" . $session . "','_blank','width=320,height=550').print();";
  $popupQR = "javascript:window.open('./voucher/print.php?user=" . $usermode . "-" . $uname . "&qr=yes&session=" . $session . "','_blank','width=320,height=550').print();";
  if ($isManagerTicketViewer) {
    echo "<td><span title='User " . $uname . "'><i class='fa fa-user'></i> " . $uname . " </span>";
  } else {
    echo "<td><a title='Open User " . $uname . "' href=./?hotspot-user=" . $uid . "&session=" . $session . "><i class='fa fa-edit'></i> " . $uname . " </a>";
  }
  echo '</td><td class"text-center"><a title="Print ' . $uname . '" href="' . $popup . '"><i class="fa fa-print"></i></a> &nbsp <a title="Print ' . $uname . '" href="' . $popupQR . '"><i class="fa fa-qrcode"></i> </a></td>';
  echo "<td>" . $uprofile . "</td>";
  echo "<td style=' text-align:left'>" . $umacadd . "</td>";
  echo "<td style=' text-align:right'>" . $uuptime . "</td>";
  echo "<td style=' text-align:right'>" . $ubytesi . "</td>";
  echo "<td style=' text-align:right'>" . $ubyteso . "</td>";
  echo "<td>";
  if ($uname == "default-trial") {
  } else if (substr($ucomment,0,3) == "vc-" || substr($ucomment,0,3) == "up-") {
    echo "<a href=./?hotspot=users&comment=" . $ucomment . "&session=" . $session . " title='Filter by " . $ucomment . "'><i class='fa fa-search'></i> ". $ucomment." ". $udatalimit ." ".$utimelimit . "</a>";
  } else if ($utimelimit == ' expired') {
    echo "<a href=./?hotspot=users&profile=all&exp=1&session=" . $session . " title='Filter by expired'><i class='fa fa-search'></i> " . $ucomment." ". $udatalimit ." ".$utimelimit . "</a>";
  }else{
    echo $ucomment.' ';
  }
  echo  "</td>";


}
?>
  </tr>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>

	
	
