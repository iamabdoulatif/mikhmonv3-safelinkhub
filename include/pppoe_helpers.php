<?php

if (!function_exists('mikhmon_ppp_bool')) {
  function mikhmon_ppp_bool($value, $default = 'no')
  {
    $value = strtolower(trim((string) $value));
    return in_array($value, array('yes', 'no'), true) ? $value : $default;
  }

  function mikhmon_ppp_service($value)
  {
    $value = strtolower(trim((string) $value));
    return in_array($value, array('pppoe', 'pptp', 'l2tp', 'sstp', 'ovpn', 'any'), true) ? $value : 'pppoe';
  }

  function mikhmon_ppp_get($row, $key, $fallback = '')
  {
    return isset($row[$key]) ? (string) $row[$key] : $fallback;
  }

  function mikhmon_ppp_text($key, $fallback)
  {
    return isset($GLOBALS[$key]) && $GLOBALS[$key] !== '' ? (string) $GLOBALS[$key] : $fallback;
  }

  function mikhmon_ppp_label($key, $fallback)
  {
    return htmlspecialchars(mikhmon_ppp_text($key, $fallback), ENT_QUOTES, 'UTF-8');
  }

  function mikhmon_ppp_safe_id($value)
  {
    return preg_replace('/[^A-Za-z0-9_*.-]/', '', (string) $value);
  }

  function mikhmon_ppp_count_unit($count)
  {
    return ((int) $count > 1) ? 'items' : 'item';
  }

  function mikhmon_ppp_redirect($target)
  {
    echo "<script>window.location='" . $target . "'</script>";
    exit;
  }

  function mikhmon_ppp_responsive_css()
  {
    /* CSS déplacé dans css/mikhmon-responsive.css — aucune sortie inline nécessaire. */
  }
}
