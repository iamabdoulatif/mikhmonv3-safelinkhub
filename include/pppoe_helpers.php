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
    static $printed = false;
    if ($printed) {
      return;
    }
    $printed = true;
    ?>
<style>
.ppp-action-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 10px;
}
.ppp-row-actions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: center;
}
.ppp-form-page {
  display: flex;
  flex-wrap: wrap;
}
.ppp-form-page .ppp-main-panel {
  flex: 1 1 620px;
  max-width: 100%;
}
.ppp-form-page .ppp-help-panel {
  flex: 1 1 260px;
  max-width: 100%;
}
.ppp-form-table td:first-child {
  width: 220px;
  font-weight: 600;
}
.ppp-responsive-table td[data-label] {
  vertical-align: middle;
}
@media (max-width: 760px) {
  .ppp-form-page .col-8,
  .ppp-form-page .col-4,
  .ppp-form-page .ppp-main-panel,
  .ppp-form-page .ppp-help-panel {
    flex: 0 0 100%;
    max-width: 100%;
    width: 100%;
  }
  .ppp-action-bar {
    align-items: stretch;
  }
  .ppp-action-bar .btn {
    flex: 1 1 120px;
    text-align: center;
  }
  .ppp-form-table,
  .ppp-form-table tbody,
  .ppp-form-table tr,
  .ppp-form-table td {
    display: block;
    width: 100%;
  }
  .ppp-form-table td {
    border: 0 !important;
    padding: 6px 0 !important;
  }
  .ppp-form-table td:first-child {
    width: 100%;
    padding-top: 12px !important;
  }
  .ppp-form-table input,
  .ppp-form-table select,
  .ppp-form-table .input-group {
    max-width: 100%;
    width: 100%;
  }
  .ppp-responsive-table,
  .ppp-responsive-table thead,
  .ppp-responsive-table tbody,
  .ppp-responsive-table th,
  .ppp-responsive-table tr,
  .ppp-responsive-table td {
    display: block;
    width: 100%;
  }
  .ppp-responsive-table thead {
    display: none;
  }
  .ppp-responsive-table tr {
    border: 1px solid rgba(128, 128, 128, 0.35);
    border-radius: 6px;
    margin-bottom: 12px;
    padding: 6px 8px;
  }
  .ppp-responsive-table td {
    border: 0 !important;
    border-bottom: 1px solid rgba(128, 128, 128, 0.18) !important;
    display: flex;
    gap: 12px;
    justify-content: space-between;
    text-align: right !important;
    white-space: normal !important;
  }
  .ppp-responsive-table td:last-child {
    border-bottom: 0 !important;
  }
  .ppp-responsive-table td[data-label]:before {
    content: attr(data-label);
    flex: 0 0 42%;
    font-weight: 600;
    text-align: left;
  }
  .ppp-responsive-table td.ppp-empty {
    justify-content: center;
    text-align: center !important;
  }
  .ppp-responsive-table td.ppp-empty:before {
    content: "";
    display: none;
  }
}
</style>
    <?php
  }
}
