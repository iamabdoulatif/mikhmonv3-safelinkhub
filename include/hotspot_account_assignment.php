<?php
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -30) == "hotspot_account_assignment.php") {
  header("Location:./");
  exit;
}

if (!function_exists('mikhmon_hotspot_account_key')) {
  function mikhmon_hotspot_account_key($value)
  {
    return preg_replace('/[^a-zA-Z0-9_]/', '', trim((string) $value));
  }

  function mikhmon_hotspot_account_label($value)
  {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', strip_tags($value));
    if ($value === '') {
      return '';
    }
    return $value;
  }

  function mikhmon_hotspot_assignment_base_comment($comment)
  {
    $comment = mikhmon_hotspot_account_label($comment);
    $comment = preg_replace('/(?:\s*\|\s*)?MIKHMON_ACCOUNT\b.*$/', '', $comment);
    return mikhmon_hotspot_account_label($comment);
  }

  function mikhmon_hotspot_default_account_candidates($hotspotUsers, $sellersData = array(), $managersData = array())
  {
    $candidates = array();
    $seen = array();

    foreach ((array) $hotspotUsers as $user) {
      if (!is_array($user)) {
        continue;
      }

      $profile = isset($user['profile']) ? trim((string) $user['profile']) : '';
      if (strtolower($profile) !== 'default') {
        continue;
      }

      $username = isset($user['name']) ? trim((string) $user['name']) : '';
      if ($username === '') {
        continue;
      }

      $accountKey = mikhmon_hotspot_account_key($username);
      if ($accountKey === '' || isset($seen[$accountKey])) {
        continue;
      }
      if (isset($sellersData[$accountKey]) || isset($managersData[$accountKey])) {
        continue;
      }

      $comment = isset($user['comment']) ? mikhmon_hotspot_assignment_base_comment($user['comment']) : '';
      $password = isset($user['password']) ? trim((string) $user['password']) : '';
      if ($password === '') {
        $password = $username;
      }

      $candidates[] = array(
        'id' => isset($user['.id']) ? $user['.id'] : '',
        'username' => $username,
        'account_key' => $accountKey,
        'password' => $password,
        'display_name' => $comment !== '' ? $comment : ucfirst(strtolower($accountKey)),
        'profile' => $profile,
        'comment' => $comment,
      );
      $seen[$accountKey] = true;
    }

    return $candidates;
  }

  function mikhmon_hotspot_find_default_account_candidate($hotspotUsers, $username, $sellersData = array(), $managersData = array())
  {
    $username = trim((string) $username);
    if ($username === '') {
      return null;
    }

    $candidates = mikhmon_hotspot_default_account_candidates($hotspotUsers, $sellersData, $managersData);
    foreach ($candidates as $candidate) {
      if ($candidate['username'] === $username) {
        return $candidate;
      }
    }

    return null;
  }

  function mikhmon_hotspot_ip_binding_comment_name($comment)
  {
    $comment = mikhmon_hotspot_assignment_base_comment($comment);
    if ($comment === '') {
      return '';
    }

    $parts = explode('|', $comment);
    for ($i = count($parts) - 1; $i >= 0; $i--) {
      $part = mikhmon_hotspot_account_label($parts[$i]);
      if ($part === '' || $part === 'mikhmon-ipbinding' || stripos($part, 'profile=') === 0 || stripos($part, 'validity=') === 0) {
        continue;
      }
      return $part;
    }

    return '';
  }

  function mikhmon_hotspot_account_identity_candidates($hotspotUsers, $ipBindings, $sellersData = array(), $managersData = array())
  {
    $identities = array();
    $seen = array();

    foreach (mikhmon_hotspot_default_account_candidates($hotspotUsers, $sellersData, $managersData) as $candidate) {
      $key = isset($candidate['account_key']) ? $candidate['account_key'] : '';
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $candidate['source'] = 'hotspot_default';
      $candidate['select_value'] = 'hotspot:' . $candidate['username'];
      $identities[] = $candidate;
      $seen[$key] = true;
      $displayKey = isset($candidate['display_name']) ? mikhmon_hotspot_account_key($candidate['display_name']) : '';
      if ($displayKey !== '') {
        $seen[$displayKey] = true;
      }
    }

    foreach ((array) $ipBindings as $binding) {
      if (!is_array($binding)) {
        continue;
      }

      $rawComment = isset($binding['comment']) ? mikhmon_hotspot_account_label($binding['comment']) : '';
      if (mikhmon_hotspot_assignment_from_comment($rawComment) !== null) {
        continue;
      }

      $name = mikhmon_hotspot_ip_binding_comment_name($rawComment);
      $key = mikhmon_hotspot_account_key($name);
      if ($name === '' || $key === '' || isset($seen[$key]) || isset($sellersData[$key]) || isset($managersData[$key])) {
        continue;
      }

      $identities[] = array(
        'id' => isset($binding['.id']) ? $binding['.id'] : '',
        'username' => $name,
        'account_key' => $key,
        'password' => $key,
        'display_name' => $name,
        'profile' => '',
        'comment' => $rawComment,
        'source' => 'ip_binding',
        'select_value' => 'ipbinding:' . $key,
      );
      $seen[$key] = true;
    }

    return $identities;
  }

  function mikhmon_hotspot_find_account_identity_candidate($hotspotUsers, $ipBindings, $selectValue, $sellersData = array(), $managersData = array())
  {
    $selectValue = trim((string) $selectValue);
    if ($selectValue === '') {
      return null;
    }

    $identities = mikhmon_hotspot_account_identity_candidates($hotspotUsers, $ipBindings, $sellersData, $managersData);
    foreach ($identities as $identity) {
      if (isset($identity['select_value']) && $identity['select_value'] === $selectValue) {
        return $identity;
      }
      if (isset($identity['source']) && $identity['source'] === 'hotspot_default' && isset($identity['username']) && $identity['username'] === $selectValue) {
        return $identity;
      }
    }

    return null;
  }

  function mikhmon_hotspot_assignment_comment($comment, $session, $role, $accountKey, $authHash = '')
  {
    $comment = mikhmon_hotspot_account_label($comment);
    $session = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string) $session));
    $accountKey = mikhmon_hotspot_account_key($accountKey);
    $role = strtolower(trim((string) $role));
    $roleLabel = $role === 'manager' ? 'gerant' : 'vendeur';

    $baseComment = mikhmon_hotspot_assignment_base_comment($comment);
    $trace = 'MIKHMON_ACCOUNT role=' . $roleLabel . ' session=' . $session . ' account=' . $accountKey;
    if ((int) password_get_info((string) $authHash)['algo'] !== 0) {
      $trace .= ' auth=' . $authHash;
    }

    if ($baseComment === '') {
      return $trace;
    }

    return $baseComment . ' | ' . $trace;
  }

  function mikhmon_hotspot_assignment_from_comment($comment, $session = '')
  {
    $comment = mikhmon_hotspot_account_label($comment);
    if ($comment === '' || strpos($comment, 'MIKHMON_ACCOUNT') === false) {
      return null;
    }

    if (!preg_match('/MIKHMON_ACCOUNT\s+role=([^\s|]+)\s+session=([^\s|]+)\s+account=([^\s|]+)/', $comment, $matches)) {
      return null;
    }

    $role = strtolower(trim($matches[1]));
    if ($role === 'gerant') {
      $role = 'manager';
    } elseif ($role === 'vendeur') {
      $role = 'seller';
    }
    if (!in_array($role, array('seller', 'manager'), true)) {
      return null;
    }

    $foundSession = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($matches[2]));
    $wantedSession = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string) $session));
    if ($wantedSession !== '' && $foundSession !== $wantedSession) {
      return null;
    }

    $account = mikhmon_hotspot_account_key($matches[3]);
    if ($account === '') {
      return null;
    }

    $authHash = '';
    if (preg_match('/\bauth=([^\s|]+)/', $comment, $authMatches) && (int) password_get_info($authMatches[1])['algo'] !== 0) {
      $authHash = $authMatches[1];
    }

    return array(
      'role' => $role,
      'session' => $foundSession,
      'account_key' => $account,
      'base_comment' => mikhmon_hotspot_assignment_base_comment($comment),
      'auth_hash' => $authHash,
    );
  }

  function mikhmon_hotspot_normalized_assignment_role($role)
  {
    $role = strtolower(trim((string) $role));
    if ($role === 'gerant') {
      return 'manager';
    }
    if ($role === 'vendeur') {
      return 'seller';
    }
    return $role;
  }

  function mikhmon_hotspot_clear_assignment_comment($comment, $session, $role, $accountKey)
  {
    $comment = mikhmon_hotspot_account_label($comment);
    $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
    if ($assignment === null) {
      return $comment;
    }

    $role = mikhmon_hotspot_normalized_assignment_role($role);
    $accountKey = mikhmon_hotspot_account_key($accountKey);
    if ($assignment['role'] !== $role || $assignment['account_key'] !== $accountKey) {
      return $comment;
    }

    return mikhmon_hotspot_assignment_base_comment($comment);
  }

  function mikhmon_hotspot_clear_account_footprints($api, $hotspotUsers, $ipBindings, $session, $role, $accountKey, $routerUsers = array(), $hardDelete = false)
  {
    if (!is_object($api)) {
      return false;
    }

    $ok = true;
    $role = mikhmon_hotspot_normalized_assignment_role($role);
    $accountKey = mikhmon_hotspot_account_key($accountKey);

    foreach ((array) $hotspotUsers as $user) {
      if (!is_array($user) || !isset($user['.id'])) {
        continue;
      }
      $comment = isset($user['comment']) ? $user['comment'] : '';
      $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
      if ($assignment === null || $assignment['role'] !== $role || $assignment['account_key'] !== $accountKey) {
        continue;
      }
      if ($hardDelete) {
        $response = $api->comm("/ip/hotspot/user/remove", array(".id" => $user['.id']));
      } else {
        $newComment = mikhmon_hotspot_clear_assignment_comment($comment, $session, $role, $accountKey);
        $response = $api->comm("/ip/hotspot/user/set", array(".id" => $user['.id'], "comment" => $newComment));
      }
      if (!mikhmon_hotspot_routeros_response_ok($response)) {
        $ok = false;
      }
    }

    foreach ((array) $ipBindings as $binding) {
      if (!is_array($binding) || !isset($binding['.id'])) {
        continue;
      }
      $comment = isset($binding['comment']) ? $binding['comment'] : '';
      $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
      if ($assignment === null || $assignment['role'] !== $role || $assignment['account_key'] !== $accountKey) {
        continue;
      }
      if ($hardDelete) {
        $response = $api->comm("/ip/hotspot/ip-binding/remove", array(".id" => $binding['.id']));
      } else {
        $newComment = mikhmon_hotspot_clear_assignment_comment($comment, $session, $role, $accountKey);
        $response = $api->comm("/ip/hotspot/ip-binding/set", array(".id" => $binding['.id'], "comment" => $newComment));
      }
      if (!mikhmon_hotspot_routeros_response_ok($response)) {
        $ok = false;
      }
    }

    foreach ((array) $routerUsers as $routerUser) {
      if (!is_array($routerUser) || !isset($routerUser['.id'])) {
        continue;
      }
      $comment = isset($routerUser['comment']) ? $routerUser['comment'] : '';
      $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
      if ($assignment === null || $assignment['role'] !== $role || $assignment['account_key'] !== $accountKey) {
        continue;
      }
      if ($hardDelete) {
        $response = $api->comm("/user/remove", array(".id" => $routerUser['.id']));
      } else {
        $response = $api->comm("/user/set", array(
          ".id" => $routerUser['.id'],
          "comment" => mikhmon_hotspot_assignment_base_comment($comment)
        ));
      }
      if (!mikhmon_hotspot_routeros_response_ok($response)) {
        $ok = false;
      }
    }

    return $ok;
  }

  function mikhmon_hotspot_restore_seller_from_comment($comment, $session, &$restored, &$seen, $profile = '')
  {
    $comment = mikhmon_hotspot_account_label($comment);
    if ($comment === '') {
      return;
    }
    $profile = trim((string) $profile);
    if ($profile !== '') {
      $normalizedComment = strtolower(preg_replace('/\s+/', ' ', $comment));
      $normalizedProfile = strtolower(preg_replace('/\s+/', ' ', $profile));
      if ($normalizedComment === $normalizedProfile || substr($normalizedComment, -strlen('-' . $normalizedProfile)) === '-' . $normalizedProfile) {
        return;
      }
    }

    $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
    if ($assignment !== null) {
      if ($assignment['role'] !== 'seller' || isset($seen[$assignment['account_key']])) {
        return;
      }
      $name = mikhmon_hotspot_account_label($assignment['base_comment']);
      if ($name === '') {
        $name = ucfirst(strtolower($assignment['account_key']));
      }
      $restored['sellers'][$assignment['account_key']] = array(
        'password' => '',
        'name' => $name,
        'session' => $session,
        'commission' => 10,
        'historical' => true,
      );
      $seen[$assignment['account_key']] = true;
      return;
    }

    $candidate = $comment;
    $separator = strrpos($comment, '-');
    if ($separator !== false) {
      $candidate = substr($comment, $separator + 1);
    }
    $candidate = mikhmon_hotspot_account_key($candidate);
    if ($candidate === '' || isset($seen[$candidate])) {
      return;
    }

    $restored['sellers'][$candidate] = array(
      'password' => '',
      'name' => ucfirst(strtolower($candidate)) . ' (historique)',
      'session' => $session,
      'commission' => 10,
      'historical' => true,
    );
    $seen[$candidate] = true;
  }

  function mikhmon_hotspot_restored_account_records($hotspotUsers, $ipBindings, $session, $sellersData = array(), $managersData = array(), $routerUsers = array(), $sales = array(), $stockUsers = array())
  {
    $restored = array('sellers' => array(), 'managers' => array());
    $seen = array();

    foreach ((array) $sellersData as $key => $record) {
      $cleanKey = mikhmon_hotspot_account_key($key);
      if ($cleanKey !== '') {
        $seen[$cleanKey] = true;
      }
    }
    foreach ((array) $managersData as $key => $record) {
      $cleanKey = mikhmon_hotspot_account_key($key);
      if ($cleanKey !== '') {
        $seen[$cleanKey] = true;
      }
    }

    foreach ((array) $hotspotUsers as $user) {
      if (!is_array($user)) {
        continue;
      }

      $assignment = mikhmon_hotspot_assignment_from_comment(isset($user['comment']) ? $user['comment'] : '', $session);
      if ($assignment === null || isset($seen[$assignment['account_key']])) {
        continue;
      }

      $password = isset($user['password']) ? trim((string) $user['password']) : '';
      if ($password === '') {
        $password = $assignment['account_key'];
      }
      $name = mikhmon_hotspot_account_label($assignment['base_comment']);
      if ($name === '') {
        $name = ucfirst(strtolower($assignment['account_key']));
      }

      $record = array(
        'password' => $password,
        'name' => $name,
        'session' => $session,
      );

      if ($assignment['role'] === 'seller') {
        $record['commission'] = 10;
        $restored['sellers'][$assignment['account_key']] = $record;
      } else {
        $restored['managers'][$assignment['account_key']] = $record;
      }
      $seen[$assignment['account_key']] = true;
    }

    foreach ((array) $ipBindings as $binding) {
      if (!is_array($binding)) {
        continue;
      }

      $comment = isset($binding['comment']) ? $binding['comment'] : '';
      $assignment = mikhmon_hotspot_assignment_from_comment($comment, $session);
      if ($assignment === null || isset($seen[$assignment['account_key']])) {
        continue;
      }

      $name = mikhmon_hotspot_ip_binding_comment_name($comment);
      if ($name === '') {
        $name = ucfirst(strtolower($assignment['account_key']));
      }

      $record = array(
        'password' => $assignment['account_key'],
        'name' => $name,
        'session' => $session,
      );

      if ($assignment['role'] === 'seller') {
        $record['commission'] = 10;
        $restored['sellers'][$assignment['account_key']] = $record;
      } else {
        $restored['managers'][$assignment['account_key']] = $record;
      }
      $seen[$assignment['account_key']] = true;
    }

    foreach ((array) $routerUsers as $routerUser) {
      if (!is_array($routerUser)) {
        continue;
      }
      $assignment = mikhmon_hotspot_assignment_from_comment(isset($routerUser['comment']) ? $routerUser['comment'] : '', $session);
      if ($assignment === null || $assignment['auth_hash'] === '' || isset($seen[$assignment['account_key']])) {
        continue;
      }
      $name = mikhmon_hotspot_account_label($assignment['base_comment']);
      if ($name === '') {
        $name = ucfirst(strtolower($assignment['account_key']));
      }
      $record = array(
        'password' => $assignment['auth_hash'],
        'name' => $name,
        'session' => $session,
      );
      if ($assignment['role'] === 'seller') {
        $record['commission'] = 10;
        $restored['sellers'][$assignment['account_key']] = $record;
      } else {
        $restored['managers'][$assignment['account_key']] = $record;
      }
      $seen[$assignment['account_key']] = true;
    }

    $saleRows = function_exists('mikhmon_unique_sale_scripts') ? mikhmon_unique_sale_scripts($sales) : (array) $sales;
    foreach ($saleRows as $saleRow) {
      if (!is_array($saleRow)) {
        continue;
      }
      $sale = (isset($saleRow['date']) && isset($saleRow['comment']))
        ? $saleRow
        : (function_exists('mikhmon_parse_sale_script') ? mikhmon_parse_sale_script($saleRow) : $saleRow);
      mikhmon_hotspot_restore_seller_from_comment(
        isset($sale['comment']) ? $sale['comment'] : '',
        $session,
        $restored,
        $seen,
        isset($sale['profile']) ? $sale['profile'] : ''
      );
    }

    foreach ((array) $stockUsers as $stockUser) {
      if (!is_array($stockUser)) {
        continue;
      }
      $uptime = isset($stockUser['uptime']) ? trim((string) $stockUser['uptime']) : '0s';
      if ($uptime !== '' && $uptime !== '0s') {
        continue;
      }
      $profile = isset($stockUser['profile']) ? trim((string) $stockUser['profile']) : '';
      if ($profile === '' || strtolower($profile) === 'default') {
        continue;
      }
      mikhmon_hotspot_restore_seller_from_comment(isset($stockUser['comment']) ? $stockUser['comment'] : '', $session, $restored, $seen, $profile);
    }

    return $restored;
  }

  function mikhmon_hotspot_routeros_response_ok($response)
  {
    if (!is_array($response)) {
      return false;
    }

    if (array_key_exists('!trap', $response) || array_key_exists('!fatal', $response)) {
      return false;
    }

    foreach ($response as $item) {
      if (is_array($item) && !mikhmon_hotspot_routeros_response_ok($item)) {
        return false;
      }
    }

    return true;
  }

  function mikhmon_hotspot_provision_account($api, $mode, $session, $role, $username, $password, $displayName, $macAddress = '', $address = '')
  {
    $mode = strtolower(trim((string) $mode));
    $role = mikhmon_hotspot_normalized_assignment_role($role);
    $username = mikhmon_hotspot_account_key($username);
    $password = trim((string) $password);
    $displayName = mikhmon_hotspot_account_label($displayName);
    $macAddress = strtoupper(trim((string) $macAddress));
    $address = trim((string) $address);

    if (!is_object($api) || !in_array($mode, array('hotspot_user', 'ip_binding', 'router_user'), true) || !in_array($role, array('seller', 'manager'), true)) {
      return array('ok' => false, 'error' => 'Mode ou rôle MikroTik invalide.');
    }
    if ($username === '' || $password === '' || $displayName === '' || trim((string) $session) === '') {
      return array('ok' => false, 'error' => 'Tous les champs du compte sont obligatoires.');
    }

    $comment = mikhmon_hotspot_assignment_comment($displayName, $session, $role, $username, password_hash($password, PASSWORD_DEFAULT));
    if ($mode === 'hotspot_user') {
      $response = $api->comm('/ip/hotspot/user/add', array(
        'server' => 'all',
        'name' => $username,
        'password' => $password,
        'profile' => 'default',
        'comment' => $comment,
      ));
    } elseif ($mode === 'ip_binding') {
      if (!preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/', $macAddress)) {
        return array('ok' => false, 'error' => 'Adresse MAC invalide. Format attendu : AA:BB:CC:DD:EE:FF.');
      }
      if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return array('ok' => false, 'error' => 'Adresse IPv4 invalide.');
      }

      $attributes = array(
        'mac-address' => $macAddress,
        'server' => 'all',
        'type' => 'bypassed',
        'comment' => $comment,
      );
      if ($address !== '') {
        $attributes['address'] = $address;
      }
      $response = $api->comm('/ip/hotspot/ip-binding/add', $attributes);
    } else {
      if ($address !== '') {
        $addressParts = explode('/', $address, 2);
        $prefix = isset($addressParts[1]) ? $addressParts[1] : '';
        if (filter_var($addressParts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ($prefix !== '' && (!ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32))) {
          return array('ok' => false, 'error' => 'Adresse autorisée invalide. Utilisez une IPv4 ou un réseau IPv4/CIDR.');
        }
      }
      $group = $role === 'manager' ? 'mikhmon-gerant' : 'mikhmon-vendeur';
      $policy = $role === 'manager'
        ? 'read,write,test,winbox,password,web,api,rest-api'
        : 'read,test,winbox,password,web';
      $groups = $api->comm('/user/group/print', array('?name' => $group));
      if (!is_array($groups)) {
        return array('ok' => false, 'error' => 'Impossible de vérifier le groupe RouterOS limité.');
      }
      if (empty($groups)) {
        $groupResponse = $api->comm('/user/group/add', array(
          'name' => $group,
          'policy' => $policy,
          'comment' => 'Groupe limité créé par Mikhmon pour les comptes ' . ($role === 'manager' ? 'gérants' : 'vendeurs'),
        ));
      } else {
        $groupId = isset($groups[0]['.id']) ? $groups[0]['.id'] : '';
        if ($groupId === '') {
          return array('ok' => false, 'error' => 'Identifiant du groupe RouterOS limité introuvable.');
        }
        $groupResponse = $api->comm('/user/group/set', array(
          '.id' => $groupId,
          'policy' => $policy,
        ));
      }
      if (!mikhmon_hotspot_routeros_response_ok($groupResponse)) {
        return array('ok' => false, 'error' => 'MikroTik a refusé la configuration du groupe limité.');
      }

      $attributes = array(
        'name' => $username,
        'password' => $password,
        'group' => $group,
        'comment' => $comment,
      );
      if ($address !== '') {
        $attributes['address'] = $address;
      }
      $response = $api->comm('/user/add', $attributes);
    }

    if (!mikhmon_hotspot_routeros_response_ok($response)) {
      return array('ok' => false, 'error' => 'MikroTik a refusé la création du compte.');
    }

    return array(
      'ok' => true,
      'mode' => $mode,
      'candidate' => array(
        'account_key' => $username,
        'username' => $username,
        'password' => $password,
        'display_name' => $displayName,
        'comment' => $comment,
        'source' => $mode,
      ),
    );
  }

  function mikhmon_hotspot_account_record($candidate, $session, $role, $password = null)
  {
    $role = strtolower(trim((string) $role));
    $key = isset($candidate['account_key']) ? mikhmon_hotspot_account_key($candidate['account_key']) : '';
    $name = isset($candidate['display_name']) ? mikhmon_hotspot_account_label($candidate['display_name']) : '';
    $password = $password === null ? (isset($candidate['password']) ? (string) $candidate['password'] : '') : (string) $password;
    $session = trim((string) $session);

    $record = array(
      'password' => $password,
      'name' => $name !== '' ? $name : ucfirst(strtolower($key)),
      'session' => $session,
    );

    if ($role === 'seller') {
      $record['commission'] = 10;
      return array('var' => 'sellers_data', 'key' => $key, 'record' => $record);
    }

    if ($role === 'manager') {
      return array('var' => 'managers_data', 'key' => $key, 'record' => $record);
    }

    return array('var' => '', 'key' => '', 'record' => array());
  }
}
