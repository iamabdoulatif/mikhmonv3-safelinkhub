<?php
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -19) == "sellers_config.php") {
  header("Location:./");
  exit;
}
// Comptes vendeurs - gérés par l'administrateur
// Format: $sellers_data['username'] = array('password' => encrypt('motdepasse'), 'name' => 'Nom Affiché', 'session' => 'NomSession');
$sellers_data = array();
