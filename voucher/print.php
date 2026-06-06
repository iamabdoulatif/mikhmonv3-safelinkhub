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

error_reporting(0);
include_once(__DIR__ . '/../include/mikhmon_compat.php');

ob_start("ob_gzhandler");

if (!isset($_SESSION["mikhmon"]) && empty($_SESSION['seller_username']) && empty($_SESSION['manager_username'])) {
  header("Location:../admin.php?id=login");
} else {
  
  date_default_timezone_set($_SESSION['timezone'] ?? 'UTC');
  
// load session MikroTik
  $session = $_GET['session'];
  if (empty($_SESSION["mikhmon"])) {
    if (!empty($_SESSION['seller_session'])) {
      $session = $_SESSION['seller_session'];
    } elseif (!empty($_SESSION['manager_session'])) {
      $session = $_SESSION['manager_session'];
    }
  }

// load config
  include('../include/config.php');
  include('../include/readcfg.php');
  if (empty($mikhmon_router_session_valid)) {
    ob_end_clean();
    if (!empty($_SESSION['seller_username'])) {
      header("Location:../sellers.php?action=tickets");
    } elseif (!empty($_SESSION['manager_username'])) {
      header("Location:../manager.php?action=tickets");
    } else {
      $missingSession = rawurlencode((string)$session);
      header("Location:../admin.php?id=sessions&missing-session=" . $missingSession);
    }
    exit;
  }
  include_once('../include/sellers_config.php');
  include_once('../include/seller_ticket_helper.php');
  include_once('../include/hotspot_account_assignment.php');

  include('../lib/formatbytesbites.php');

  if (!isset($sellers_data) || !is_array($sellers_data)) {
    $sellers_data = array();
  }

  function mikhmon_resolve_voucher_seller($comment, $sellersData) {
    $normalizedComment = strtolower(trim((string)$comment));
    if ($normalizedComment === '') {
      return '';
    }

    if (function_exists('mikhmon_comment_seller_key')) {
      $sellerKey = mikhmon_comment_seller_key($comment, $sellersData);
      if ($sellerKey !== '') {
        $sellerName = trim(isset($sellersData[$sellerKey]['name']) ? (string)$sellersData[$sellerKey]['name'] : '');
        return $sellerName !== '' ? $sellerName : $sellerKey;
      }
    }

    if (function_exists('mikhmon_hotspot_assignment_from_comment')) {
      $assignment = mikhmon_hotspot_assignment_from_comment($comment);
      if ($assignment !== null && $assignment['role'] === 'seller') {
        $sellerKey = $assignment['account_key'];
        if (isset($sellersData[$sellerKey])) {
          $sellerName = trim(isset($sellersData[$sellerKey]['name']) ? (string)$sellersData[$sellerKey]['name'] : '');
          return $sellerName !== '' ? $sellerName : $sellerKey;
        }
        $baseName = trim((string)$assignment['base_comment']);
        return $baseName !== '' ? $baseName : $sellerKey;
      }
    }

    if (!empty($sellersData)) {
      foreach ($sellersData as $sellerKey => $sellerData) {
        $normalizedSeller = strtolower(trim((string)$sellerKey));
        if ($normalizedSeller === '') {
          continue;
        }

        $suffix = '-' . $normalizedSeller;
        if ($normalizedComment === $normalizedSeller || substr($normalizedComment, -strlen($suffix)) === $suffix) {
          $sellerName = trim(isset($sellerData['name']) ? (string)$sellerData['name'] : '');
          return $sellerName !== '' ? $sellerName : $sellerKey;
        }
      }
    }

    if (strpos($normalizedComment, '-') !== false) {
      $parts = explode('-', $normalizedComment);
      $fallbackKey = trim((string) end($parts));
      if ($fallbackKey !== '') {
        return ucwords(str_replace(array('_', '-'), ' ', $fallbackKey));
      }
    }

    return '';
  }

  $id = $_GET['id'];
  $qr = $_GET['qr'];
  $small = $_GET['small'];
  $template = isset($_GET['template']) ? $_GET['template'] : '';
  $userp = $_GET['user'];
  $usersPayload = isset($_POST['users_payload']) ? $_POST['users_payload'] : '';

  require('../lib/routeros_api.class.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  

  $getuser = array();
  if ($usersPayload != '') {
    $selectedUsers = json_decode($usersPayload, true);
    if (!is_array($selectedUsers)) {
      $selectedUsers = array();
    }
    $selectedUsers = array_values(array_unique(array_filter(array_map('trim', $selectedUsers))));
    if (!empty($selectedUsers)) {
      $allUsers = $API->comm('/ip/hotspot/user/print');
      $selectedLookup = array_fill_keys($selectedUsers, true);
      $userMap = array();
      foreach ($allUsers as $row) {
        $rowName = isset($row['name']) ? trim($row['name']) : '';
        if ($rowName !== '' && isset($selectedLookup[$rowName])) {
          $userMap[$rowName] = $row;
        }
      }
      foreach ($selectedUsers as $selectedName) {
        if (isset($userMap[$selectedName])) {
          $getuser[] = $userMap[$selectedName];
        }
      }
      $TotalReg = count($getuser);
    }
  } elseif ($userp != "") {
    $usermode = explode('-', $userp)[0];
    $pulluser = explode('-', $userp);
    $iuser = count($pulluser);
    $prefix = explode('-', $userp)[$iuser - 2];
    $user = explode('-', $userp)[$iuser - 1];
    if ($iuser == 3) {
      $user = $prefix . "-" . $user;
    } else {
      $user = $user;
    }
    $getuser = $API->comm("/ip/hotspot/user/print", array("?name" => "$user"));
    $TotalReg = count($getuser);
  } elseif ($id != "") {
    $usermode = explode('-', $id)[0];
    $getuser = $API->comm('/ip/hotspot/user/print', array("?comment" => "$id", "?uptime" => "0s"));
    $TotalReg = count($getuser);
  }
  $getuprofile = isset($getuser[0]['profile']) ? $getuser[0]['profile'] : '';

  function mikhmon_profile_voucher_meta($API, $profileName, $currency, $cekindo, &$cache) {
    if ($profileName === '') {
      return array('validity' => '', 'price' => '');
    }
    if (isset($cache[$profileName])) {
      return $cache[$profileName];
    }

    $profileDetails = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profileName"));
    $ponlogin = isset($profileDetails[0]['on-login']) ? $profileDetails[0]['on-login'] : '';
    $validity = isset(explode(",", $ponlogin)[3]) ? explode(",", $ponlogin)[3] : '';
    $getprice = isset(explode(",", $ponlogin)[2]) ? explode(",", $ponlogin)[2] : '0';
    $getsprice = isset(explode(",", $ponlogin)[4]) ? explode(",", $ponlogin)[4] : '0';
    $price = '';

    if ($getsprice == "0" && $getprice != "0") {
      $price = mikhmon_format_money_amount(mikhmon_parse_money_amount($getprice), $currency, $cekindo);
    } elseif ($getsprice != "0") {
      $price = mikhmon_format_money_amount(mikhmon_parse_money_amount($getsprice), $currency, $cekindo);
    }

    $cache[$profileName] = array(
      'validity' => $validity,
      'price' => $price,
    );

    return $cache[$profileName];
  }

    
  

  $logo = "../img/logo-" . $session . ".png";
  if (file_exists($logo)) {
    $logo = "../img/logo-" . $session . ".png?t=". str_replace(" ","_",date("Y-m-d H:i:s"));
  } else {
    $logo = "../img/logo.png?t=". str_replace(" ","_",date("Y-m-d H:i:s"));
  }

}
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Voucher-<?= $hotspotname . "-" . $getuprofile . "-" . $id; ?></title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta http-equiv="pragma" content="no-cache" />
		<link rel="icon" href="../img/favicon.png" />
		<script src="../js/qrious.min.js"></script>
		<style>
body {
  color: #000000;
  background-color: #FFFFFF;
  font-size: 14px;
  font-family:  'Helvetica', arial, sans-serif;
  margin: 0px;
  -webkit-print-color-adjust: exact;
}
table.voucher {
  display: inline-block;
  border: 2px solid black;
  margin: 2px;
}
@page
{
  size: auto;
  margin-left: 7mm;
  margin-right: 3mm;
  margin-top: 9mm;
  margin-bottom: 3mm;
}
@media print
{
  table { page-break-after:auto }
  tr    { page-break-inside:avoid; page-break-after:auto }
  td    { page-break-inside:avoid; page-break-after:auto }
  thead { display:table-header-group }
  tfoot { display:table-footer-group }
}
#num {
  float:right;
  display:inline-block;
}
.qrc {
  width:30px;
  height:30px;
  margin-top:1px;
}
		</style>
	</head>
	<body onload="window.print()">

