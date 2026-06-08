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
session_start();
// hide all error
error_reporting(0);
include_once(__DIR__ . '/../include/mikhmon_compat.php');
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
// load session MikroTik
  $session = $_GET['session'];
// set  timezone
	date_default_timezone_set(mikhmon_safe_timezone($_SESSION['timezone'] ?? 'UTC'));

// lang
include('../include/lang.php');
include('../lang/'.$langid.'.php');


// load config
  include('../include/config.php');
  include('../include/readcfg.php');
	  include_once('../include/mikhmon_compat.php');
  include('../include/sellers_config.php');

// routeros api
  include_once('../lib/routeros_api.class.php');
  include_once('../lib/formatbytesbites.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
// get selling report
    $thisD = date("d");
    $thisM = strtolower(date("M"));
    $thisY = date("Y");

    if (strlen($thisD) == 1) {
      $thisD = "0" . $thisD;
    } else {
      $thisD = $thisD;
    }

    $idhr = $thisM . "/" . $thisD . "/" . $thisY;
    $idbl = $thisM . $thisY;

    $_SESSION[$session.'idhr'] = $idhr;

   /* $getSRHr = $API->comm("/system/script/print", array(
      "?source" => "$idhr",
    ));
    $TotalRHr = count($getSRHr);
    $_SESSION[$session.'totalHr'] = $TotalRHr;*/
    $getSales = $API->comm("/system/script/print", array(
      "?comment" => "mikhmon",
    ));
    $TotalRBl = 0;
    $TotalRHr = 0;
    $tHr = 0;
    $tBl = 0;

    // per-seller counters with profile breakdown
    $sellerStats = array();
    if (!empty($sellers_data)) {
      foreach (array_keys($sellers_data) as $sk) {
        $sellerStats[$sk] = array(
          'today' => 0, 'month' => 0,
          'today_rev' => 0.0, 'month_rev' => 0.0,
          'profiles' => array()
        );
      }
    }

    foreach ($getSales as $row) {
      $sale = mikhmon_parse_sale_script($row);
      if ($sale['month_key'] == $idbl) {
        $tBl += mikhmon_parse_money_amount($sale['price']);
        $TotalRBl++;

        if ($sale['date'] == $idhr) {
          $tHr += mikhmon_parse_money_amount($sale['price']);
          $TotalRHr++;
        }

        // match sale to a seller via comment field
        $rawComment = strtolower(trim($sale['comment']));
        foreach (array_keys($sellerStats) as $sk) {
          $suffix = '-' . strtolower($sk);
          if ($rawComment === strtolower($sk) ||
              substr($rawComment, -strlen($suffix)) === $suffix) {
            $prof  = ($sale['profile'] !== '') ? $sale['profile'] : '(sans profil)';
            $price = mikhmon_parse_money_amount($sale['price']);
            if (!isset($sellerStats[$sk]['profiles'][$prof])) {
              $sellerStats[$sk]['profiles'][$prof] = array(
                'today' => 0, 'month' => 0, 'price' => $price
              );
            }
            $sellerStats[$sk]['profiles'][$prof]['month']++;
            $sellerStats[$sk]['month']++;
            $sellerStats[$sk]['month_rev'] += $price;
            if ($sale['date'] == $idhr) {
              $sellerStats[$sk]['profiles'][$prof]['today']++;
              $sellerStats[$sk]['today']++;
              $sellerStats[$sk]['today_rev'] += $price;
            }
            break;
          }
        }
      }
    }

    $_SESSION[$session.'totalBl'] = $TotalRBl;
    $_SESSION[$session.'totalHr'] = $TotalRHr;
    $_SESSION[$session.'sellerStats'] = $sellerStats;
  }
}
?>

            <div id="r_4" class="row">
              <div <?= $lreport; ?> class="box bmh-75 box-bordered">
                <div class="box-group">
                  <div class="box-group-icon"><i class="fa fa-money"></i></div>
                    <div class="box-group-area">
                      <span >
                        <div id="reloadLreport">
                        <?php
                          $dincome = mikhmon_format_money_amount($tHr, $currency, $cekindo);
                          $mincome = mikhmon_format_money_amount($tBl, $currency, $cekindo);
                          $_SESSION[$session.'dincome'] = $dincome;
                          $_SESSION[$session.'mincome'] = $mincome;
                          echo $_income."<br/>" . "
                          ".$_today." " . $TotalRHr . "vcr : " . $dincome . "<br/>
                          ".$_this_month." " . $TotalRBl . "vcr : " . $mincome;
                          ?>
                        </div>
                    </span>
                </div>
              </div>
            </div>
            </div>

