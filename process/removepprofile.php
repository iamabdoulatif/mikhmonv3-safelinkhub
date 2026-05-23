<?php
session_start();
error_reporting(0);
include_once(__DIR__ . '/../include/csrf.php');
include_once(__DIR__ . '/../include/pppoe_helpers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  mikhmon_ppp_redirect("./?ppp=profiles&session=" . urlencode($session));
}

csrf_guard();

$API->comm("/ppp/profile/remove", array(
  ".id" => mikhmon_ppp_safe_id($removepprofile),
));

mikhmon_ppp_redirect("./?ppp=profiles&session=" . urlencode($session));
