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
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
// hide all error
error_reporting(0);

ini_set('max_execution_time', 600);

$isStandaloneGenerator = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'generateuser.php';
$appPrefix = $isStandaloneGenerator ? '../' : './';

// Charger csrf si non déjà inclus (admin route via index.php n'inclut pas csrf)
if (!function_exists('csrf_field')) {
	include_once(__DIR__ . '/../include/csrf.php');
}
if (!function_exists('mikhmon_format_money_amount')) {
	include_once(__DIR__ . '/../include/mikhmon_compat.php');
}
if (!function_exists('formatBytes')) {
	include_once(__DIR__ . '/../lib/formatbytesbites.php');
}

if (!function_exists('mikhmon_hotspot_user_add_payload')) {
	function mikhmon_hotspot_user_add_payload($name, $password, $server, $profile, $timelimit, $datalimit, $comment)
	{
		return array(
			"server" => "$server",
			"name" => "$name",
			"password" => "$password",
			"profile" => "$profile",
			"limit-uptime" => "$timelimit",
			"limit-bytes-total" => "$datalimit",
			"comment" => "$comment",
		);
	}

	function mikhmon_hotspot_add_users_slow($API, $users, $server, $profile, $timelimit, $datalimit, $comment)
	{
		foreach ($users as $user) {
			$API->comm("/ip/hotspot/user/add", mikhmon_hotspot_user_add_payload(
				$user['name'],
				$user['password'],
				$server,
				$profile,
				$timelimit,
				$datalimit,
				$comment
			));
		}
	}

	function mikhmon_hotspot_user_add_script_line($user, $server, $profile, $timelimit, $datalimit, $comment)
	{
		$parts = array(
			'/ip hotspot user add',
			'server=' . mikhmon_routeros_quote($server),
			'name=' . mikhmon_routeros_quote($user['name']),
			'password=' . mikhmon_routeros_quote($user['password']),
			'profile=' . mikhmon_routeros_quote($profile),
			'limit-uptime=' . mikhmon_routeros_quote($timelimit),
			'limit-bytes-total=' . mikhmon_routeros_quote($datalimit),
			'comment=' . mikhmon_routeros_quote($comment),
		);

		return ':do { ' . implode(' ', $parts) . ' } on-error={};';
	}

	function mikhmon_hotspot_script_id($scripts)
	{
		if (!is_array($scripts)) {
			return '';
		}

		foreach ($scripts as $script) {
			if (is_array($script) && isset($script['.id']) && $script['.id'] !== '') {
				return $script['.id'];
			}
		}

		return '';
	}

	function mikhmon_hotspot_add_users_fast($API, $users, $server, $profile, $timelimit, $datalimit, $comment)
	{
		$threshold = function_exists('mikhmon_hotspot_fast_generate_threshold') ? mikhmon_hotspot_fast_generate_threshold() : 20;
		if (count($users) < $threshold) {
			return false;
		}

		$chunkSize = function_exists('mikhmon_hotspot_fast_generate_chunk_size') ? mikhmon_hotspot_fast_generate_chunk_size() : 150;
		$chunkSize = max(1, (int) $chunkSize);
		$chunks = array_chunk($users, $chunkSize);

		foreach ($chunks as $chunkIndex => $chunk) {
			$lines = array();
			foreach ($chunk as $user) {
				$lines[] = mikhmon_hotspot_user_add_script_line($user, $server, $profile, $timelimit, $datalimit, $comment);
			}

			$scriptName = substr('mikhmon-gen-' . date('His') . '-' . mt_rand(1000, 9999) . '-' . ($chunkIndex + 1), 0, 63);
			$addResponse = $API->comm('/system/script/add', array(
				'name' => $scriptName,
				'source' => implode('', $lines),
				'policy' => 'read,write,test',
				'comment' => 'mikhmon-fast-generate',
			));

			if (mikhmon_routeros_response_error($addResponse) !== '') {
				mikhmon_hotspot_add_users_slow($API, array_slice($users, $chunkIndex * $chunkSize), $server, $profile, $timelimit, $datalimit, $comment);
				return true;
			}

			$scriptRows = $API->comm('/system/script/print', array('?name' => $scriptName));
			if (mikhmon_routeros_response_error($scriptRows) !== '') {
				mikhmon_hotspot_add_users_slow($API, array_slice($users, $chunkIndex * $chunkSize), $server, $profile, $timelimit, $datalimit, $comment);
				return true;
			}

			$scriptId = mikhmon_hotspot_script_id($scriptRows);
			if ($scriptId === '') {
				mikhmon_hotspot_add_users_slow($API, array_slice($users, $chunkIndex * $chunkSize), $server, $profile, $timelimit, $datalimit, $comment);
				return true;
			}

			$runResponse = $API->comm('/system/script/run', array('.id' => $scriptId));
			$runError = mikhmon_routeros_response_error($runResponse);
			$API->comm('/system/script/remove', array('numbers' => $scriptId));

			if ($runError !== '') {
				mikhmon_hotspot_add_users_slow($API, array_slice($users, $chunkIndex * $chunkSize), $server, $profile, $timelimit, $datalimit, $comment);
				return true;
			}

		}

		return true;
	}

	function mikhmon_hotspot_existing_user_name_map($API)
	{
		$names = array();
		$rows = $API->comm('/ip/hotspot/user/print', array('.proplist' => 'name'));
		if (mikhmon_routeros_response_error($rows) !== '' || !is_array($rows)) {
			return $names;
		}

		foreach ($rows as $row) {
			if (is_array($row) && isset($row['name']) && trim((string) $row['name']) !== '') {
				$names[(string) $row['name']] = true;
			}
		}

		return $names;
	}

	function mikhmon_hotspot_accept_unique_name($name, &$usedNames)
	{
		$name = (string) $name;
		if ($name === '' || isset($usedNames[$name])) {
			return false;
		}

		$usedNames[$name] = true;
		return true;
	}

	function mikhmon_hotspot_numeric_password($length)
	{
		$length = (int) $length;
		if ($length < 3 || $length > 8) {
			$length = 4;
		}

		return randN($length);
	}

	function mikhmon_hotspot_random_name_part($char, $length)
	{
		$length = max(1, (int) $length);
		if ($char == "lower") {
			return randLC($length);
		}
		if ($char == "upper") {
			return randUC($length);
		}
		if ($char == "upplow") {
			return randULC($length);
		}
		if ($char == "mix") {
			return randNLC($length);
		}
		if ($char == "mix1") {
			return randNUC($length);
		}
		if ($char == "mix2") {
			return randNULC($length);
		}
		if ($char == "num") {
			return randN($length);
		}

		return randNLC($length);
	}

	function mikhmon_hotspot_candidate_credentials($mode, $char, $userl, $prefix)
	{
		$userl = (int) $userl;
		$prefix = (string) $prefix;

		if ($mode == "up") {
			$name = $prefix . mikhmon_hotspot_random_name_part($char, $userl);
			return array(
				'name' => $name,
				'password' => mikhmon_hotspot_numeric_password($userl),
			);
		}

		$a = array("1" => "", "", 1, 2, 2, 3, 3, 4);
		$shuf = max(1, $userl - (int) (isset($a[$userl]) ? $a[$userl] : 2));
		if ($char == "num" || $char == "mix" || $char == "mix1" || $char == "mix2") {
			$name = $prefix . mikhmon_hotspot_random_name_part($char, $userl);
		} else {
			$name = $prefix . mikhmon_hotspot_random_name_part($char, $shuf);
			if ($userl == 3) {
				$name .= randN(1);
			} elseif ($userl == 4 || $userl == 5) {
				$name .= randN(2);
			} elseif ($userl == 6 || $userl == 7) {
				$name .= randN(3);
			} elseif ($userl == 8) {
				$name .= randN(4);
			}
		}

		return array(
			'name' => $name,
			'password' => $name,
		);
	}

	function mikhmon_hotspot_unique_fallback_name($prefix, &$usedNames)
	{
		static $fallbackCounter = 0;
		do {
			$fallbackCounter++;
			$name = (string) $prefix . 'T' . date('His') . strtoupper(base_convert($fallbackCounter, 10, 36));
		} while (isset($usedNames[$name]));

		$usedNames[$name] = true;
		return $name;
	}

	function mikhmon_hotspot_unique_credentials($mode, $char, $userl, $prefix, &$usedNames)
	{
		for ($attempt = 0; $attempt < 2000; $attempt++) {
			$candidate = mikhmon_hotspot_candidate_credentials($mode, $char, $userl, $prefix);
			if (mikhmon_hotspot_accept_unique_name($candidate['name'], $usedNames)) {
				return $candidate;
			}
		}

		$name = mikhmon_hotspot_unique_fallback_name($prefix, $usedNames);
		return array(
			'name' => $name,
			'password' => $mode == "up" ? mikhmon_hotspot_numeric_password($userl) : $name,
		);
	}
}

