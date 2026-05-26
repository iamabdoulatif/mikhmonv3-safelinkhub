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

  function mikhmon_hotspot_assignment_comment($comment, $session, $role, $accountKey)
  {
    $comment = mikhmon_hotspot_account_label($comment);
    $session = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string) $session));
    $accountKey = mikhmon_hotspot_account_key($accountKey);
    $role = strtolower(trim((string) $role));
    $roleLabel = $role === 'manager' ? 'gerant' : 'vendeur';

    $baseComment = mikhmon_hotspot_assignment_base_comment($comment);
    $trace = 'MIKHMON_ACCOUNT role=' . $roleLabel . ' session=' . $session . ' account=' . $accountKey;

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

    return array(
      'role' => $role,
      'session' => $foundSession,
      'account_key' => $account,
      'base_comment' => mikhmon_hotspot_assignment_base_comment($comment),
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

  function mikhmon_hotspot_clear_account_footprints($api, $hotspotUsers, $ipBindings, $session, $role, $accountKey)
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
      $newComment = mikhmon_hotspot_clear_assignment_comment($comment, $session, $role, $accountKey);
      $response = $api->comm("/ip/hotspot/user/set", array(
        ".id" => $user['.id'],
        "comment" => $newComment
      ));
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
      $newComment = mikhmon_hotspot_clear_assignment_comment($comment, $session, $role, $accountKey);
      $response = $api->comm("/ip/hotspot/ip-binding/set", array(
        ".id" => $binding['.id'],
        "comment" => $newComment
      ));
      if (!mikhmon_hotspot_routeros_response_ok($response)) {
        $ok = false;
      }
    }

    return $ok;
  }

  function mikhmon_hotspot_restored_account_records($hotspotUsers, $ipBindings, $session, $sellersData = array(), $managersData = array())
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