<div id="r_sellers">
<?php if (!empty($sellers_data) && ($livereport == "enable" || $livereport == "")):
  $statsToShow = isset($_SESSION[$session.'sellerStats']) ? $_SESSION[$session.'sellerStats'] : array();
  $fmtNum = function($n) use ($currency, $cekindo) {
    return mikhmon_format_money_amount((float)$n, $currency, $cekindo);
  };
?>
<div class="card box-bordered" style="margin-top:10px;">
  <div class="card-header">
    <h3><i class="fa fa-users"></i> <?= $_seller_sales ?></h3>
  </div>
  <div class="card-body" style="padding:8px;">
    <div class="overflow" style="-webkit-overflow-scrolling:touch;">
    <table class="table table-sm table-bordered table-hover" style="font-size:13px;margin-bottom:0;">
      <thead class="thead-light">
        <tr>
          <th style="width:30%"><?= $_seller ?> / <?= $_profile ?></th>
          <th class="text-center" style="width:12%"><?= $_seller_unit_price ?></th>
          <th class="text-center" style="width:14%"><?= $_seller_qty ?><br><small><?= $_today ?></small></th>
          <th class="text-center" style="width:14%"><?= $_seller_ca ?><br><small><?= $_today ?></small></th>
          <th class="text-center" style="width:14%"><?= $_seller_qty ?><br><small><?= $_this_month ?></small></th>
          <th class="text-center" style="width:16%"><?= $_seller_ca ?><br><small><?= $_this_month ?></small></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sellers_data as $su => $sd):
        $st = isset($statsToShow[$su]) ? $statsToShow[$su] : array('today'=>0,'month'=>0,'today_rev'=>0,'month_rev'=>0,'profiles'=>array());
        $profiles = isset($st['profiles']) ? $st['profiles'] : array();
        ksort($profiles);
      ?>
        <tr style="background:rgba(255,255,255,0.08);">
          <td colspan="6" style="padding:5px 8px;">
            <i class="fa fa-user"></i>
            <b><?= htmlspecialchars($sd['name']) ?></b>
            <small style="opacity:.7;">(<?= htmlspecialchars($su) ?>)</small>
            &nbsp;—&nbsp;
            <span class="badge bg-success" style="font-size:12px;"><?= $st['today'] ?> <?= $_seller_vcr_today ?></span>
            &nbsp;
            <span class="badge bg-primary" style="font-size:12px;"><?= $st['month'] ?> <?= $_seller_vcr_month ?></span>
          </td>
        </tr>
        <?php if (empty($profiles)): ?>
        <tr>
          <td colspan="6" class="text-center" style="opacity:.5;font-style:italic;"><?= $_seller_no_sales ?></td>
        </tr>
        <?php else: ?>
        <?php foreach ($profiles as $profName => $ps): ?>
        <tr>
          <td style="padding-left:20px;"><i class="fa fa-ticket" style="opacity:.5;"></i> <?= htmlspecialchars($profName) ?></td>
          <td class="text-center"><?= $fmtNum($ps['price']) ?></td>
          <td class="text-center"><?= $ps['today'] ?> vcr</td>
          <td class="text-center"><?= $fmtNum($ps['today'] * $ps['price']) ?></td>
          <td class="text-center"><?= $ps['month'] ?> vcr</td>
          <td class="text-center"><?= $fmtNum($ps['month'] * $ps['price']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold;border-top:2px solid rgba(255,255,255,0.2);">
          <td style="padding-left:20px;"><?= $_seller_subtotal ?></td>
          <td></td>
          <td class="text-center"><?= $st['today'] ?> vcr</td>
          <td class="text-center"><?= $fmtNum($st['today_rev']) ?></td>
          <td class="text-center"><?= $st['month'] ?> vcr</td>
          <td class="text-center"><?= $fmtNum($st['month_rev']) ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>
</div>
