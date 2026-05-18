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

ob_start("ob_gzhandler");

// check url
$url = $_SERVER['REQUEST_URI'];

// load session MikroTik
$session = $_GET['session'];
$id = $_GET['id'];
$c = $_GET['c'];
$router = $_GET['router'];
$logo = $_GET['logo'];

$hasAdminSession = !empty($_SESSION['mikhmon']);
$hasManagerSession = !empty($_SESSION['manager_username']) && !empty($_SESSION['manager_session']);
$hasSellerSession = !empty($_SESSION['seller_username']) && !empty($_SESSION['seller_session']);
if ($id === 'editor' && $hasSellerSession && !$hasAdminSession) {
  ob_end_clean();
  header("Location: ./sellers.php?action=tickets");
  exit;
}
if ($id === 'editor' && !$hasAdminSession) {
  if ($hasManagerSession) {
    $session = $_SESSION['manager_session'];
    $_GET['session'] = $session;
  }
}
$hasEditorPortalAccess = ($id === 'editor') && ($hasAdminSession || $hasManagerSession);
$isNewRouterDraft = ($id === 'settings')
  && (
    (!empty($router) && preg_match('/^new-\d+$/', (string)$router))
    || (!empty($session) && preg_match('/^new-\d+$/', (string)$session))
  );

$ids = array(
  "editor",
  "uplogo",
  "settings",
  "fraud",
);

// lang
include('./lang/isocodelang.php');
include('./include/lang.php');
include('./lang/'.$langid.'.php');

// quick bt
include('./include/quickbt.php');

// theme
include('./include/theme.php');
include('./settings/settheme.php');
include('./settings/setlang.php');
if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
}

$isLoginRoute = ($id == "login" || substr($url, -1) == "p");

if ($isLoginRoute) {
  include('./include/config.php');
  include('./include/sellers_config.php');
  include('./include/managers_config.php');
  include_once('./lib/routeros_api.class.php');
  include_once('./include/auth.php');
  include_once('./include/csrf.php');

  $useradm = '';
  $passadm = '';
  if (!empty($data['mikhmon'][1])) {
    $useradm = explode('<|<', $data['mikhmon'][1])[1] ?? '';
  }
  if (!empty($data['mikhmon'][2])) {
    $passadm = explode('>|>', $data['mikhmon'][2])[1] ?? '';
  }

  $error         = '';
  $error_manager = '';
  $error_seller  = '';

  if (isset($_POST['login'])) {
    $user = $_POST['user'];
    $pass = $_POST['pass'];
    if ($user == $useradm && $pass == decrypt($passadm)) {
      session_regenerate_id(true);
      $_SESSION["mikhmon"] = $user;
      ob_end_clean();
      header("Location: ./admin.php?id=sessions");
      exit;
    } else {
      $error = '<i class="fa fa-ban"></i> Invalid username or password.';
    }
  }

  if (isset($_POST['manager_login'])) {
    $mu = trim($_POST['manager_user']);
    $mp = $_POST['manager_pass'];
    if (!empty($managers_data) && isset($managers_data[$mu]) && $mp === decrypt($managers_data[$mu]['password'])) {
      session_regenerate_id(true);
      $_SESSION['manager_username'] = $mu;
      $_SESSION['manager_name']     = $managers_data[$mu]['name'];
      $_SESSION['manager_session']  = $managers_data[$mu]['session'];
      ob_end_clean();
      header("Location: ./manager.php");
      exit;
    } else {
      $error_manager = '<i class="fa fa-ban"></i> ' . (isset($_please_login) ? $_please_login : 'Invalid credentials') . '.';
    }
  }

  if (isset($_POST['seller_login'])) {
    $su = trim($_POST['seller_user']);
    $sp = $_POST['seller_pass'];
    if (!empty($sellers_data) && isset($sellers_data[$su]) && $sp === decrypt($sellers_data[$su]['password'])) {
      session_regenerate_id(true);
      $_SESSION['seller_username'] = $su;
      $_SESSION['seller_name']     = $sellers_data[$su]['name'];
      $_SESSION['seller_session']  = $sellers_data[$su]['session'];
      ob_end_clean();
      header("Location: ./sellers.php?idbl=" . strtolower(date("M")) . date("Y"));
      exit;
    } else {
      $error_seller = '<i class="fa fa-ban"></i> ' . (isset($_please_login) ? $_please_login : 'Invalid credentials') . '.';
    }
  }

  include_once('./include/login.php');
  exit;
}

include('./include/config.php');
include('./include/sellers_config.php');
include('./include/managers_config.php');
include_once('./include/auth.php');
include_once('./include/csrf.php');
include_once('./include/mikhmon_compat.php');

