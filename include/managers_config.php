<?php
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -20) == "managers_config.php") {
  header("Location:./");
  exit;
}
// Comptes gérants - gérés par l'administrateur
// Format: $managers_data['username'] = array('password' => encrypt('password'), 'name' => 'Nom Affiché', 'session' => 'NomSession');
$managers_data = array();
$managers_data['latif'] = array('password'=>'v2:pjA4BgFC6G6SRVhKSIqAu4CyNWcEPAFUqGYQRPLeFag=','name'=>'Latif','session'=>'Safelink');
