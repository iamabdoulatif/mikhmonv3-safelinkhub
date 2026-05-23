<?php
$root = dirname(__DIR__);

$requiredFiles = array(
  'ppp/pppactive.php',
  'ppp/pppprofile.php',
  'ppp/addpppprofile.php',
  'ppp/profilebyname.php',
  'ppp/pppsecrets.php',
  'ppp/addsecret.php',
  'ppp/secretbyname.php',
  'ppp/pppoeservers.php',
  'ppp/addpppoeserver.php',
  'ppp/pppoeserverbyname.php',
  'process/psecret.php',
  'process/removepprofile.php',
  'process/pppoeserver.php',
);

foreach ($requiredFiles as $file) {
  if (!is_file($root . '/' . $file)) {
    fwrite(STDERR, 'missing PPPoE file: ' . $file . PHP_EOL);
    exit(1);
  }
}

$index = file_get_contents($root . '/index.php');
$menu = file_get_contents($root . '/include/menu.php');
$home = file_get_contents($root . '/dashboard/home.php');
$aload = file_get_contents($root . '/dashboard/aload.php');

$routeNeedles = array(
  'ppp/pppsecrets.php',
  'ppp/addsecret.php',
  'ppp/secretbyname.php',
  'ppp/pppprofile.php',
  'ppp/addpppprofile.php',
  'ppp/profilebyname.php',
  'ppp/pppactive.php',
  'ppp/pppoeservers.php',
  'ppp/addpppoeserver.php',
  'ppp/pppoeserverbyname.php',
  'process/psecret.php',
  'process/removepprofile.php',
  'process/pppoeserver.php',
);

foreach ($routeNeedles as $needle) {
  if (strpos($index, $needle) === false) {
    fwrite(STDERR, 'index missing PPPoE route include: ' . $needle . PHP_EOL);
    exit(1);
  }
}

$menuNeedles = array(
  'PPP Active',
  'PPP Profiles',
  'PPP Secrets',
  'PPPoE Servers',
  '?ppp=active',
  '?ppp=profiles',
  '?ppp=secrets',
  '?ppp=servers',
);

foreach ($menuNeedles as $needle) {
  if (strpos($menu, $needle) === false) {
    fwrite(STDERR, 'menu missing PPPoE entry: ' . $needle . PHP_EOL);
    exit(1);
  }
}

$dashboardNeedles = array(
  '/ppp/active/print',
  '/ppp/profile/print',
  '/ppp/secret/print',
  '/interface/pppoe-server/server/print',
  '?ppp=active',
  '?ppp=profiles',
  '?ppp=secrets',
  '?ppp=servers',
);

foreach ($dashboardNeedles as $needle) {
  if (strpos($home, $needle) === false && strpos($aload, $needle) === false) {
    fwrite(STDERR, 'dashboard missing PPPoE integration: ' . $needle . PHP_EOL);
    exit(1);
  }
}

if (strpos($aload, 'id="r_2" class="row') === false && strpos($aload, 'id="r_2"class="row') === false) {
  fwrite(STDERR, 'dashboard ajax hotspot refresh must keep PPPoE inside the #r_2 replacement row' . PHP_EOL);
  exit(1);
}

if (strpos($aload, 'id="r_2" class="card') !== false) {
  fwrite(STDERR, 'dashboard ajax hotspot refresh must not bind #r_2 to the hotspot-only card' . PHP_EOL);
  exit(1);
}

if (!preg_match('/<\/div>\s*<\/div>\s*<\/div>\s*<div class="card">\s*<div class="card-header"><h3><i class="fa fa-exchange"/', $aload)) {
  fwrite(STDERR, 'dashboard ajax hotspot card must close before the PPPoE card' . PHP_EOL);
  exit(1);
}

$r2Start = strpos($aload, '<div id="r_2" class="row');
$pppoeHeader = strpos($aload, '<div class="card-header"><h3><i class="fa fa-exchange"', $r2Start);
$beforePppoeHeader = substr($aload, $r2Start, $pppoeHeader - $r2Start);
$pppoeCardStart = strrpos($beforePppoeHeader, '<div class="card">');
$hotspotFragment = substr($beforePppoeHeader, 0, $pppoeCardStart);
preg_match_all('/<div\b/', $hotspotFragment, $hotspotOpenDivs);
preg_match_all('/<\/div>/', $hotspotFragment, $hotspotCloseDivs);
if (count($hotspotOpenDivs[0]) !== count($hotspotCloseDivs[0]) + 1) {
  fwrite(STDERR, 'dashboard ajax hotspot card div structure is not balanced before PPPoE card' . PHP_EOL);
  exit(1);
}