<?php $profileMetaCache = array(); ?>
<?php for ($i = 0; $i < $TotalReg; $i++) {;
  $regtable = $getuser[$i];
  $uid = str_replace("=","",base64_encode($regtable['.id']));
  $idqr = str_replace("=","",base64_encode(($regtable['.id']."qr")));
  $username = $regtable['name'];
  $password = $regtable['password'];
  $profile = $regtable['profile'];
  $profileMeta = mikhmon_profile_voucher_meta($API, $profile, $currency, $cekindo, $profileMetaCache);
  $validity = $profileMeta['validity'];
  $price = $profileMeta['price'];
  $timelimit = $regtable['limit-uptime'];
  $getdatalimit = $regtable['limit-bytes-total'];
  $comment = $regtable['comment'];
  $voucherSellerName = mikhmon_resolve_voucher_seller($comment, $sellers_data);
  if ($getdatalimit == 0) {
    $datalimit = "";
  } else {
    $datalimit = formatBytes($getdatalimit, 2);
  }
  
  $urilogin = "http://$dnsname/login?username=$username&password=$password";
  $qrcode = "
	<canvas class='qrcode' id='".$uid."'></canvas>
    <script>
      (function() {
        var ".$uid." = new QRious({
          element: document.getElementById('".$uid."'),
          value: '".$urilogin."',
          size:'256'
        });

      })();
    </script>
	";
 
  $num = $i + 1;
  $currentUsermode = $usermode;
  if ($currentUsermode === '' || ($currentUsermode !== 'vc' && $currentUsermode !== 'up')) {
    $rowPassword = isset($regtable['password']) ? trim((string) $regtable['password']) : '';
    $currentUsermode = ($rowPassword !== '' && $rowPassword !== $username) ? 'up' : 'vc';
  }
  $usermode = $currentUsermode;
  ?>
<?php
if ($userp != "") {
  include('./template-thermal.php');
} else {
  if ($template == "safetmp") {
    include('./safetmp.php');
  } elseif ($small == "yes") {
    include('./template-small.php');
  } else {
    include('./template.php');
  }
}
?>
<?php 
} ?>

	
</body>
</html>