// Extraire les credentials admin pour la page sessions (non disponibles hors bloc login)
$useradm = '';
$passadm = '';
if (!empty($data['mikhmon'][1])) {
  $useradm = explode('<|<', $data['mikhmon'][1])[1] ?? '';
}
if (!empty($data['mikhmon'][2])) {
  $passadm = explode('>|>', $data['mikhmon'][2])[1] ?? '';
}

$adminRouteNeedsRouterSession =
  (!$isNewRouterDraft && (($id == "settings" && !empty($session)) || in_array($id, array("connect", "uplogo", "reboot", "shutdown", "remove-logo", "editor", "fraud"), true)));

if ($adminRouteNeedsRouterSession) {
  include('./include/readcfg.php');
  if (empty($mikhmon_router_session_valid)) {
    ob_end_clean();
    $missingSession = rawurlencode((string)$session);
    header("Location: ./admin.php?id=sessions&missing-session=" . $missingSession);
    exit;
  }
}

include_once('./include/headhtml.php');

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
?>

<?php
if (!$hasAdminSession && !$hasEditorPortalAccess) {
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif (substr($url, -1) == "/" || substr($url, -4) == ".php") {
  echo "<script>window.location='./admin.php?id=sessions'</script>";

} elseif ($id == "sessions") {
  $_SESSION["connect"] = "";
  include_once('./include/menu.php');
  include_once('./settings/sessions.php');
  /*echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';*/
} elseif ($id == "settings" && !empty($session) || $id == "settings" && !empty($router)) {
  include_once('./include/menu.php');
  include_once('./settings/settings.php');
  echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';
} elseif ($id == "connect"  && !empty($session)) {
  ini_set("max_execution_time",5);  
  include_once('./include/menu.php');
  $API = new RouterosAPI();
  $API->debug = false;
  if ($API->connect($iphost, $userhost, decrypt($passwdhost))){
    $_SESSION["connect"] = "<b class='text-green'>Connected</b>";
    echo "<script>window.location='./?session=" . $session . "'</script>";
  } else {
    $_SESSION["connect"] = "<b class='text-red'>Not Connected</b>";
    $nl = '\n';
    if (mikhmon_currency_uses_integer_amounts($currency, $cekindo)) {
      echo "<script>alert('Mikhmon not connected!".$nl."Silakan periksa kembali IP, User, Password dan port API harus enable.".$nl."Jika menggunakan koneksi VPN, pastikan VPN tersebut terkoneksi.')</script>";
    }else{
      echo "<script>alert('Mikhmon not connected!".$nl."Please check the IP, User, Password and port API must be enabled.')</script>";
    }
    if($c == "settings"){
      echo "<script>window.location='./admin.php?id=settings&session=" . $session . "'</script>";
    }else{
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
  }
} elseif ($id == "uplogo"  && !empty($session)) {
  include_once('./include/menu.php');
  include_once('./settings/uplogo.php');
} elseif ($id == "reboot"  && !empty($session)) {
  include_once('./process/reboot.php');
} elseif ($id == "shutdown"  && !empty($session)) {
  include_once('./process/shutdown.php');
} elseif ($id == "remove-session" && $session != "") {
  include_once('./include/menu.php');
  $fc = file("./include/config.php" );
  $f = fopen("./include/config.php", "w");
  $q = "'";
  $rem = '$data['.$q.$session.$q.']';
  foreach ($fc as $line) {
    if (!strstr($line, $rem))
      fputs($f, $line);
  }
  fclose($f);
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif ($id == "about") {
  include_once('./include/menu.php');
  include_once('./include/about.php');
} elseif ($id == "logout") {
  include_once('./include/menu.php');
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";
  session_destroy();
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif ($id == "remove-logo" && $logo != ""  && !empty($session)) {
  include_once('./include/menu.php');
  $logopath = "./img/";
  $remlogo = $logopath . $logo;
  unlink("$remlogo");
  echo "<script>window.location='./admin.php?id=uplogo&session=" . $session . "'</script>";
} elseif ($id == "editor"  && !empty($session)) {
  if ($hasAdminSession) {
    include_once('./include/menu.php');
  }
  include_once('./settings/vouchereditor.php');
} elseif ($id == "sellers") {
  include_once('./include/menu.php');
  include_once('./settings/manage_sellers.php');
} elseif ($id == "safelink") {
  include_once('./include/menu.php');
  include_once('./settings/safelink_integration.php');
} elseif ($id == "fraud" && !empty($session)) {
  include_once('./include/menu.php');
  include_once('./settings/fraud.php');
} elseif (empty($id)) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif(in_array($id, $ids) && empty($session)){
	echo "<script>window.location='./admin.php?id=sessions'</script>";
}
?>
<script src="js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")); ?>"></script>
<?php include('./include/info.php'); ?>
</body>
</html>