if ($isStandaloneGenerator) {
	include_once(__DIR__ . '/../lib/routeros_api.class.php');
	include_once(__DIR__ . '/../include/mikhmon_compat.php');
	include_once(__DIR__ . '/../include/lang.php');
	include(__DIR__ . '/../lang/' . $langid . '.php');
	include_once(__DIR__ . '/../include/theme.php');
	include_once(__DIR__ . '/../settings/settheme.php');
	include_once(__DIR__ . '/../include/sellers_config.php');
	include_once(__DIR__ . '/../include/managers_config.php');
	include_once(__DIR__ . '/../include/auth.php');
	include_once(__DIR__ . '/../include/csrf.php');

	if ($_SESSION['theme'] == "") {
		$theme      = $theme;
		$themecolor = $themecolor;
	} else {
		$theme      = $_SESSION['theme'];
		$themecolor = $_SESSION['themecolor'];
	}

	$session = isset($_REQUEST['session']) ? trim($_REQUEST['session']) : ($_SESSION['manager_session'] ?? '');
	include(__DIR__ . '/../include/config.php');
	include(__DIR__ . '/../include/readcfg.php');

	$API = new RouterosAPI();
	$API->debug = false;
		if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
			$gettimezone = $API->comm("/system/clock/print");
			if (!empty($gettimezone[0]['time-zone-name'])) {
				$_SESSION['timezone'] = mikhmon_safe_timezone($gettimezone[0]['time-zone-name'], $_SESSION['timezone'] ?? 'UTC');
				date_default_timezone_set($_SESSION['timezone']);
			} else {
				date_default_timezone_set(mikhmon_safe_timezone($_SESSION['timezone'] ?? 'UTC'));
			}
		} else {
			date_default_timezone_set(mikhmon_safe_timezone($_SESSION['timezone'] ?? 'UTC'));
		}
	}

