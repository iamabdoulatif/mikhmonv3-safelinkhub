<?php
$root = dirname(__DIR__);
$settings = file_get_contents($root . '/settings/settings.php');

$checks = array(
  'settings must compute the MikroTik password display value once' => strpos($settings, '$mikrotikPasswordValue = !empty($passwdhost) ? decrypt($passwdhost) : \'\';') !== false,
  'MikroTik password field must remain required' => strpos($settings, 'name="passmik"') !== false && strpos($settings, 'required="1"') !== false,
  'blank saved password fields must tell the admin that saving preserves the value' => strpos($settings, 'Mot de passe enregistré - laissez vide pour conserver') !== false,
  'blank submits must preserve existing encrypted passwords' => strpos($settings, '$postedPassmik === \'\' && !empty($passwdhost)') !== false,
  'portable legacy migration must run when a v2 password can be decrypted' => strpos($settings, 'strpos((string) $passwdhost, \'v2:\') === 0') !== false && strpos($settings, 'mikhmon_legacy_encrypt($currentPassmik)') !== false,
  'password value must be escaped before rendering' => strpos($settings, 'htmlspecialchars($mikrotikPasswordValue, ENT_QUOTES, \'UTF-8\')') !== false,
);

foreach ($checks as $label => $ok) {
  if (!$ok) {
    fwrite(STDERR, $label . PHP_EOL);
    exit(1);
  }
}

echo "settings_mikrotik_password_test passed\n";
