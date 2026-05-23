<?php
session_start();
error_reporting(0);
include_once(__DIR__ . '/../include/csrf.php');
include_once(__DIR__ . '/../include/pppoe_helpers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  mikhmon_ppp_redirect("./?ppp=secrets&session=" . urlencode($session));
}

csrf_guard();

if ($enablesecr != "") {
  $API->comm("/ppp/secret/set", array(
    ".id" => mikhmon_ppp_safe_id($enablesecr),
    "disabled" => "no",
  ));
} elseif ($disablesecr != "") {
  $API->comm("/ppp/secret/set", array(
    ".id" => mikhmon_ppp_safe_id($disablesecr),
    "disabled" => "yes",
  ));
} elseif ($removesecr != "") {
  $API->comm("/ppp/secret/remove", array(
    ".id" => mikhmon_ppp_safe_id($removesecr),
  ));
}

mikhmon_ppp_redirect("./?ppp=secrets&session=" . urlencode($session));