$fileNeedles = array(
  'ppp/pppactive.php' => array('/ppp/active/print', 'remove-pactive'),
  'ppp/pppprofile.php' => array('/ppp/profile/print', 'remove-pprofile', 'edit-profile'),
  'ppp/addpppprofile.php' => array('/ppp/profile/add', 'csrf_guard'),
  'ppp/profilebyname.php' => array('/ppp/profile/set', 'csrf_guard'),
  'ppp/pppsecrets.php' => array('/ppp/secret/print', 'enable-pppsecret', 'disable-pppsecret', 'remove-pppsecret'),
  'ppp/addsecret.php' => array('/ppp/secret/add', 'service', 'pppoe', 'csrf_guard'),
  'ppp/secretbyname.php' => array('/ppp/secret/set', 'csrf_guard'),
  'ppp/pppoeservers.php' => array('/interface/pppoe-server/server/print', 'enable-pppoe-server', 'disable-pppoe-server', 'remove-pppoe-server'),
  'ppp/addpppoeserver.php' => array('/interface/pppoe-server/server/add', 'default-profile', 'csrf_guard'),
  'ppp/pppoeserverbyname.php' => array('/interface/pppoe-server/server/set', 'csrf_guard'),
  'process/psecret.php' => array('/ppp/secret/set', '/ppp/secret/remove', 'csrf_guard'),
  'process/removepprofile.php' => array('/ppp/profile/remove', 'csrf_guard'),
  'process/pppoeserver.php' => array('/interface/pppoe-server/server/set', '/interface/pppoe-server/server/remove', 'csrf_guard'),
);

foreach ($fileNeedles as $file => $needles) {
  $content = file_get_contents($root . '/' . $file);
  foreach ($needles as $needle) {
    if (strpos($content, $needle) === false) {
      fwrite(STDERR, $file . ' missing PPPoE behavior: ' . $needle . PHP_EOL);
      exit(1);
    }
  }
}

$languageVars = array(
  '$_pppoe',
  '$_pppoe_servers',
  '$_ppp_service',
  '$_ppp_service_name',
  '$_ppp_default_profile',
  '$_ppp_local_address',
  '$_ppp_remote_address',
  '$_ppp_rate_limit',
  '$_ppp_dns_server',
  '$_ppp_caller_id',
  '$_ppp_only_one',
  '$_ppp_use_encryption',
  '$_ppp_disabled',
  '$_ppp_enabled',
  '$_ppp_disconnect',
  '$_ppp_no_active',
  '$_ppp_no_profile',
  '$_ppp_no_secret',
  '$_pppoe_no_server',
);

foreach (glob($root . '/lang/*.php') as $langFile) {
  if (basename($langFile) === 'isocodelang.php') {
    continue;
  }
  $langContent = file_get_contents($langFile);
  foreach ($languageVars as $varName) {
    if (strpos($langContent, $varName) === false) {
      fwrite(STDERR, basename($langFile) . ' missing PPPoE language variable: ' . $varName . PHP_EOL);
      exit(1);
    }
  }
}

$responsiveFiles = array(
  'ppp/pppactive.php',
  'ppp/pppprofile.php',
  'ppp/pppsecrets.php',
  'ppp/pppoeservers.php',
  'ppp/addsecret.php',
  'ppp/secretbyname.php',
  'ppp/addpppprofile.php',
  'ppp/profilebyname.php',
  'ppp/addpppoeserver.php',
  'ppp/pppoeserverbyname.php',
);

foreach ($responsiveFiles as $file) {
  $content = file_get_contents($root . '/' . $file);
  if (strpos($content, 'mikhmon_ppp_responsive_css') === false) {
    fwrite(STDERR, $file . ' must include PPPoE responsive CSS' . PHP_EOL);
    exit(1);
  }
}

foreach (array('ppp/pppactive.php', 'ppp/pppprofile.php', 'ppp/pppsecrets.php', 'ppp/pppoeservers.php') as $file) {
  $content = file_get_contents($root . '/' . $file);
  if (strpos($content, 'ppp-responsive-table') === false || strpos($content, 'data-label') === false) {
    fwrite(STDERR, $file . ' must expose responsive table labels' . PHP_EOL);
    exit(1);
  }
}

foreach (array('ppp/addsecret.php', 'ppp/secretbyname.php', 'ppp/addpppprofile.php', 'ppp/profilebyname.php', 'ppp/addpppoeserver.php', 'ppp/pppoeserverbyname.php') as $file) {
  $content = file_get_contents($root . '/' . $file);
  if (strpos($content, 'ppp-form-table') === false || strpos($content, 'ppp-form-page') === false) {
    fwrite(STDERR, $file . ' must expose responsive form layout' . PHP_EOL);
    exit(1);
  }
}

echo "mikhmon_pppoe_module_test passed\n";