if (!isset($_SESSION["mikhmon"]) && empty($_SESSION['manager_username'])) {
	if (!empty($_SESSION['seller_username'])) {
		header("Location:../sellers.php?action=tickets");
		exit;
	}
	header("Location:../admin.php?id=login");
} else {
// time zone
	date_default_timezone_set(mikhmon_safe_timezone($_SESSION['timezone'] ?? 'UTC'));

	$generateRole = !empty($_SESSION["mikhmon"]) ? 'admin' : 'manager';
	if (empty($session)) {
		if ($generateRole === 'manager' && !empty($_SESSION['manager_session'])) {
			$session = $_SESSION['manager_session'];
		}
	}

	$genprof = $_GET['genprof'];
	if ($genprof != "") {
		$getprofile = $API->comm("/ip/hotspot/user/profile/print", array(
			"?name" => "$genprof",
		));
		$ponlogin = $getprofile[0]['on-login'];
		$getprice = explode(",", $ponlogin)[2];
		if ($getprice == "0") {
			$getprice = "";
		} else {
			$getprice = $getprice;
		}

		$getvalid = explode(",", $ponlogin)[3];

		$getlocku = explode(",", $ponlogin)[6];
		if ($getlocku == "") {
			$getprice = "Disable";
		} else {
			$getlocku = $getlocku;
		}

		$getprice = mikhmon_format_money_amount(mikhmon_parse_money_amount($getprice), $currency, $cekindo);
		$ValidPrice = "<b>Validity : " . $getvalid . " | Price : " . $getprice . " | Lock User : " . $getlocku . "</b>";
	} else {
	}

	$srvlist = $API->comm("/ip/hotspot/print");
	include_once($appPrefix . 'include/sellers_config.php');
	include_once($appPrefix . 'include/seller_ticket_helper.php');
	$sessionSellers = array();
	$generationError = '';
	$maxGenerateQty = mikhmon_generate_ticket_limit();
	$selectedSellerId = isset($_POST['seller_id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['seller_id'])) : '';
	if (!empty($sellers_data) && is_array($sellers_data)) {
			foreach ($sellers_data as $sellerKey => $sellerData) {
				$sellerSession = isset($sellerData['session']) ? trim($sellerData['session']) : '';
				if ($sellerSession === '' || $sellerSession === $session) {
					$sessionSellers[$sellerKey] = $sellerData;
				}
			}
		}
	if ($selectedSellerId !== '' && !isset($sessionSellers[$selectedSellerId])) {
		$selectedSellerId = '';
	}
	if (isset($_POST['qty'])) {
		csrf_guard();
		
		$qty = max(1, (int) $_POST['qty']);
		$server = ($_POST['server']);
		$user = ($_POST['user']);
		$userl = ($_POST['userl']);
		$prefix = ($_POST['prefix']);
		$char = ($_POST['char']);
		$profile = ($_POST['profile']);
		$timelimit = ($_POST['timelimit']);
		$datalimit = ($_POST['datalimit']);
			$adcomment = trim($_POST['adcomment']);
			$sellerId = isset($_POST['seller_id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['seller_id'])) : '';
			$mbgb = ($_POST['mbgb']);
		if ($timelimit == "") {
			$timelimit = "0";
		} else {
			$timelimit = $timelimit;
		}
		if ($datalimit == "") {
			$datalimit = "0";
		} else {
			$datalimit = $datalimit * $mbgb;
		}
			$adcomment = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $adcomment);
			if ($qty > $maxGenerateQty) {
				$generationError = 'La génération est limitée à ' . $maxGenerateQty . ' tickets par lot.';
			}
			if ($generationError === '') {
				if ($qty > 500 && (int)$userl < 6) {
					$generationError = 'Pour générer plus de 500 tickets, utilisez une longueur de code de 6 caractères minimum.';
				}
			}
			if ($generationError === '' && $sellerId != "" && !isset($sessionSellers[$sellerId])) {
				$sellerId = "";
			}
			if ($generationError === '') {
				if ($sellerId != "") {
					$adcomment = mikhmon_comment_assign_seller($adcomment, $sellerId, $sessionSellers);
				}
			$getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile"));
			$ponlogin = $getprofile[0]['on-login'];
			$getvalid = explode(",", $ponlogin)[3];
			$getprice = explode(",", $ponlogin)[2];
			$getsprice = explode(",", $ponlogin)[4];
			$getlock = explode(",", $ponlogin)[6];
			$_SESSION['ubp'] = $profile;
				$commt = $user . "-" . rand(100, 999) . "-" . date("m.d.y");
				if ($adcomment != "") {
					$commt .= "-" . $adcomment;
				}
			$gentemp = $commt . "|~" . $profile . "~" . $getvalid . "~" . $getprice . "!".$getsprice."~" . $timelimit . "~" . $datalimit . "~" . $getlock;
			$gen = '<?php $genu="'.encrypt($gentemp).'";?>';
			$temp = __DIR__ . '/../voucher/temp.php';
			$handle = fopen($temp, 'w') or die('Cannot open file:  ' . $temp);
			$data = $gen;
			fwrite($handle, $data);

			$batchUsers = array();
			$usedHotspotNames = mikhmon_hotspot_existing_user_name_map($API);

			for ($i = 1; $i <= $qty; $i++) {
				$credentials = mikhmon_hotspot_unique_credentials($user, $char, $userl, $prefix, $usedHotspotNames);
				$u[$i] = $credentials['name'];
				$p[$i] = $credentials['password'];
				$batchUsers[] = $credentials;
			}

			$addedFast = mikhmon_hotspot_add_users_fast($API, $batchUsers, $server, $profile, $timelimit, $datalimit, $commt);
			if (!$addedFast) {
				mikhmon_hotspot_add_users_slow($API, $batchUsers, $server, $profile, $timelimit, $datalimit, $commt);
			}


			if (!$isStandaloneGenerator && $qty < 2) {
				echo "<script>window.location='./?hotspot-user=" . $u[1] . "&session=" . $session . "'</script>";
			} elseif (!$isStandaloneGenerator) {
				echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>";
			}
		}
	}

	$getprofile = $API->comm("/ip/hotspot/user/profile/print");
	include_once($appPrefix . 'voucher/temp.php');
	$genuser = explode("-", decrypt($genu));
	$genuser1 = explode("~", decrypt($genu));
	$umode = $genuser[0];
	$ucode = $genuser[1];
	$udate = $genuser[2];
	$uprofile = $genuser1[1];
	$uvalid = $genuser1[2];
	$ucommt = $genuser[3];
	if ($uvalid == "") {
		$uvalid = "-";
	} else {
		$uvalid = $uvalid;
	}
	$uprice = explode("!",$genuser1[3])[0];
	if ($uprice == "0") {
		$uprice = "-";
	} else {
		$uprice = $uprice;
	}
	$suprice = explode("!",$genuser1[3])[1];
	if ($suprice == "0") {
		$suprice = "-";
	} else {
		$suprice = $suprice;
	}
	$utlimit = $genuser1[4];
	if ($utlimit == "0") {
		$utlimit = "-";
	} else {
		$utlimit = $utlimit;
	}
	$udlimit = $genuser1[5];
	if ($udlimit == "0") {
		$udlimit = "-";
	} else {
		$udlimit = formatBytes($udlimit, 2);
	}
	$ulock = $genuser1[6];
	//$urlprint = "$umode-$ucode-$udate-$ucommt";
	$urlprint = explode("|", decrypt($genu))[0];
	$uprice = mikhmon_format_money_amount(mikhmon_parse_money_amount($uprice), $currency, $cekindo);
	$suprice = mikhmon_format_money_amount(mikhmon_parse_money_amount($suprice), $currency, $cekindo);

}
?>
<?php if ($isStandaloneGenerator): ?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="<?= htmlspecialchars($themecolor ?? '#3a4149') ?>">
	<title>MIKHMON – Génération tickets gérant</title>
	<link rel="stylesheet" type="text/css" href="<?= $appPrefix ?>css/font-awesome/css/font-awesome.min.css">
	<link rel="stylesheet" href="<?= $appPrefix ?>css/mikhmon-ui.<?= htmlspecialchars($theme) ?>.min.css">
	<link rel="stylesheet" href="<?= $appPrefix ?>css/mikhmon-portal.css">
	<link rel="stylesheet" href="<?= $appPrefix ?>css/mikhmon-responsive.css">
	<link rel="icon" href="<?= $appPrefix ?>img/favicon.png">
	<style>
		body { background:#20262e; }
		.manager-generator-shell { max-width:1240px; margin:0 auto; padding:24px 16px 40px; }
		.manager-generator-header { display:flex; gap:12px; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
		.manager-generator-header h1 { margin:0; font-size:28px; color:#f8fafc; }
		.manager-generator-sub { color:#cbd5e1; font-size:14px; }
		.manager-generator-grid { display:grid; grid-template-columns:minmax(0, 1.45fr) minmax(300px, 0.95fr); gap:18px; align-items:start; }
		.manager-generator-card { background:#2f3640; border:1px solid rgba(255,255,255,.08); border-radius:16px; overflow:hidden; box-shadow:0 18px 40px rgba(0,0,0,.18); }
		.manager-generator-card .card-header { padding:18px 20px; }
		.manager-generator-card .card-body { padding:20px; }
		.manager-generator-actions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
		.manager-generator-actions .btn { flex:1 1 160px; text-align:center; }
		@media (max-width: 960px) {
			.manager-generator-grid { grid-template-columns:1fr; }
		}
	</style>
</head>
<body>
<div class="manager-generator-shell">
	<div class="manager-generator-header">
		<div>
			<h1><i class="fa fa-ticket"></i> Génération de tickets</h1>
			<div class="manager-generator-sub">Gérant · session <?= htmlspecialchars($session) ?> · impression immédiate avec le moteur voucher Mikhmon</div>
		</div>
		<div class="manager-generator-actions" style="margin-bottom:0;">
			<a class="btn bg-primary" href="<?= $appPrefix ?>manager.php?action=tickets"><i class="fa fa-arrow-left"></i> Retour gérant</a>
			<a class="btn bg-secondary" href="<?= $appPrefix ?>manager.php?action=transfer"><i class="fa fa-exchange"></i> Transferts</a>
		</div>
	</div>
	<div class="manager-generator-grid">
<?php endif; ?>
<div class="row">

<div class="col-8">
<div class="card box-bordered<?= $isStandaloneGenerator ? ' manager-generator-card' : '' ?>">
	<div class="card-header">
	<h3><i class="fa fa-user-plus"></i> <?= $_generate_user ?> <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small></h3> 
	</div>
	<div class="card-body">
<form autocomplete="off" method="post" action="">
	<?= csrf_field() ?>
	<?php if ($generationError !== ''): ?>
	<div class="bg-danger" style="padding:10px 14px;border-radius:8px;margin-bottom:14px;">
		<i class="fa fa-ban"></i> <?= htmlspecialchars($generationError, ENT_QUOTES, 'UTF-8') ?>
	</div>
	<?php endif; ?>
	<div>
		<?php if ($_SESSION['ubp'] != "") {
		echo "    <a class='btn bg-warning' href='" . ($isStandaloneGenerator && $generateRole === 'manager' ? $appPrefix . "manager.php?action=tickets" : "./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session) . "'> <i class='fa fa-close'></i> ".$_close."</a>";
	} elseif ($_SESSION['vcr'] = "active") {
		echo "    <a class='btn bg-warning' href='" . ($isStandaloneGenerator && $generateRole === 'manager' ? $appPrefix . "manager.php?action=tickets" : "./?hotspot=users-by-profile&session=" . $session) . "'> <i class='fa fa-close'></i> ".$_close."</a>";
	} else {
		echo "    <a class='btn bg-warning' href='" . ($isStandaloneGenerator && $generateRole === 'manager' ? $appPrefix . "manager.php?action=tickets" : "./?hotspot=users&profile=all&session=" . $session) . "'> <i class='fa fa-close'></i> ".$_close."</a>";
	}

	?>
	<?php if (!$isStandaloneGenerator || $generateRole !== 'manager'): ?>
	<a class="btn bg-pink" title="Open User List by Profile 
<?php if ($_SESSION['ubp'] == "") {
	echo "all";
} else {
	echo $uprofile;
} ?>" href="./?hotspot=users&profile=
<?php if ($_SESSION['ubp'] == "") {
	echo "all";
} else {
	echo $uprofile;
} ?>&session=<?= $session; ?>"> <i class="fa fa-users"></i> <?= $_user_list ?></a>
	<?php endif; ?>
    <button type="submit" name="save" onclick="loader()" class="btn bg-primary" title="Generate User"> <i class="fa fa-save"></i> <?= $_generate ?></button>
    <?php if (!empty($urlprint)): ?>
    <a class="btn bg-secondary" title="Print Default" href="<?= $appPrefix ?>voucher/print.php?id=<?= $urlprint; ?>&qr=no&session=<?= $session; ?>" target="_blank"> <i class="fa fa-print"></i> <?= $_print ?></a>
    <a class="btn bg-danger" title="Print QR" href="<?= $appPrefix ?>voucher/print.php?id=<?= $urlprint; ?>&qr=yes&session=<?= $session; ?>" target="_blank"> <i class="fa fa-qrcode"></i> <?= $_print_qr ?></a>
    <a class="btn bg-info" title="Print Small" href="<?= $appPrefix ?>voucher/print.php?id=<?= $urlprint; ?>&small=yes&session=<?= $session; ?>" target="_blank"> <i class="fa fa-print"></i> <?= $_print_small ?></a>
    <?php endif; ?>
</div>
<table class="table">
  <tr>
    <td class="align-middle"><?= $_qty ?></td><td><div><input class="form-control " type="number" name="qty" min="1" max="<?= (int)$maxGenerateQty ?>" value="1" required="1"><small style="display:block;color:#aaa;margin-top:4px;">Max <?= (int)$maxGenerateQty ?> tickets par lot.</small></div></td>
  </tr>
  <tr>
    <td class="align-middle">Server</td>
    <td>
		<select class="form-control " name="server" required="1">
			<option>all</option>
				<?php $TotalReg = count($srvlist);
			for ($i = 0; $i < $TotalReg; $i++) {
				echo "<option>" . $srvlist[$i]['name'] . "</option>";
			}
			?>
		</select>
	</td>
	</tr>
	<tr>
    <td class="align-middle"><?= $_user_mode ?></td><td>
			<select class="form-control " onchange="defUserl();" id="user" name="user" required="1">
				<option value="up"><?= $_user_pass ?></option>
				<option value="vc"><?= $_user_user ?></option>
			</select>
		</td>
	</tr>
  <tr>
    <td class="align-middle"><?= $_user_length ?></td><td>
      <select class="form-control " id="userl" name="userl" required="1">
        <option>4</option>
				<option>3</option>
				<option>4</option>
				<option>5</option>
				<option>6</option>
				<option>7</option>
				<option>8</option>
			</select>
    </td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_prefix ?></td><td><input class="form-control " type="text" size="6" maxlength="6" autocomplete="off" name="prefix" value=""></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_character ?></td><td>
      <select class="form-control " name="char" required="1">
				<option id="lower" style="display:block;" value="lower"><?= $_random ?> abcd</option>
				<option id="upper" style="display:block;" value="upper"><?= $_random ?> ABCD</option>
				<option id="upplow" style="display:block;" value="upplow"><?= $_random ?> aBcD</option>
				<option id="lower1" style="display:none;" value="lower"><?= $_random ?> abcd2345</option>
				<option id="upper1" style="display:none;" value="upper"><?= $_random ?> ABCD2345</option>
				<option id="upplow1" style="display:none;" value="upplow"><?= $_random ?> aBcD2345</option>
				<option id="mix" style="display:block;" value="mix"><?= $_random ?> 5ab2c34d</option>
				<option id="mix1" style="display:block;" value="mix1"><?= $_random ?> 5AB2C34D</option>
				<option id="mix2" style="display:block;" value="mix2"><?= $_random ?> 5aB2c34D</option>
				<option id="num" style="display:none;" value="num"><?= $_random ?> 1234</option>
			</select>
    </td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_profile ?></td><td>
			<select class="form-control " onchange="GetVP();" id="uprof" name="profile" required="1">
				<?php if ($genprof != "") {
				echo "<option>" . $genprof . "</option>";
			} else {
			}
			$TotalReg = count($getprofile);
			for ($i = 0; $i < $TotalReg; $i++) {
				echo "<option>" . $getprofile[$i]['name'] . "</option>";
			}
			?>
			</select>
		</td>
	</tr>
	<tr>
    <td class="align-middle"><?= $_time_limit ?></td><td><input class="form-control " type="text" size="4" autocomplete="off" name="timelimit" value=""></td>
  </tr>
	<tr>
    <td class="align-middle"><?= $_data_limit ?></td><td>
      <div class="input-group">
      	<div class="input-group-10 col-box-9">
        	<input class="group-item group-item-l" type="number" min="0" max="9999" name="datalimit" value="<?= $udatalimit; ?>">
    	</div>
          <div class="input-group-2 col-box-3">
              <select style="padding:4.2px;" class="group-item group-item-r" name="mbgb" required="1">
				        <option value=1048576>MB</option>
				        <option value=1073741824>GB</option>
			        </select>
          </div>
      </div>
    </td>
  </tr>
	<tr>
		<td class="align-middle"><?= isset($_seller) ? $_seller : 'Vendeur' ?></td>
		<td>
			<select class="form-control " name="seller_id"<?= empty($sessionSellers) ? ' disabled' : '' ?>>
				<?php if (empty($sessionSellers)): ?>
				<option value="">Stock général - aucun vendeur disponible</option>
				<?php else: ?>
				<option value=""<?= $selectedSellerId === '' ? ' selected' : '' ?>>Stock général - distribuer plus tard</option>
				<?php foreach ($sessionSellers as $sellerKey => $sellerData): ?>
					<option value="<?= htmlspecialchars($sellerKey) ?>"<?= $selectedSellerId === $sellerKey ? ' selected' : '' ?>><?= htmlspecialchars($sellerData['name']) ?> (<?= htmlspecialchars($sellerKey) ?>)</option>
				<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<small style="display:block;padding-top:6px;color:#9aa0a6;">
				<?php if (empty($sessionSellers)): ?>
					Aucun vendeur n'est disponible. Le lot sera généré en <b>stock général</b> et pourra être distribué plus tard après création des vendeurs.
				<?php else: ?>
					Choisissez un vendeur pour lui attribuer le lot immédiatement, ou laissez <b>Stock général</b> pour générer maintenant et distribuer plus tard.
				<?php endif; ?>
			</small>
		</td>
	</tr>
		<tr>
	    <td class="align-middle"><?= $_comment ?></td><td><input class="form-control " type="text" title="No special characters" id="comment" autocomplete="off" name="adcomment" value=""></td>
	  </tr>
   <tr >
    <td  colspan="4" class="align-middle w-12"  id="GetValidPrice">
    	<?php if ($genprof != "") {
					echo $ValidPrice;
				} ?>
    </td>
  </tr>
</table>
</form>
</div>
</div>
</div>

<div class="col-4">
	<div class="card<?= $isStandaloneGenerator ? ' manager-generator-card' : '' ?>">
		<div class="card-header">
			<h3><i class="fa fa-ticket"></i> <?= $_last_generate ?></h3>
		</div>
		<div class="card-body">
<table class="table table-bordered">
  <tr>
  	<td><?= $_generate_code ?></td><td><?= $ucode ?></td>
  </tr>
  <tr>
  	<td><?= $_date ?></td><td><?= $udate ?></td>
  </tr>
  <tr>
  	<td><?= $_profile ?></td><td><?= $uprofile ?></td>
  </tr>
  <tr>
  	<td><?= $_validity ?></td><td><?= $uvalid ?></td>
  </tr>
  <tr>
  	<td><?= $_time_limit ?></td><td><?= $utlimit ?></td>
  </tr>
  <tr>
  	<td><?= $_data_limit ?></td><td><?= $udlimit ?></td>
  </tr>
  <tr>
  	<td><?= $_price ?></td><td><?= $uprice ?></td>
  </tr>
  <tr>
  	<td><?= $_selling_price ?></td><td><?= $suprice ?></td>
  </tr>
  <tr>
  	<td><?= $_lock_user ?></td><td><?= $ulock ?></td>
  </tr>
  <tr>
    <td colspan="2">
		<p style="padding:0px 5px;">
      <?= $_format_time_limit ?>
    </p>
    <p style="padding:0px 5px;">
      <?= $_details_add_user ?>
    </p>
    </td>
  </tr>
</table>
</div>
</div>
</div>
<script>
function loader(){
  var el = document.getElementById('loader');
  if (el) el.style.display = 'inline';
}
function defUserl(){
  var user = document.getElementById('user');
  if (!user) return;
  var mode = user.value;
  var num = document.getElementById('num');
  var lower = document.getElementById('lower');
  var upper = document.getElementById('upper');
  var upplow = document.getElementById('upplow');
  var lower1 = document.getElementById('lower1');
  var upper1 = document.getElementById('upper1');
  var upplow1 = document.getElementById('upplow1');
  var mix = document.getElementById('mix');
  var mix1 = document.getElementById('mix1');
  var mix2 = document.getElementById('mix2');
  var userlFirst = document.querySelector('select[name="userl"] option:first-child');
  var charFirst = document.querySelector('select[name="char"] option:first-child');
  if (!userlFirst || !charFirst) return;
  if (mode === 'up') {
    userlFirst.textContent = '4';
    charFirst.textContent = 'Random abcd';
    if (lower) lower.style.display = 'block';
    if (upper) upper.style.display = 'block';
    if (upplow) upplow.style.display = 'block';
    if (lower1) lower1.style.display = 'none';
    if (upper1) upper1.style.display = 'none';
    if (upplow1) upplow1.style.display = 'none';
    if (num) num.style.display = 'none';
    if (mix) mix.style.display = 'block';
    if (mix1) mix1.style.display = 'block';
    if (mix2) mix2.style.display = 'block';
  } else {
    userlFirst.textContent = '8';
    charFirst.textContent = 'Random abcd2345';
    if (lower) lower.style.display = 'none';
    if (upper) upper.style.display = 'none';
    if (upplow) upplow.style.display = 'none';
    if (lower1) lower1.style.display = 'block';
    if (upper1) upper1.style.display = 'block';
    if (upplow1) upplow1.style.display = 'block';
    if (num) num.style.display = 'block';
    if (mix) mix.style.display = 'block';
    if (mix1) mix1.style.display = 'block';
    if (mix2) mix2.style.display = 'block';
  }
}
// get valid $ price
function GetVP(){
  var prof = document.getElementById('uprof').value;
  $("#GetValidPrice").load("<?= $appPrefix ?>process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata");
} 
defUserl();
</script>
</div>
<?php if ($isStandaloneGenerator): ?>
	</div>
</div>
<script src="<?= $appPrefix ?>js/jquery.min.js"></script>
<script src="<?= $appPrefix ?>js/mikhmon-ui.<?= htmlspecialchars($theme) ?>.min.js"></script>
</body>
</html>
<?php endif; ?>
