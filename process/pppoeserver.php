<?php
session_start();
error_reporting(0);
include_once(__DIR__ . '/../include/csrf.php');
include_once(__DIR__ . '/../include/pppoe_helpers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  mikhmon_ppp_redirect("./?ppp=servers&session=" . urlencode($session));
}

csrf_guard();

if ($enablepppoeserver != "") {
  $API->comm("/interface/pppoe-server/server/set", array(
    ".id" => mikhmon_ppp_safe_id($enablepppoeserver),
    "disabled" => "no",
  ));
} elseif ($disablepppoeserver != "") {
  $API->comm("/interface/pppoe-server/server/set", array(
    ".id" => mikhmon_ppp_safe_id($disablepppoeserver),
    "disabled" => "yes",
  ));
} elseif ($removepppoeserver != "") {
  $API->comm("/interface/pppoe-server/server/remove", array(
    ".id" => mikhmon_ppp_safe_id($removepppoeserver),
  ));
}

mikhmon_ppp_redirect("./?ppp=servers&session=" . urlencode($session));
