<?php
if (!function_exists('mikhmon_configure_routeros_api')) {
  function mikhmon_configure_routeros_api($api)
  {
    // 3s provoque des lectures vides sur grosses tables, mais 30s bloque trop
    // longtemps les portails si RouterOS est lent ou indisponible.
    $api->timeout = 12;
    $api->attempts = 1;
    $api->delay = 0;
    return $api;
  }
}

if (!function_exists('mikhmon_comm_with_reconnect')) {
  /**
   * Exécute $API->comm($path, $params) et, si le résultat est vide, suppose
   * qu'une requête précédente sur une grosse table a coupé silencieusement la
   * connexion RouterOS : rouvre une connexion fraîche et retente une fois.
   */
  function mikhmon_comm_with_reconnect($API, $path, $params, $iphost, $userhost, $passwdhost)
  {
    $result = $API->comm($path, $params);
    if (!is_array($result)) {
      $result = array();
    }

    if (empty($result) && method_exists($API, 'disconnect') && method_exists($API, 'connect')) {
      $API->disconnect();
      if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $result = $API->comm($path, $params);
        if (!is_array($result)) {
          $result = array();
        }
      }
    }

    return $result;
  }
}

if (!function_exists('mikhmon_month_map')) {
  function mikhmon_month_map()
  {
    return array(
      '01' => 'jan',
      '02' => 'feb',
      '03' => 'mar',
      '04' => 'apr',
      '05' => 'may',
      '06' => 'jun',
      '07' => 'jul',
      '08' => 'aug',
      '09' => 'sep',
      '10' => 'oct',
      '11' => 'nov',
      '12' => 'dec',
    );
  }

  function mikhmon_generate_ticket_limit()
  {
    return 1000;
  }

  function mikhmon_hotspot_fast_generate_threshold()
  {
    return mikhmon_generate_ticket_limit() + 1;
  }

  function mikhmon_hotspot_fast_generate_chunk_size()
  {
    return 150;
  }

  function mikhmon_normalize_sale_date($rawDate)
  {
    $rawDate = trim((string) $rawDate);
    if ($rawDate === '') {
      return '';
    }

    if (preg_match('/^[A-Za-z]{3}\/\d{2}\/\d{4}$/', $rawDate)) {
      return strtolower(substr($rawDate, 0, 3)) . substr($rawDate, 3);
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $matches)) {
      $months = mikhmon_month_map();
      if (isset($months[$matches[2]])) {
        return $months[$matches[2]] . '/' . $matches[3] . '/' . $matches[1];
      }
    }

    return $rawDate;
  }

  function mikhmon_sale_month_key($rawDate)
  {
    $normalizedDate = mikhmon_normalize_sale_date($rawDate);
    if (preg_match('/^([a-z]{3})\/\d{2}\/(\d{4})$/', $normalizedDate, $matches)) {
      return strtolower($matches[1]) . $matches[2];
    }

    return '';
  }

  function mikhmon_sale_day_number($rawDate)
  {
    $normalizedDate = mikhmon_normalize_sale_date($rawDate);
    if (preg_match('/^[a-z]{3}\/(\d{2})\/\d{4}$/', $normalizedDate, $matches)) {
      return (int) $matches[1];
    }

    return 0;
  }

  function mikhmon_parse_sale_script($script)
  {
    $parts = explode('-|-', isset($script['name']) ? $script['name'] : '');
    $rawDate = '';
    if (!empty($parts[0])) {
      $rawDate = $parts[0];
    } elseif (!empty($script['source'])) {
      $rawDate = $script['source'];
    }

    return array(
      'date_raw' => $rawDate,
      'date' => mikhmon_normalize_sale_date($rawDate),
      'month_key' => mikhmon_sale_month_key($rawDate),
      'time' => isset($parts[1]) ? $parts[1] : '',
      'user' => isset($parts[2]) ? $parts[2] : '',
      'price' => isset($parts[3]) ? $parts[3] : '0',
      'address' => isset($parts[4]) ? $parts[4] : '',
      'mac' => isset($parts[5]) ? $parts[5] : '',
      'validity' => isset($parts[6]) ? $parts[6] : '',
      'profile' => isset($parts[7]) ? $parts[7] : '',
      'comment' => isset($parts[8]) ? $parts[8] : '',
      'source' => isset($script['source']) ? $script['source'] : '',
      'owner' => isset($script['owner']) ? $script['owner'] : '',
      'name' => isset($script['name']) ? $script['name'] : '',
    );
  }

  function mikhmon_filter_sale_scripts($scripts, $dayKey = '', $monthKey = '')
  {
    $sales = array();
    foreach ($scripts as $script) {
      $sale = mikhmon_parse_sale_script($script);
      if ($sale['date'] === '') {
        continue;
      }

      if ($dayKey !== '' && $sale['date'] !== $dayKey) {
        continue;
      }

      if ($monthKey !== '' && $sale['month_key'] !== $monthKey) {
        continue;
      }

      $sales[] = $sale;
    }

    return $sales;
  }

  function mikhmon_sale_iso_date($rawDate)
  {
    $rawDate = trim((string) $rawDate);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
      return $rawDate;
    }

    return mikhmon_iso_date_from_day_key($rawDate);
  }

  function mikhmon_sales_period_bounds($period, $year = null, $month = null, $week = null)
  {
    $period = strtolower(trim((string) $period));
    if (!in_array($period, array('week', 'month', 'year'), true)) {
      $period = 'month';
    }

    $year = (int) $year;
    if ($year < 2018 || $year > 2100) {
      $year = (int) date('Y');
    }

    $month = (int) $month;
    if ($month < 1 || $month > 12) {
      $month = (int) date('n');
    }

    $week = (int) $week;
    if ($week < 1 || $week > 53) {
      $week = (int) date('W');
    }

    $months = mikhmon_month_map();

    if ($period === 'week') {
      $start = new DateTime();
      $start->setISODate($year, $week, 1);
      $end = clone $start;
      $end->modify('+6 days');
      return array(
        'period' => 'week',
        'year' => $year,
        'month' => (int) $start->format('n'),
        'week' => (int) $start->format('W'),
        'from' => $start->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
        'label' => 'Semaine ' . sprintf('%02d', $week) . ' · ' . $start->format('Y-m-d') . ' au ' . $end->format('Y-m-d'),
        'month_key' => $months[$start->format('m')] . $start->format('Y'),
      );
    }

    if ($period === 'year') {
      return array(
        'period' => 'year',
        'year' => $year,
        'month' => $month,
        'week' => $week,
        'from' => sprintf('%04d-01-01', $year),
        'to' => sprintf('%04d-12-31', $year),
        'label' => 'Année ' . $year,
        'month_key' => $months[sprintf('%02d', $month)] . $year,
      );
    }

    $monthKey = $months[sprintf('%02d', $month)] . $year;
    $bounds = mikhmon_accounting_month_bounds($monthKey);

    return array(
      'period' => 'month',
      'year' => $year,
      'month' => $month,
      'week' => $week,
      'from' => $bounds['from'],
      'to' => $bounds['to'],
      'label' => ucfirst($months[sprintf('%02d', $month)]) . ' ' . $year,
      'month_key' => $monthKey,
    );
  }

  function mikhmon_filter_sale_scripts_by_iso_range($scripts, $fromIso, $toIso)
  {
    $fromIso = mikhmon_sale_iso_date($fromIso);
    $toIso = mikhmon_sale_iso_date($toIso);
    if ($fromIso === '' || $toIso === '') {
      return array();
    }
    if ($fromIso > $toIso) {
      $tmp = $fromIso;
      $fromIso = $toIso;
      $toIso = $tmp;
    }

    $sales = array();
    foreach (mikhmon_unique_sale_scripts($scripts) as $script) {
      $sale = (isset($script['date']) && isset($script['price'])) ? $script : mikhmon_parse_sale_script($script);
      $saleIso = mikhmon_sale_iso_date(isset($sale['date']) ? $sale['date'] : '');
      if ($saleIso === '' || $saleIso < $fromIso || $saleIso > $toIso) {
        continue;
      }
      if (!isset($sale['date'])) {
        $sale['date'] = mikhmon_normalize_sale_date($saleIso);
      }
      if (!isset($sale['month_key']) || $sale['month_key'] === '') {
        $sale['month_key'] = mikhmon_sale_month_key($sale['date']);
      }
      $sales[] = $sale;
    }

    return $sales;
  }

  function mikhmon_iso_date_from_day_key($dayKey)
  {
    $normalized = mikhmon_normalize_sale_date($dayKey);
    if (!preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})$/', $normalized, $matches)) {
      return '';
    }

    $months = array_flip(mikhmon_month_map());
    $month = strtolower($matches[1]);
    if (!isset($months[$month])) {
      return '';
    }

    return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $months[$month], (int) $matches[2]);
  }

  function mikhmon_safe_timezone($timezone, $fallback = 'UTC')
  {
    $timezone = trim((string) $timezone);
    if ($timezone === '') {
      $timezone = $fallback;
    }

    try {
      new DateTimeZone($timezone);
      return $timezone;
    } catch (Exception $e) {
      return $fallback;
    }
  }

  function mikhmon_router_clock_day_key($clock, $fallbackTimezone = 'UTC')
  {
    $rawDate = is_array($clock) && isset($clock['date']) ? $clock['date'] : '';
    $dayKey = mikhmon_normalize_sale_date($rawDate);
    if ($dayKey !== '') {
      return $dayKey;
    }

    $timezone = mikhmon_safe_timezone(
      is_array($clock) && isset($clock['time-zone-name']) ? $clock['time-zone-name'] : $fallbackTimezone,
      $fallbackTimezone
    );
    $now = new DateTime('now', new DateTimeZone($timezone));
    $months = mikhmon_month_map();
    return $months[$now->format('m')] . '/' . $now->format('d') . '/' . $now->format('Y');
  }

  function mikhmon_router_clock_display($clock, $fallbackTimezone = 'UTC')
  {
    $dayKey = mikhmon_router_clock_day_key($clock, $fallbackTimezone);
    $isoDate = mikhmon_iso_date_from_day_key($dayKey);
    if ($isoDate === '') {
      $isoDate = date('Y-m-d');
    }

    $time = is_array($clock) && isset($clock['time']) ? trim((string) $clock['time']) : '';
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
      $timezone = mikhmon_safe_timezone(
        is_array($clock) && isset($clock['time-zone-name']) ? $clock['time-zone-name'] : $fallbackTimezone,
        $fallbackTimezone
      );
      $time = (new DateTime('now', new DateTimeZone($timezone)))->format('H:i:s');
    }

    return $isoDate . ' ' . $time;
  }

  function mikhmon_parse_money_amount($amount)
  {
    $value = trim((string) $amount);
    if ($value === '') {
      return 0.0;
    }

    $value = preg_replace('/[^\d,.\-]/', '', str_replace(array(' ', "\xc2\xa0"), '', $value));
    if ($value === '' || $value === '-' || $value === null) {
      return 0.0;
    }

    $lastComma = strrpos($value, ',');
    $lastDot = strrpos($value, '.');
    if ($lastComma !== false && $lastDot !== false) {
      if ($lastComma > $lastDot) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
      } else {
        $value = str_replace(',', '', $value);
      }
      return (float) $value;
    }

    if ($lastComma !== false) {
      $parts = explode(',', $value);
      $tail = end($parts);
      if (strlen($tail) === 3 && count($parts) > 1) {
        return (float) str_replace(',', '', $value);
      }
      return (float) str_replace(',', '.', $value);
    }

    if ($lastDot !== false) {
      $parts = explode('.', $value);
      $tail = end($parts);
      if (strlen($tail) === 3 && count($parts) > 1) {
        return (float) str_replace('.', '', $value);
      }
    }

    return (float) $value;
  }

  function mikhmon_currency_uses_integer_amounts($currency, $cekindo = array())
  {
    $currency = trim((string) $currency);
    $indo = isset($cekindo['indo']) && is_array($cekindo['indo']) ? $cekindo['indo'] : array();
    if (in_array($currency, $indo, true)) {
      return true;
    }

    return in_array(strtoupper($currency), array('XOF', 'XAF', 'CFA', 'FCFA', 'GNF', 'KMF', 'DJF', 'RWF', 'BIF', 'CLP', 'JPY', 'KRW', 'VND'), true);
  }

  function mikhmon_format_money_amount($amount, $currency, $cekindo = array())
  {
    $amount = (float) $amount;
    if (mikhmon_currency_uses_integer_amounts($currency, $cekindo)) {
      $thousands = in_array(trim((string) $currency), isset($cekindo['indo']) ? $cekindo['indo'] : array(), true) ? '.' : ' ';
      return trim((string) $currency . ' ' . number_format($amount, 0, ',', $thousands));
    }

    return trim((string) $currency . ' ' . number_format($amount, 2, '.', ','));
  }

  function mikhmon_accounting_month_bounds($monthKey)
  {
    $monthKey = strtolower(trim((string) $monthKey));
    if (!preg_match('/^([a-z]{3})(\d{4})$/', $monthKey, $matches)) {
      return array('from' => date('Y-m-01'), 'to' => date('Y-m-t'));
    }

    $months = array_flip(mikhmon_month_map());
    if (!isset($months[$matches[1]])) {
      return array('from' => date('Y-m-01'), 'to' => date('Y-m-t'));
    }

    $year = (int) $matches[2];
    $month = $months[$matches[1]];
    $monthStart = DateTime::createFromFormat('!Y-n-j', $year . '-' . (int) $month . '-1');
    if (!$monthStart) {
      return array('from' => date('Y-m-01'), 'to' => date('Y-m-t'));
    }
    $days = (int) $monthStart->format('t');

    return array(
      'from' => sprintf('%04d-%02d-01', $year, (int) $month),
      'to' => sprintf('%04d-%02d-%02d', $year, (int) $month, $days),
    );
  }

  function mikhmon_accounting_iso_date($date, $fallback = '')
  {
    $date = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      return $date;
    }

    $iso = mikhmon_iso_date_from_day_key($date);
    if ($iso !== '') {
      return $iso;
    }

    return $fallback;
  }

  function mikhmon_accounting_settlement_time($time, $fallback = '')
  {
    $time = trim((string) $time);
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
      $time .= ':00';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
      return $time;
    }

    $fallback = trim((string) $fallback);
    if (preg_match('/^\d{2}:\d{2}$/', $fallback)) {
      return $fallback . ':00';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $fallback)) {
      return $fallback;
    }

    return date('H:i:s');
  }

  function mikhmon_accounting_commission_rate($sellerData = array())
  {
    return 10;
  }

  function mikhmon_accounting_sale_datetime($sale)
  {
    $saleIso = mikhmon_accounting_iso_date(isset($sale['date']) ? $sale['date'] : '');
    if ($saleIso === '') {
      return '';
    }

    $saleTime = mikhmon_accounting_settlement_time(isset($sale['time']) ? $sale['time'] : '00:00:00', '00:00:00');
    return $saleIso . ' ' . $saleTime;
  }

  function mikhmon_accounting_day_keys_between($fromIso, $toIso)
  {
    $fromIso = mikhmon_accounting_iso_date($fromIso);
    $toIso = mikhmon_accounting_iso_date($toIso, $fromIso);
    if ($fromIso === '' || $toIso === '') {
      return array();
    }
    if ($fromIso > $toIso) {
      $tmp = $fromIso;
      $fromIso = $toIso;
      $toIso = $tmp;
    }

    $days = array();
    $cursor = new DateTime($fromIso);
    $end = new DateTime($toIso);
    $months = mikhmon_month_map();

    while ($cursor <= $end) {
      $days[] = array(
        'iso' => $cursor->format('Y-m-d'),
        'key' => $months[$cursor->format('m')] . '/' . $cursor->format('d') . '/' . $cursor->format('Y'),
      );
      $cursor->modify('+1 day');
    }

    return $days;
  }

  function mikhmon_accounting_blank_total()
  {
    return array('count' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'net' => 0.0);
  }

  function mikhmon_accounting_seller_key($comment, $sellersData)
  {
    $rawComment = trim((string) $comment);
    if (function_exists('mikhmon_comment_seller_key')) {
      return mikhmon_comment_seller_key($rawComment, $sellersData);
    }

    $comment = strtolower($rawComment);
    if ($comment === '' || !is_array($sellersData)) {
      return '';
    }

    if (preg_match('/MIKHMON_ACCOUNT\s+role=([^\s|]+)\s+session=([^\s|]+)\s+account=([^\s|]+)/i', $rawComment, $matches)) {
      $role = strtolower(trim($matches[1]));
      $account = preg_replace('/[^a-zA-Z0-9_]/', '', trim($matches[3]));
      if (($role === 'seller' || $role === 'vendeur') && $account !== '' && isset($sellersData[$account])) {
        return $account;
      }
    }

    foreach ($sellersData as $sellerKey => $sellerData) {
      $sellerKey = trim((string) $sellerKey);
      if ($sellerKey === '') {
        continue;
      }
      $aliases = array($sellerKey);
      if (is_array($sellerData) && isset($sellerData['name'])) {
        $sellerName = trim((string) $sellerData['name']);
        if ($sellerName !== '') {
          $aliases[] = $sellerName;
        }
      }
      foreach ($aliases as $alias) {
        $normalizedSeller = strtolower(preg_replace('/\s+/', ' ', trim((string) $alias)));
        if ($normalizedSeller === '') {
          continue;
        }
        $suffix = '-' . $normalizedSeller;
        if ($comment === $normalizedSeller || substr($comment, -strlen($suffix)) === $suffix) {
          return $sellerKey;
        }
      }
    }

    return '';
  }

  function mikhmon_accounting_historical_sellers($sales, $session, $sellersData = array())
  {
    $historical = array();
    $session = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $session);

    foreach (mikhmon_unique_sale_scripts($sales) as $script) {
      $sale = (isset($script['date']) && isset($script['comment'])) ? $script : mikhmon_parse_sale_script($script);
      $comment = trim(isset($sale['comment']) ? (string) $sale['comment'] : '');
      if ($comment === '') {
        continue;
      }

      $candidate = $comment;
      $separator = strrpos($comment, '-');
      if ($separator !== false) {
        $candidate = substr($comment, $separator + 1);
      }
      $candidate = preg_replace('/[^a-zA-Z0-9_]/', '', trim($candidate));
      if ($candidate === '' || isset($sellersData[$candidate]) || isset($historical[$candidate])) {
        continue;
      }

      $historical[$candidate] = array(
        'password' => '',
        'name' => ucfirst(strtolower($candidate)) . ' (historique)',
        'session' => $session,
        'commission' => 10,
        'historical' => true,
      );
    }

    return $historical;
  }

  function mikhmon_accounting_add_amount(&$bucket, $amount, $commission)
  {
    $amount = (float) $amount;
    $commission = (float) $commission;
    $bucket['count']++;
    $bucket['revenue'] += $amount;
    $bucket['commission'] += $commission;
    $bucket['net'] += ($amount - $commission);
  }

  function mikhmon_accounting_period_summary($sales, $sellersData, $fromIso, $toIso, $sellerFilter = '', $fromTime = '00:00:00', $toTime = '23:59:59')
  {
    $fromIso = mikhmon_accounting_iso_date($fromIso);
    $toIso = mikhmon_accounting_iso_date($toIso, $fromIso);
    $fromTime = mikhmon_accounting_settlement_time($fromTime, '00:00:00');
    $toTime = mikhmon_accounting_settlement_time($toTime, '23:59:59');
    if ($fromIso === '' || $toIso === '') {
      return array('from' => '', 'to' => '', 'days' => array(), 'total' => mikhmon_accounting_blank_total());
    }
    if ($fromIso > $toIso) {
      $tmp = $fromIso;
      $fromIso = $toIso;
      $toIso = $tmp;
    }
    $fromDateTime = $fromIso . ' ' . $fromTime;
    $toDateTime = $toIso . ' ' . $toTime;
    if ($fromDateTime > $toDateTime) {
      $tmpDateTime = $fromDateTime;
      $fromDateTime = $toDateTime;
      $toDateTime = $tmpDateTime;
    }

    $sellerFilter = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $sellerFilter);
    $summary = array(
      'from' => $fromIso,
      'to' => $toIso,
      'from_time' => $fromTime,
      'to_time' => $toTime,
      'days' => array(),
      'total' => mikhmon_accounting_blank_total(),
    );

    foreach (mikhmon_accounting_day_keys_between($fromIso, $toIso) as $day) {
      $summary['days'][$day['key']] = array(
        'date' => $day['key'],
        'iso' => $day['iso'],
        'sellers' => array(),
        'total' => mikhmon_accounting_blank_total(),
      );
    }

    foreach (mikhmon_unique_sale_scripts($sales) as $script) {
      $sale = (isset($script['date']) && isset($script['price'])) ? $script : mikhmon_parse_sale_script($script);
      $saleIso = mikhmon_accounting_iso_date(isset($sale['date']) ? $sale['date'] : '');
      if ($saleIso === '' || $saleIso < $fromIso || $saleIso > $toIso) {
        continue;
      }
      $saleDateTime = mikhmon_accounting_sale_datetime($sale);
      if ($saleDateTime === '' || $saleDateTime < $fromDateTime || $saleDateTime > $toDateTime) {
        continue;
      }

      $sellerKey = mikhmon_accounting_seller_key(isset($sale['comment']) ? $sale['comment'] : '', $sellersData);
      if ($sellerKey === '' || !isset($sellersData[$sellerKey])) {
        continue;
      }
      if ($sellerFilter !== '' && $sellerKey !== $sellerFilter) {
        continue;
      }

      $dayKey = mikhmon_normalize_sale_date(isset($sale['date']) ? $sale['date'] : '');
      if (!isset($summary['days'][$dayKey])) {
        continue;
      }

      $amount = mikhmon_parse_money_amount(isset($sale['price']) ? $sale['price'] : 0);
      $rate = mikhmon_accounting_commission_rate($sellersData[$sellerKey]);
      $commission = $amount * $rate / 100;
      $sellerName = isset($sellersData[$sellerKey]['name']) ? $sellersData[$sellerKey]['name'] : $sellerKey;

      if (!isset($summary['days'][$dayKey]['sellers'][$sellerKey])) {
        $summary['days'][$dayKey]['sellers'][$sellerKey] = array(
          'key' => $sellerKey,
          'name' => $sellerName,
          'commission_rate' => $rate,
          'profiles' => array(),
        ) + mikhmon_accounting_blank_total();
      }

      $profile = isset($sale['profile']) && trim((string) $sale['profile']) !== '' ? trim((string) $sale['profile']) : '-';
      if (!isset($summary['days'][$dayKey]['sellers'][$sellerKey]['profiles'][$profile])) {
        $summary['days'][$dayKey]['sellers'][$sellerKey]['profiles'][$profile] = mikhmon_accounting_blank_total();
      }

      mikhmon_accounting_add_amount($summary['days'][$dayKey]['sellers'][$sellerKey], $amount, $commission);
      mikhmon_accounting_add_amount($summary['days'][$dayKey]['sellers'][$sellerKey]['profiles'][$profile], $amount, $commission);
      mikhmon_accounting_add_amount($summary['days'][$dayKey]['total'], $amount, $commission);
      mikhmon_accounting_add_amount($summary['total'], $amount, $commission);
    }

    return $summary;
  }

  function mikhmon_php_literal($value)
  {
    if (is_array($value)) {
      $parts = array();
      foreach ($value as $key => $item) {
        $parts[] = mikhmon_php_literal($key) . '=>' . mikhmon_php_literal($item);
      }
      return 'array(' . implode(',', $parts) . ')';
    }

    if (is_int($value)) {
      return (string) $value;
    }

    if (is_float($value)) {
      return str_replace(',', '.', (string) $value);
    }

    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if ($value === null) {
      return 'null';
    }

    $value = str_replace(
      array('\\', "'", "\r", "\n", "\0"),
      array('\\\\', "\\'", '\\r', '\\n', '\\0'),
      (string) $value
    );
    return "'" . $value . "'";
  }

  function mikhmon_php_assignment_line($varName, $key, $value)
  {
    $varName = trim((string) $varName);
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $varName)) {
      throw new InvalidArgumentException('Invalid PHP variable name.');
    }

    return '$' . $varName . '[' . mikhmon_php_literal($key) . '] = ' . mikhmon_php_literal($value) . ';' . "\n";
  }

  function mikhmon_account_password_is_hash($storedPassword)
  {
    $info = password_get_info((string) $storedPassword);
    return isset($info['algo']) && (int) $info['algo'] !== 0;
  }

  function mikhmon_account_password_matches($plainPassword, $storedPassword)
  {
    $storedPassword = (string) $storedPassword;
    if (mikhmon_account_password_is_hash($storedPassword)) {
      return password_verify((string) $plainPassword, $storedPassword);
    }

    $decoded = function_exists('decrypt') ? decrypt($storedPassword) : $storedPassword;
    return hash_equals((string) $decoded, (string) $plainPassword);
  }

  function mikhmon_account_password_storage($password)
  {
    $password = (string) $password;
    if (mikhmon_account_password_is_hash($password)) {
      return $password;
    }

    return function_exists('encrypt') ? encrypt($password) : $password;
  }

  function mikhmon_assignment_line_matches($line, $varName, $key)
  {
    $varName = trim((string) $varName);
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $varName)) {
      return false;
    }

    $literal = preg_quote(mikhmon_php_literal($key), '/');
    return (bool) preg_match('/^\s*\$' . preg_quote($varName, '/') . '\s*\[\s*' . $literal . '\s*\]\s*=/', (string) $line);
  }

  function mikhmon_normalize_session_name($name)
  {
    return preg_replace('/\s+/', '-', trim((string) $name));
  }

  function mikhmon_is_valid_session_name($name)
  {
    $name = (string) $name;
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,30}[A-Za-z0-9])?$/', $name)) {
      return false;
    }
    if (preg_match('/^(mikhmon|new-\d+)$/i', $name)) {
      return false;
    }
    if (preg_match('/(url|webhook|http|process|config|docker)/i', $name)) {
      return false;
    }
    return true;
  }

  function mikhmon_replace_assignment_line_in_file($file, $varName, $key, $value, $matchKey = null)
  {
    $newLine = rtrim(mikhmon_php_assignment_line($varName, $key, $value), "\r\n");
    $matchKey = $matchKey === null ? $key : $matchKey;
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : array('<?php');
    $out = array();
    $replaced = false;

    foreach ($lines as $line) {
      if (mikhmon_assignment_line_matches($line, $varName, $matchKey)) {
        if (!$replaced) {
          $out[] = $newLine;
          $replaced = true;
        }
      } else {
        $out[] = $line;
      }
    }

    if (!$replaced) {
      $out[] = $newLine;
    }

    $written = file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX) !== false;
    if ($written && function_exists('opcache_invalidate')) {
      @opcache_invalidate($file, true);
    }
    return $written;
  }

  function mikhmon_delete_assignment_line_in_file($file, $varName, $key)
  {
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : array();
    $out = array();

    foreach ($lines as $line) {
      if (!mikhmon_assignment_line_matches($line, $varName, $key)) {
        $out[] = $line;
      }
    }

    $written = file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX) !== false;
    if ($written && function_exists('opcache_invalidate')) {
      @opcache_invalidate($file, true);
    }
    return $written;
  }

  function mikhmon_legacy_ros7_owner_keys($monthKey)
  {
    $monthKey = strtolower(trim((string) $monthKey));
    if (!preg_match('/^([a-z]{3})(\d{4})$/', $monthKey, $matches)) {
      return array();
    }

    $months = array_flip(mikhmon_month_map());
    if (!isset($months[$matches[1]])) {
      return array();
    }

    $year = $matches[2];
    $monthNumber = (int) $months[$matches[1]];
    $monthStart = DateTime::createFromFormat('!Y-n-j', (int) $year . '-' . $monthNumber . '-1');
    if (!$monthStart) {
      return array();
    }
    $days = (int) $monthStart->format('t');
    $prefix = substr($year, 0, 3) . substr(sprintf('%02d', $monthNumber), 1, 1) . '-';
    $owners = array();
    for ($day = 1; $day <= $days; $day++) {
      $owners[] = $prefix . sprintf('%02d', $day);
    }

    return $owners;
  }

  function mikhmon_fetch_mikhmon_sale_scripts($API)
  {
    $originalTimeout = null;
    if (is_object($API) && isset($API->timeout)) {
      $originalTimeout = $API->timeout;
      if ((int) $API->timeout < 90) {
        $API->timeout = 90;
      }
    }

    $rows = array();
    $data = $API->comm('/system/script/print', array('?comment' => 'mikhmon'));
    if (is_array($data) && !empty($data)) {
      $rows = $data;
    }

    $data = $API->comm('/system/script/print', array('.proplist' => 'name,source,owner,comment'));
    if (!is_array($data)) {
      if ($originalTimeout !== null) {
        $API->timeout = $originalTimeout;
      }
      return mikhmon_unique_sale_scripts($rows);
    }

    foreach ($data as $row) {
      if (!is_array($row)) {
        continue;
      }
      $comment = isset($row['comment']) ? strtolower(trim((string) $row['comment'])) : '';
      $name = isset($row['name']) ? (string) $row['name'] : '';
      $sale = mikhmon_parse_sale_script($row);
      if ($comment === 'mikhmon' || ($comment === '' && strpos($name, '-|-') !== false && $sale['date'] !== '')) {
        $rows[] = $row;
      }
    }

    if ($originalTimeout !== null) {
      $API->timeout = $originalTimeout;
    }

    return mikhmon_unique_sale_scripts($rows);
  }

  function mikhmon_fetch_sales_by_day($API, $dayKey)
  {
    $normalizedDay = mikhmon_normalize_sale_date($dayKey);
    $monthKey = mikhmon_sale_month_key($normalizedDay);
    $data = mikhmon_fetch_mikhmon_sale_scripts($API);
    if (is_array($data) && !empty($data)) {
      $rows = mikhmon_filter_sale_scripts(mikhmon_unique_sale_scripts($data), $normalizedDay, '');
      if (!empty($rows)) {
        return $rows;
      }
      return array();
    }

    $monthRows = mikhmon_sales_from_used_hotspot_users($API, '', $monthKey);
    if (!empty($monthRows)) {
      return mikhmon_filter_sale_scripts($monthRows, $normalizedDay, '');
    }
    return array();
  }

  function mikhmon_sales_through_day($sales, $dayKey)
  {
    $dayIso = mikhmon_iso_date_from_day_key($dayKey);
    if ($dayIso === '') {
      return mikhmon_unique_sale_scripts($sales);
    }

    $rows = array();
    foreach (mikhmon_unique_sale_scripts($sales) as $script) {
      $sale = mikhmon_parse_sale_script($script);
      $saleIso = mikhmon_iso_date_from_day_key($sale['date']);
      if ($saleIso === '' || $saleIso <= $dayIso) {
        $rows[] = $script;
      }
    }

    return $rows;
  }

  function mikhmon_fetch_sales_by_month($API, $monthKey, $throughDayKey = '')
  {
    $monthKey = strtolower(trim((string) $monthKey));
    $rows = array();
    $data = mikhmon_fetch_mikhmon_sale_scripts($API);
    if (is_array($data)) {
      $rows = mikhmon_filter_sale_scripts($data, '', $monthKey);
    }
    if (!empty($rows)) {
      return mikhmon_sales_through_day($rows, $throughDayKey);
    }

    $usedRows = mikhmon_sales_from_used_hotspot_users($API, '', $monthKey);
    if (!empty($usedRows)) {
      return mikhmon_sales_through_day($usedRows, $throughDayKey);
    }

    return mikhmon_sales_through_day($rows, $throughDayKey);
  }

  function mikhmon_fetch_sales_by_month_index($API, $monthKey, $throughDayKey = '', $includeLegacyOwners = true)
  {
    $monthKey = strtolower(trim((string) $monthKey));
    if ($monthKey === '') {
      return array();
    }

    $rows = array();
    $owners = array($monthKey);
    foreach ($owners as $owner) {
      $data = $API->comm('/system/script/print', array(
        '?owner' => $owner,
        '.proplist' => 'name,source,owner,comment',
      ));
      if (is_array($data) && !empty($data)) {
        $rows = array_merge($rows, $data);
      }
    }

    if ($includeLegacyOwners && empty($rows)) {
      foreach (mikhmon_legacy_ros7_owner_keys($monthKey) as $owner) {
        $data = $API->comm('/system/script/print', array(
          '?owner' => $owner,
          '.proplist' => 'name,source,owner,comment',
        ));
        if (is_array($data) && !empty($data)) {
          $rows = array_merge($rows, $data);
        }
      }
    }

    if (empty($rows)) {
      return array();
    }

    $rows = mikhmon_filter_sale_scripts(mikhmon_unique_sale_scripts($rows), '', $monthKey);
    return mikhmon_sales_through_day($rows, $throughDayKey);
  }

  function mikhmon_dashboard_sales_for_month($API, $monthKey, $throughDayKey = '', $iphost = '', $userhost = '', $passwdhost = '')
  {
    $monthKey = strtolower(trim((string) $monthKey));
    if ($monthKey === '') {
      return array();
    }

    $rows = mikhmon_fetch_sales_by_month_index($API, $monthKey, $throughDayKey, false);
    if (!empty($rows)) {
      return $rows;
    }

    if (method_exists($API, 'disconnect') && method_exists($API, 'connect')
        && trim((string) $iphost) !== '' && function_exists('decrypt')) {
      $API->disconnect();
      if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $rows = mikhmon_fetch_sales_by_month_index($API, $monthKey, $throughDayKey, false);
        if (!empty($rows)) {
          return $rows;
        }
      }
    }

    return array();
  }

  function mikhmon_latest_sale_month_key($sales)
  {
    $latestMonth = '';
    $latestIso = '';

    foreach (mikhmon_unique_sale_scripts($sales) as $script) {
      $sale = (isset($script['date']) && isset($script['month_key']))
        ? $script
        : mikhmon_parse_sale_script($script);
      $monthKey = isset($sale['month_key']) ? strtolower(trim((string) $sale['month_key'])) : '';
      if ($monthKey === '') {
        continue;
      }

      $saleIso = mikhmon_iso_date_from_day_key(isset($sale['date']) ? $sale['date'] : '');
      if ($saleIso === '') {
        $saleIso = '0000-00-00';
      }
      if ($latestMonth === '' || $saleIso >= $latestIso) {
        $latestMonth = $monthKey;
        $latestIso = $saleIso;
      }
    }

    return $latestMonth;
  }

  function mikhmon_report_sales_for_month($API, $monthKey, $throughDayKey = '')
  {
    $monthKey = strtolower(trim((string) $monthKey));
    $allSales = mikhmon_fetch_mikhmon_sale_scripts($API);
    $rows = array();

    if ($monthKey !== '') {
      $rows = mikhmon_filter_sale_scripts($allSales, '', $monthKey);
    }

    if ($monthKey === '' || empty($rows)) {
      $latestMonth = mikhmon_latest_sale_month_key($allSales);
      if ($latestMonth !== '' && $latestMonth !== $monthKey) {
        $monthKey = $latestMonth;
        $rows = mikhmon_filter_sale_scripts($allSales, '', $monthKey);
        $throughDayKey = '';
      }
    }

    if (!empty($rows)) {
      return array(
        'month_key' => $monthKey,
        'rows' => mikhmon_sales_through_day($rows, $throughDayKey),
      );
    }

    $fallbackRows = mikhmon_fetch_sales_by_month($API, $monthKey, $throughDayKey);
    return array(
      'month_key' => $monthKey,
      'rows' => $fallbackRows,
    );
  }

  function mikhmon_income_summary_from_scripts($scripts, $dayKey, $monthKey)
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
    $dayIso = mikhmon_iso_date_from_day_key($dayKey);
    $monthKey = strtolower(trim((string) $monthKey));
    $summary = array(
      'today_count' => 0,
      'today_total' => 0.0,
      'month_count' => 0,
      'month_total' => 0.0,
    );

    foreach (mikhmon_unique_sale_scripts($scripts) as $script) {
      $sale = mikhmon_parse_sale_script($script);
      if ($sale['month_key'] !== $monthKey) {
        continue;
      }

      $price = mikhmon_parse_money_amount($sale['price']);
      $saleIso = mikhmon_iso_date_from_day_key($sale['date']);
      if ($dayIso !== '' && $saleIso !== '' && $saleIso > $dayIso) {
        continue;
      }

      $summary['month_count']++;
      $summary['month_total'] += $price;

      if ($sale['date'] === $dayKey) {
        $summary['today_count']++;
        $summary['today_total'] += $price;
      }
    }

    return $summary;
  }

  function mikhmon_income_counter_file_value($API, $name)
  {
    $rows = $API->comm('/file/print', array(
      '?name' => $name,
      '.proplist' => 'name,contents',
    ));
    if (!is_array($rows) || empty($rows[0]['contents'])) {
      return 0.0;
    }

    return mikhmon_parse_money_amount($rows[0]['contents']);
  }

  function mikhmon_income_summary_from_counter_files($API, $dayKey)
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
    $monthKey = mikhmon_sale_month_key($dayKey);
    $dayNumber = mikhmon_sale_day_number($dayKey);
    $dayPrefix = 'mikhmon-income-day-' . $monthKey . '-' . sprintf('%02d', $dayNumber);
    $monthPrefix = 'mikhmon-income-month-' . $monthKey;

    return array(
      'today_count' => (int) mikhmon_income_counter_file_value($API, $dayPrefix . '-count.txt'),
      'today_total' => (float) mikhmon_income_counter_file_value($API, $dayPrefix . '-total.txt'),
      'month_count' => (int) mikhmon_income_counter_file_value($API, $monthPrefix . '-count.txt'),
      'month_total' => (float) mikhmon_income_counter_file_value($API, $monthPrefix . '-total.txt'),
    );
  }

  function mikhmon_routeros_duration_seconds($duration)
  {
    $duration = strtolower(trim((string) $duration));
    if ($duration === '' || $duration === '0') {
      return 0;
    }

    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $duration, $matches)) {
      return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    if (!preg_match_all('/(\d+)([wdhms])/', $duration, $matches, PREG_SET_ORDER)) {
      return 0;
    }

    $seconds = 0;
    foreach ($matches as $match) {
      $value = (int) $match[1];
      if ($match[2] === 'w') $seconds += $value * 604800;
      if ($match[2] === 'd') $seconds += $value * 86400;
      if ($match[2] === 'h') $seconds += $value * 3600;
      if ($match[2] === 'm') $seconds += $value * 60;
      if ($match[2] === 's') $seconds += $value;
    }

    return $seconds;
  }

  function mikhmon_expiration_comment_datetime($comment)
  {
    $comment = trim((string) $comment);
    if ($comment === '') {
      return null;
    }

    if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})(?:\s+(\d{2}:\d{2}:\d{2}))?$/i', $comment, $matches)) {
      $months = array_flip(mikhmon_month_map());
      $month = strtolower($matches[1]);
      if (!isset($months[$month])) {
        return null;
      }
      $time = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : '00:00:00';
      return DateTime::createFromFormat('!Y-m-d H:i:s', $matches[3] . '-' . $months[$month] . '-' . $matches[2] . ' ' . $time);
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}:\d{2}:\d{2}))?$/', $comment, $matches)) {
      $time = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : '00:00:00';
      return DateTime::createFromFormat('!Y-m-d H:i:s', $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $time);
    }

    return null;
  }

  function mikhmon_profile_income_meta_from_on_login($onLogin)
  {
    $parts = explode(',', (string) $onLogin);
    $price = isset($parts[4]) && trim($parts[4]) !== '' && trim($parts[4]) !== '0'
      ? trim($parts[4])
      : (isset($parts[2]) ? trim($parts[2]) : '0');

    return array(
      'price' => mikhmon_parse_money_amount($price),
      'validity' => isset($parts[3]) ? trim($parts[3]) : '',
    );
  }

  function mikhmon_income_summary_from_used_hotspot_users($API, $dayKey)
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
    $dayIso = mikhmon_iso_date_from_day_key($dayKey);
    $monthKey = mikhmon_sale_month_key($dayKey);
    $summary = array(
      'today_count' => 0,
      'today_total' => 0.0,
      'month_count' => 0,
      'month_total' => 0.0,
    );
    if ($dayIso === '' || $monthKey === '') {
      return $summary;
    }

    $profileRows = $API->comm('/ip/hotspot/user/profile/print', array('.proplist' => 'name,on-login'));
    $profiles = array();
    if (is_array($profileRows)) {
      foreach ($profileRows as $profileRow) {
        $profileName = isset($profileRow['name']) ? trim((string) $profileRow['name']) : '';
        if ($profileName === '') {
          continue;
        }
        $meta = mikhmon_profile_income_meta_from_on_login(isset($profileRow['on-login']) ? $profileRow['on-login'] : '');
        $meta['validity_seconds'] = mikhmon_routeros_duration_seconds($meta['validity']);
        if ($meta['price'] > 0 && $meta['validity_seconds'] > 0) {
          $profiles[$profileName] = $meta;
        }
      }
    }
    if (empty($profiles)) {
      return $summary;
    }

    $userRows = $API->comm('/ip/hotspot/user/print', array('.proplist' => 'name,profile,comment'));
    if (!is_array($userRows)) {
      return $summary;
    }

    foreach ($userRows as $userRow) {
      $profileName = isset($userRow['profile']) ? trim((string) $userRow['profile']) : '';
      if ($profileName === '' || !isset($profiles[$profileName])) {
        continue;
      }

      $expiresAt = mikhmon_expiration_comment_datetime(isset($userRow['comment']) ? $userRow['comment'] : '');
      if (!$expiresAt) {
        continue;
      }

      $soldAt = clone $expiresAt;
      $soldAt->modify('-' . (int) $profiles[$profileName]['validity_seconds'] . ' seconds');
      $saleIso = $soldAt->format('Y-m-d');
      $saleDay = mikhmon_normalize_sale_date($saleIso);
      $saleMonth = mikhmon_sale_month_key($saleDay);
      if ($saleMonth !== $monthKey) {
        continue;
      }

      $summary['month_count']++;
      $summary['month_total'] += $profiles[$profileName]['price'];

      if ($saleIso === $dayIso) {
        $summary['today_count']++;
        $summary['today_total'] += $profiles[$profileName]['price'];
      }
    }

    return $summary;
  }

  function mikhmon_sales_from_used_hotspot_users($API, $dayKey = '', $monthKey = '')
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
    $monthKey = strtolower(trim((string) $monthKey));
    if ($monthKey === '' && $dayKey !== '') {
      $monthKey = mikhmon_sale_month_key($dayKey);
    }

    $profileRows = $API->comm('/ip/hotspot/user/profile/print', array('.proplist' => 'name,on-login'));
    $profiles = array();
    if (is_array($profileRows)) {
      foreach ($profileRows as $profileRow) {
        $profileName = isset($profileRow['name']) ? trim((string) $profileRow['name']) : '';
        if ($profileName === '') {
          continue;
        }
        $meta = mikhmon_profile_income_meta_from_on_login(isset($profileRow['on-login']) ? $profileRow['on-login'] : '');
        $meta['validity_seconds'] = mikhmon_routeros_duration_seconds($meta['validity']);
        if ($meta['price'] > 0 && $meta['validity_seconds'] > 0) {
          $profiles[$profileName] = $meta;
        }
      }
    }
    if (empty($profiles)) {
      return array();
    }

    $userRows = $API->comm('/ip/hotspot/user/print', array('.proplist' => 'name,profile,comment'));
    if (!is_array($userRows)) {
      return array();
    }

    $rows = array();
    foreach ($userRows as $userRow) {
      $profileName = isset($userRow['profile']) ? trim((string) $userRow['profile']) : '';
      if ($profileName === '' || !isset($profiles[$profileName])) {
        continue;
      }

      $expiresAt = mikhmon_expiration_comment_datetime(isset($userRow['comment']) ? $userRow['comment'] : '');
      if (!$expiresAt) {
        continue;
      }

      $soldAt = clone $expiresAt;
      $soldAt->modify('-' . (int) $profiles[$profileName]['validity_seconds'] . ' seconds');
      $saleDay = mikhmon_normalize_sale_date($soldAt->format('Y-m-d'));
      $saleMonth = mikhmon_sale_month_key($saleDay);
      if ($saleMonth === '' || ($monthKey !== '' && $saleMonth !== $monthKey) || ($dayKey !== '' && $saleDay !== $dayKey)) {
        continue;
      }

      $userName = isset($userRow['name']) ? trim((string) $userRow['name']) : '';
      $comment = isset($userRow['comment']) ? trim((string) $userRow['comment']) : '';
      $price = (string) $profiles[$profileName]['price'];
      $parts = array(
        $saleDay,
        $soldAt->format('H:i:s'),
        $userName,
        $price,
        '',
        '',
        $profiles[$profileName]['validity'],
        $profileName,
        $comment,
      );
      $rows[] = array(
        'name' => implode('-|-', $parts),
        'source' => $saleDay,
        'owner' => $saleMonth,
        'comment' => 'mikhmon',
      );
    }

    return mikhmon_unique_sale_scripts($rows);
  }

  function mikhmon_income_summary_has_values($summary)
  {
    return is_array($summary)
      && ((int) $summary['today_count'] > 0
        || (float) $summary['today_total'] > 0
        || (int) $summary['month_count'] > 0
        || (float) $summary['month_total'] > 0);
  }

  function mikhmon_dashboard_income_summary($API, $dayKey, $monthlySales = null)
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
    $monthKey = mikhmon_sale_month_key($dayKey);
    if (is_array($monthlySales)) {
      $scriptSummary = mikhmon_income_summary_from_scripts($monthlySales, $dayKey, $monthKey);
      if (mikhmon_income_summary_has_values($scriptSummary)) {
        return $scriptSummary;
      }
    }

    mikhmon_ensure_income_counter_scheduler($API);
    $counterSummary = mikhmon_income_summary_from_counter_files($API, $dayKey);
    if (mikhmon_income_summary_has_values($counterSummary)) {
      if ((int) $counterSummary['today_count'] === 0 && (float) $counterSummary['today_total'] == 0.0) {
        $fallbackSummary = mikhmon_income_summary_from_used_hotspot_users($API, $dayKey);
        if ((int) $fallbackSummary['today_count'] > 0 || (float) $fallbackSummary['today_total'] > 0.0) {
          $counterSummary['today_count'] = $fallbackSummary['today_count'];
          $counterSummary['today_total'] = $fallbackSummary['today_total'];
        }
        if (mikhmon_income_summary_has_values($fallbackSummary)
            && ((int) $counterSummary['month_count'] === 0
              || (float) $counterSummary['month_total'] == 0.0
              || (float) $fallbackSummary['month_total'] > (float) $counterSummary['month_total'])) {
          $counterSummary['month_count'] = $fallbackSummary['month_count'];
          $counterSummary['month_total'] = $fallbackSummary['month_total'];
        }
      }
      return $counterSummary;
    }

    $scripts = is_array($monthlySales) ? $monthlySales : mikhmon_fetch_sales_by_month($API, $monthKey);
    $scriptSummary = mikhmon_income_summary_from_scripts($scripts, $dayKey, $monthKey);
    if (mikhmon_income_summary_has_values($scriptSummary)) {
      return $scriptSummary;
    }

    return mikhmon_income_summary_from_used_hotspot_users($API, $dayKey);
  }

  /**
   * Compute a monthly revenue forecast from the last N days of sales.
   *
   * Returns an array:
   *   days_sampled   – number of days that had at least one sale
   *   window_days    – number of calendar days in the look-back window
   *   avg_daily      – average daily revenue over those days
   *   days_remaining – calendar days left in the month (including today)
   *   month_so_far   – revenue already recorded this month
   *   projected      – projected month-end total
   *   confidence     – 'low' | 'medium' | 'high'
   */
  function mikhmon_revenue_forecast($API, $dayKey, $lookbackDays = 7, $monthlySales = null)
  {
    $dayKey    = mikhmon_normalize_sale_date($dayKey);
    $monthKey  = mikhmon_sale_month_key($dayKey);
    $dayNum    = mikhmon_sale_day_number($dayKey);

    // Determine total days in this month
    if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})$/', $dayKey, $m)) {
      $year  = (int) $m[3];
      $month = (int) date('n', strtotime($m[1] . ' 1 ' . $year));
      $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    } else {
      $daysInMonth = 30;
      $year  = (int) date('Y');
      $month = (int) date('n');
    }

    $daysRemaining = $daysInMonth - $dayNum + 1; // include today

    // Fetch this month's scripts and group revenue by day
    $scripts    = is_array($monthlySales) ? $monthlySales : mikhmon_fetch_sales_by_month($API, $monthKey);
    $dailyTotals = array(); // dayKey => total
    $monthTotal  = 0.0;

    foreach ($scripts as $script) {
      $sale  = mikhmon_parse_sale_script($script);
      if ($sale['month_key'] !== $monthKey) continue;
      $price = mikhmon_parse_money_amount($sale['price']);
      $monthTotal += $price;
      $dk = $sale['date'];
      if (!isset($dailyTotals[$dk])) $dailyTotals[$dk] = 0.0;
      $dailyTotals[$dk] += $price;
    }

    // Build the look-back window: last $lookbackDays calendar days up to today
    $windowRevenue = 0.0;
    $daySampled    = 0;
    for ($offset = 0; $offset < $lookbackDays; $offset++) {
      $ts   = mktime(0, 0, 0, $month, $dayNum - $offset, $year);
      $dk   = mikhmon_normalize_sale_date(date('Y-m-d', $ts));
      if (isset($dailyTotals[$dk])) {
        $windowRevenue += $dailyTotals[$dk];
        $daySampled++;
      }
    }

    $avgDaily = ($lookbackDays > 0) ? ($windowRevenue / $lookbackDays) : 0.0;

    // Projected total = already earned + avg_daily * remaining days
    // (daysRemaining-1 because today is already partially counted in month_so_far)
    $projected = $monthTotal + $avgDaily * max(0, $daysRemaining - 1);

    if ($daySampled >= 5) {
      $confidence = 'high';
    } elseif ($daySampled >= 2) {
      $confidence = 'medium';
    } else {
      $confidence = 'low';
    }

    return array(
      'days_sampled'   => $daySampled,
      'window_days'    => $lookbackDays,
      'avg_daily'      => $avgDaily,
      'days_remaining' => $daysRemaining,
      'month_so_far'   => $monthTotal,
      'projected'      => $projected,
      'confidence'     => $confidence,
    );
  }

  function mikhmon_income_counter_scheduler_source()
  {
    return mikhmon_ros_date_compat_block()
      . ':local gt 0;:local gc 0;:local mt 0;:local mc 0;:local dt 0;:local dc 0;'
      . ':local currentMonthKey ($month.$year);:local currentDayKey $dateKey;'
      . ':local gp "mikhmon-income-global";:local mp ("mikhmon-income-month-".$currentMonthKey);:local dp ("mikhmon-income-day-".$currentMonthKey."-".$day);'
      . ':foreach i in=[/system script find where comment=mikhmon] do={'
      . ':local n [/system script get $i name];'
      . ':local a [:find $n "-|-"];:if ([:typeof $a]!="nil") do={'
      . ':local ap ($a+3);:local b [:find $n "-|-" $ap];:if ([:typeof $b]!="nil") do={'
      . ':local bp ($b+3);:local c [:find $n "-|-" $bp];:if ([:typeof $c]!="nil") do={'
      . ':local cp ($c+3);:local e [:find $n "-|-" $cp];:if ([:typeof $e]!="nil") do={'
      . ':local p [:tonum [:pick $n $cp $e]];'
      . ':local saleDay [:pick $n 0 $a];:local saleMonth [:pick $saleDay 0 3];:local saleYear [:pick $saleDay 7 11];'
      . ':if ([:pick $saleDay 4 5] = "-") do={:set saleYear [:pick $saleDay 0 4];:local smm [:pick $saleDay 5 7];'
      . ':if ($smm="01") do={:set saleMonth "jan"};:if ($smm="02") do={:set saleMonth "feb"};'
      . ':if ($smm="03") do={:set saleMonth "mar"};:if ($smm="04") do={:set saleMonth "apr"};'
      . ':if ($smm="05") do={:set saleMonth "may"};:if ($smm="06") do={:set saleMonth "jun"};'
      . ':if ($smm="07") do={:set saleMonth "jul"};:if ($smm="08") do={:set saleMonth "aug"};'
      . ':if ($smm="09") do={:set saleMonth "sep"};:if ($smm="10") do={:set saleMonth "oct"};'
      . ':if ($smm="11") do={:set saleMonth "nov"};:if ($smm="12") do={:set saleMonth "dec"};'
      . ':set saleDay ($saleMonth . "/" . [:pick $saleDay 8 10] . "/" . $saleYear);};'
      . ':set gt ($gt+$p);:set gc ($gc+1);'
      . ':if (($saleMonth.$saleYear)=$currentMonthKey) do={:set mt ($mt+$p);:set mc ($mc+1)};'
      . ':if ($saleDay=$currentDayKey) do={:set dt ($dt+$p);:set dc ($dc+1)};'
      . '};};};};};'
      . ':foreach pair in={($gp."-total.txt=".$gt);($gp."-count.txt=".$gc);($mp."-total.txt=".$mt);($mp."-count.txt=".$mc);($dp."-total.txt=".$dt);($dp."-count.txt=".$dc)} do={'
      . ':local x [:find $pair "="];:local f [:pick $pair 0 $x];:local v [:pick $pair ($x+1) [:len $pair]];:local id [/file find where name=$f];'
      . ':if ([:len $id]=0) do={/file add name=$f contents=$v} else={/file set $id contents=$v};'
      . '};';
  }

  function mikhmon_ensure_income_counter_scheduler($API)
  {
    $name = 'mikhmon-income-cache';
    $source = mikhmon_income_counter_scheduler_source();
    $rows = $API->comm('/system/scheduler/print', array(
      '?name' => $name,
      '.proplist' => '.id,name,interval,on-event,disabled',
    ));
    if (is_array($rows) && !empty($rows)) {
      $disabled = isset($rows[0]['disabled']) ? strtolower((string) $rows[0]['disabled']) : '';
      if (isset($rows[0]['interval'], $rows[0]['on-event'])
          && $rows[0]['interval'] === '15m'
          && $rows[0]['on-event'] === $source
          && ($disabled === 'false' || $disabled === 'no')) {
        return true;
      }
      $result = $API->comm('/system/scheduler/set', array(
        '.id' => $rows[0]['.id'],
        'interval' => '15m',
        'comment' => 'mikhmon-income-cache',
        'on-event' => $source,
        'disabled' => 'no',
      ));
      return is_array($result);
    }

    $result = $API->comm('/system/scheduler/add', array(
      'name' => $name,
      'interval' => '15m',
      'start-time' => '00:00:05',
      'comment' => 'mikhmon-income-cache',
      'on-event' => $source,
      'disabled' => 'no',
    ));
    return is_array($result);
  }

  function mikhmon_unique_sale_scripts($scripts)
  {
    $out = array();
    $seen = array();
    if (!is_array($scripts)) {
      return $out;
    }

    foreach ($scripts as $script) {
      if (!is_array($script)) {
        continue;
      }
      $key = isset($script['.id']) ? $script['.id'] : (isset($script['name']) ? $script['name'] : serialize($script));
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $script;
    }

    return $out;
  }

  function mikhmon_ros_date_compat_block()
  {
    return ':local clockDate [ /system clock get date ];'
      . ':local year [ :pick $clockDate 7 11 ];'
      . ':local month [ :pick $clockDate 0 3 ];'
      . ':local day [ :pick $clockDate 4 6 ];'
      . ':local dateKey $clockDate;'
      . ':if ([:pick $clockDate 4 5] = "-") do={'
      . ':local yyyy [:pick $clockDate 0 4];'
      . ':local mm [:pick $clockDate 5 7];'
      . ':local dd [:pick $clockDate 8 10];'
      . ':set year $yyyy;'
      . ':set day $dd;'
      . ':if ($mm = "01") do={ :set month "jan";};'
      . ':if ($mm = "02") do={ :set month "feb";};'
      . ':if ($mm = "03") do={ :set month "mar";};'
      . ':if ($mm = "04") do={ :set month "apr";};'
      . ':if ($mm = "05") do={ :set month "may";};'
      . ':if ($mm = "06") do={ :set month "jun";};'
      . ':if ($mm = "07") do={ :set month "jul";};'
      . ':if ($mm = "08") do={ :set month "aug";};'
      . ':if ($mm = "09") do={ :set month "sep";};'
      . ':if ($mm = "10") do={ :set month "oct";};'
      . ':if ($mm = "11") do={ :set month "nov";};'
      . ':if ($mm = "12") do={ :set month "dec";};'
      . ':set dateKey ($month . "/" . $dd . "/" . $year);'
      . '};';
  }

  function mikhmon_build_record_script($price, $validity, $name)
  {
    return '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$dateKey-|-$time-|-$user-|-'
      . $price . '-|-$address-|-$mac-|-' . $validity . '-|-' . $name
      . '-|-$comment" owner="$month$year" source="$dateKey" comment=mikhmon';
  }

  function mikhmon_ros_expiry_comment_block()
  {
    return ':local expKey $exp;'
      . ':if ([:find $exp "-"] != nil) do={'
      . ':local ey [:pick $exp 0 4];'
      . ':local emm [:pick $exp 5 7];'
      . ':local edd [:pick $exp 8 10];'
      . ':local et [:pick $exp 11 19];'
      . ':local em "jan";'
      . ':if ($emm = "01") do={ :set em "jan";};'
      . ':if ($emm = "02") do={ :set em "feb";};'
      . ':if ($emm = "03") do={ :set em "mar";};'
      . ':if ($emm = "04") do={ :set em "apr";};'
      . ':if ($emm = "05") do={ :set em "may";};'
      . ':if ($emm = "06") do={ :set em "jun";};'
      . ':if ($emm = "07") do={ :set em "jul";};'
      . ':if ($emm = "08") do={ :set em "aug";};'
      . ':if ($emm = "09") do={ :set em "sep";};'
      . ':if ($emm = "10") do={ :set em "oct";};'
      . ':if ($emm = "11") do={ :set em "nov";};'
      . ':if ($emm = "12") do={ :set em "dec";};'
      . ':set expKey ($em . "/" . $edd . "/" . $ey . " " . $et);'
      . '};'
      . ':if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :set expKey ("$d$s$year $t");};'
      . ':if ($getxp = 8) do={ :set expKey ("$dateKey $exp");};'
      . '/ip hotspot user set comment="$expKey" [find where name="$user"];';
  }

  function mikhmon_build_user_expire_scheduler_script($expmode)
  {
    $expireAction = '/ip hotspot user remove [find where name=\\"" . $user . "\\"];';
    if ($expmode === 'ntf' || $expmode === 'ntfc') {
      $expireAction = '/ip hotspot user set limit-uptime=1s [find where name=\\"" . $user . "\\"];';
    }

    return ':local es [:find $expKey " "];'
      . ':if ($es != nil) do={'
      . ':local ed [:pick $expKey 0 $es];'
      . ':local ets ($es + 1);'
      . ':local et [:pick $expKey $ets [:len $expKey]];'
      . ':local en ("mikhmon-expire-" . $user);'
      . '/system scheduler remove [find where name=$en and comment="mikhmon-user-expire"];'
      . ':local edf $ed;'
      . ':if ([:pick $ed 3 4] = "/") do={'
      . ':local xm [:pick $ed 0 3];:local xd [:pick $ed 4 6];:local xy [:pick $ed 7 11];'
      . ':local xmm "01";'
      . ':if ($xm = "jan") do={:set xmm "01";};:if ($xm = "feb") do={:set xmm "02";};'
      . ':if ($xm = "mar") do={:set xmm "03";};:if ($xm = "apr") do={:set xmm "04";};'
      . ':if ($xm = "may") do={:set xmm "05";};:if ($xm = "jun") do={:set xmm "06";};'
      . ':if ($xm = "jul") do={:set xmm "07";};:if ($xm = "aug") do={:set xmm "08";};'
      . ':if ($xm = "sep") do={:set xmm "09";};:if ($xm = "oct") do={:set xmm "10";};'
      . ':if ($xm = "nov") do={:set xmm "11";};:if ($xm = "dec") do={:set xmm "12";};'
      . ':set edf ($xy . "-" . $xmm . "-" . $xd);'
      . '};'
      . ':local ev ("' . $expireAction . ' /ip hotspot active remove [find where user=\\"" . $user . "\\"]; /system scheduler remove [find where name=\\"" . $en . "\\" and comment=\\"mikhmon-user-expire\\"]");'
      . '/system scheduler add name=$en disabled=no start-date=$edf start-time=$et interval=0s comment="mikhmon-user-expire" on-event=$ev;'
      . '};';
  }

  function mikhmon_build_on_login_script($expmode, $price, $validity, $sprice, $lockStatus, $record, $lock)
  {
    $shouldRecordSale = mikhmon_parse_money_amount($price) > 0;
    $onlogin = ':put (",' . $expmode . ',' . $price . ',' . $validity . ',' . $sprice . ',,' . $lockStatus . ',"); '
      . '{:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; '
      . ':local en ("mikhmon-expire-" . $user); '
      . ':if ([:len [/sys sch find where name=$en and comment="mikhmon-user-expire"]] = 0) do={ '
      . mikhmon_ros_date_compat_block()
      . '/sys sch remove [find where name="$user" and comment="mikhmon-temp-expire"]; '
      . '/sys sch add name="$user" disabled=no start-date=$dateKey interval="' . $validity . '" comment="mikhmon-temp-expire"; '
      . ':delay 2s; '
      . ':local exp [ /sys sch get [ /sys sch find where name="$user" and comment="mikhmon-temp-expire" ] next-run]; '
      . ':local getxp [:len $exp]; '
      . mikhmon_ros_expiry_comment_block()
      . ':delay 1s; '
      . '/sys sch remove [find where name="$user" and comment="mikhmon-temp-expire"];'
      . mikhmon_build_user_expire_scheduler_script($expmode);

    if ($expmode === 'rem' || $expmode === 'ntf') {
      return $onlogin . ($shouldRecordSale ? $record : '') . $lock . '}}';
    }

    if ($expmode === 'remc' || $expmode === 'ntfc') {
      return $onlogin . $record . $lock . '}}';
    }

    if ($expmode === '0' && $price !== '') {
      return ':put (",,' . $price . ',,,noexp,' . $lockStatus . ',")' . $lock;
    }

    return '';
  }

  function mikhmon_upgrade_legacy_expiration_profiles($API)
  {
    $profiles = $API->comm('/ip/hotspot/user/profile/print', array(
      '.proplist' => '.id,name,on-login',
    ));
    if (!is_array($profiles)) {
      return 0;
    }

    $updated = 0;
    foreach ($profiles as $profile) {
      $profileId = isset($profile['.id']) ? $profile['.id'] : '';
      $profileName = isset($profile['name']) ? trim((string) $profile['name']) : '';
      $onLogin = isset($profile['on-login']) ? (string) $profile['on-login'] : '';
      if ($profileId === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $profileName)
          || (strpos($onLogin, 'mikhmon-user-expire') !== false
            && strpos($onLogin, ':if ([:pick $clockDate 4 5] = "-")') !== false
            && strpos($onLogin, '[/sys sch find where name=$en and comment="mikhmon-user-expire"]') !== false)) {
        continue;
      }

      $parts = explode(',', $onLogin);
      $expireMode = isset($parts[1]) ? trim($parts[1]) : '';
      $price = isset($parts[2]) ? trim($parts[2]) : '';
      $validity = mikhmon_normalize_routeros_duration(isset($parts[3]) ? $parts[3] : '');
      $sellingPrice = isset($parts[4]) ? trim($parts[4]) : '0';
      $lockStatus = isset($parts[6]) ? trim($parts[6]) : 'Disable';
      if (!in_array($expireMode, array('rem', 'ntf', 'remc', 'ntfc'), true) || $validity === '') {
        continue;
      }

      $record = mikhmon_build_record_script($price, $validity, $profileName);
      $lock = $lockStatus === 'Enable'
        ? '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]'
        : '';
      $newOnLogin = mikhmon_build_on_login_script(
        $expireMode,
        $price,
        $validity,
        $sellingPrice,
        $lockStatus,
        $record,
        $lock
      );
      $API->comm('/ip/hotspot/user/profile/set', array(
        '.id' => $profileId,
        'on-login' => $newOnLogin,
      ));

      $monitorMode = ($expireMode === 'ntf' || $expireMode === 'ntfc')
        ? 'set limit-uptime=1s'
        : 'remove';
      $monitor = mikhmon_build_expire_monitor_script($profileName, $monitorMode);
      $schedulers = $API->comm('/system/scheduler/print', array(
        '?name' => $profileName,
        '.proplist' => '.id,name',
      ));
      $schedulerPayload = array(
        'name' => $profileName,
        'start-time' => '00:00:05',
        'interval' => '00:00:30',
        'on-event' => $monitor,
        'disabled' => 'no',
        'comment' => 'Monitor Profile ' . $profileName,
      );
      if (is_array($schedulers) && !empty($schedulers[0]['.id'])) {
        $schedulerPayload['.id'] = $schedulers[0]['.id'];
        $API->comm('/system/scheduler/set', $schedulerPayload);
      } else {
        $API->comm('/system/scheduler/add', $schedulerPayload);
      }
      $updated++;
    }

    return $updated;
  }

  function mikhmon_ensure_expiration_profile_monitors($API)
  {
    $profiles = $API->comm('/ip/hotspot/user/profile/print', array(
      '.proplist' => '.id,name,on-login',
    ));
    if (!is_array($profiles)) {
      return 0;
    }

    $ensured = 0;
    foreach ($profiles as $profile) {
      $profileName = isset($profile['name']) ? trim((string) $profile['name']) : '';
      $onLogin = isset($profile['on-login']) ? (string) $profile['on-login'] : '';
      if ($profileName === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $profileName)) {
        continue;
      }

      $parts = explode(',', $onLogin);
      $expireMode = isset($parts[1]) ? trim($parts[1]) : '';
      $validity = mikhmon_normalize_routeros_duration(isset($parts[3]) ? $parts[3] : '');
      if (!in_array($expireMode, array('rem', 'ntf', 'remc', 'ntfc'), true) || $validity === '') {
        continue;
      }

      $monitorMode = ($expireMode === 'ntf' || $expireMode === 'ntfc')
        ? 'set limit-uptime=1s'
        : 'remove';
      $monitor = mikhmon_build_expire_monitor_script($profileName, $monitorMode);
      $schedulers = $API->comm('/system/scheduler/print', array(
        '?name' => $profileName,
        '.proplist' => '.id,name,interval,on-event,disabled,comment',
      ));
      $schedulerPayload = array(
        'name' => $profileName,
        'start-time' => '00:00:05',
        'interval' => '00:00:30',
        'on-event' => $monitor,
        'disabled' => 'no',
        'comment' => 'Monitor Profile ' . $profileName,
      );

      if (is_array($schedulers) && !empty($schedulers[0]['.id'])) {
        $disabled = isset($schedulers[0]['disabled']) ? strtolower((string) $schedulers[0]['disabled']) : '';
        if (isset($schedulers[0]['interval'], $schedulers[0]['on-event'], $schedulers[0]['comment'])
            && mikhmon_routeros_duration_seconds($schedulers[0]['interval']) === mikhmon_routeros_duration_seconds('00:00:30')
            && $schedulers[0]['on-event'] === $monitor
            && $schedulers[0]['comment'] === 'Monitor Profile ' . $profileName
            && ($disabled === 'false' || $disabled === 'no')) {
          continue;
        }
        $schedulerPayload['.id'] = $schedulers[0]['.id'];
        $API->comm('/system/scheduler/set', $schedulerPayload);
      } else {
        $API->comm('/system/scheduler/add', $schedulerPayload);
      }
      $ensured++;
    }

    return $ensured;
  }

  function mikhmon_hotspot_log_row($entry)
  {
    if (!is_array($entry)) {
      return null;
    }
    $message = isset($entry['message']) ? trim((string) $entry['message']) : '';
    if ($message === '') {
      return null;
    }

    if (substr($message, 0, 3) === '->:') {
      $message = trim(substr($message, 3));
    }

    $user = '';
    $detail = '';
    if (preg_match('/^(.+?\([^)]+\)):\s*(.*)$/', $message, $matches)) {
      $user = trim($matches[1]);
      $detail = trim($matches[2]);
    } elseif (preg_match('/^([^:]+):\s*(.*)$/', $message, $matches)) {
      $user = trim($matches[1]);
      $detail = trim($matches[2]);
    } else {
      $detail = $message;
    }

    return array(
      'time' => isset($entry['time']) ? (string) $entry['time'] : '',
      'user' => $user,
      'message' => trim(str_replace('trying to', '', $detail)),
    );
  }

  function mikhmon_build_expire_monitor_script($profileName, $mode)
  {
    return ':local date [ /system clock get date ]; :local time [ /system clock get time ]; '
      . ':local nowyear "";:local nowmm "";:local nowdays "";'
      . ':if ([:pick $date 4 5] = "-" and [:pick $date 7 8] = "-") do={:set nowyear [:pick $date 0 4];:set nowmm [:pick $date 5 7];:set nowdays [:pick $date 8 10];};'
      . ':if ([:pick $date 3 4] = "/" and [:pick $date 6 7] = "/") do={'
      . ':local nowmonth [:pick $date 0 3];:set nowdays [:pick $date 4 6];:set nowyear [:pick $date 7 11];'
      . ':if ($nowmonth = "jan") do={ :set nowmm "01";};'
      . ':if ($nowmonth = "feb") do={ :set nowmm "02";};'
      . ':if ($nowmonth = "mar") do={ :set nowmm "03";};'
      . ':if ($nowmonth = "apr") do={ :set nowmm "04";};'
      . ':if ($nowmonth = "may") do={ :set nowmm "05";};'
      . ':if ($nowmonth = "jun") do={ :set nowmm "06";};'
      . ':if ($nowmonth = "jul") do={ :set nowmm "07";};'
      . ':if ($nowmonth = "aug") do={ :set nowmm "08";};'
      . ':if ($nowmonth = "sep") do={ :set nowmm "09";};'
      . ':if ($nowmonth = "oct") do={ :set nowmm "10";};'
      . ':if ($nowmonth = "nov") do={ :set nowmm "11";};'
      . ':if ($nowmonth = "dec") do={ :set nowmm "12";};'
      . '};'
      . ':local nowdate [:tonum ("$nowyear$nowmm$nowdays")];'
      . ':local nowtime [:tonum ([:pick $time 0 2] . [:pick $time 3 5])];'
      . ':foreach i in [ /ip hotspot user find where profile="' . $profileName . '" ] do={ '
      . ':local comment [ /ip hotspot user get $i comment]; '
      . ':local name [ /ip hotspot user get $i name]; '
      . ':local gettime "";:local expyear "";:local expmm "";:local expdays "";'
      . ':local hasExp false;'
      . ':if ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/") do={'
      . ':local month [:pick $comment 0 3];:set expdays [:pick $comment 4 6];:set expyear [:pick $comment 7 11];:set gettime [:pick $comment 12 20];'
      . ':if ($month = "jan") do={ :set expmm "01";};'
      . ':if ($month = "feb") do={ :set expmm "02";};'
      . ':if ($month = "mar") do={ :set expmm "03";};'
      . ':if ($month = "apr") do={ :set expmm "04";};'
      . ':if ($month = "may") do={ :set expmm "05";};'
      . ':if ($month = "jun") do={ :set expmm "06";};'
      . ':if ($month = "jul") do={ :set expmm "07";};'
      . ':if ($month = "aug") do={ :set expmm "08";};'
      . ':if ($month = "sep") do={ :set expmm "09";};'
      . ':if ($month = "oct") do={ :set expmm "10";};'
      . ':if ($month = "nov") do={ :set expmm "11";};'
      . ':if ($month = "dec") do={ :set expmm "12";};'
      . ':set hasExp true;};'
      . ':if ([:pick $comment 4 5] = "-" and [:pick $comment 7 8] = "-") do={:set expyear [:pick $comment 0 4];:set expmm [:pick $comment 5 7];:set expdays [:pick $comment 8 10];:set gettime [:pick $comment 11 19]; :set hasExp true;};'
      . ':if ($hasExp = true) do={'
      . ':local expdate [:tonum ("$expyear$expmm$expdays")] ; :local exptime [:tonum ([:pick $gettime 0 2] . [:pick $gettime 3 5])] ; '
      . ':if ($expdate > 0) do={:if (($expdate < $nowdate) or ($expdate = $nowdate and $exptime <= $nowtime)) do={ /ip hotspot user ' . $mode . ' $i; /ip hotspot active remove [find where user=$name];}}'
      . '}'
      . '}';
  }

  function mikhmon_profile_validity_from_on_login($onLogin)
  {
    $parts = explode(',', (string) $onLogin);
    return trim(isset($parts[3]) ? $parts[3] : '');
  }

  function mikhmon_normalize_routeros_duration($duration)
  {
    $duration = trim((string) $duration);
    if ($duration === '' || $duration === '0') {
      return '';
    }

    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $duration)) {
      return $duration;
    }

    $duration = strtolower($duration);
    if (preg_match('/^(?:\d+w)?(?:\d+d)?(?:\d+h)?(?:\d+m)?(?:\d+s)?$/', $duration) && preg_match('/\d/', $duration)) {
      return $duration;
    }

    return '';
  }

  function mikhmon_routeros_quote($value)
  {
    $value = str_replace(array('\\', '"', '$'), array('\\\\', '\\"', '\\$'), (string) $value);
    return '"' . $value . '"';
  }

  function mikhmon_ip_binding_scheduler_name($mac)
  {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string) $mac));
    $safe = trim($safe, '-');
    if ($safe === '') {
      $safe = 'unknown';
    }

    return substr('mikhmon-ipbind-' . $safe, 0, 63);
  }

  function mikhmon_build_ip_binding_expire_script($mac, $address, $schedulerName)
  {
    $mac = trim((string) $mac);
    $address = trim((string) $address);
    $schedulerName = trim((string) $schedulerName);
    $findBinding = 'mac-address=' . mikhmon_routeros_quote($mac);
    if ($address !== '') {
      $findBinding .= ' and address=' . mikhmon_routeros_quote($address);
    }

    $script = '/ip hotspot ip-binding remove [find where ' . $findBinding . '];';
    $script .= '/ip hotspot active remove [find where mac-address=' . mikhmon_routeros_quote($mac) . '];';
    $script .= '/queue simple remove [find where name=' . mikhmon_routeros_quote($mac) . '];';
    if ($address !== '') {
      $script .= '/ip arp remove [find where address=' . mikhmon_routeros_quote($address) . '];';
      $script .= '/ip dhcp-server lease remove [find where address=' . mikhmon_routeros_quote($address) . '];';
    }
    $script .= '/system scheduler remove [find where name=' . mikhmon_routeros_quote($schedulerName) . ' and comment="mikhmon-ipbinding-expire"];';

    return $script;
  }

  function mikhmon_build_ip_binding_comment($profile, $duration)
  {
    $profile = trim((string) $profile);
    $duration = trim((string) $duration);
    $parts = array('mikhmon-ipbinding');
    if ($profile !== '') {
      $parts[] = 'profile=' . $profile;
    }
    if ($duration !== '') {
      $parts[] = 'validity=' . $duration;
    }

    return implode('|', $parts);
  }

  function mikhmon_routeros_response_error($response)
  {
    if (!is_array($response)) {
      return '';
    }

    foreach (array('!trap', '!fatal') as $key) {
      if (!isset($response[$key])) {
        continue;
      }

      $rows = is_array($response[$key]) ? $response[$key] : array($response[$key]);
      foreach ($rows as $row) {
        if (is_array($row) && isset($row['message'])) {
          return $row['message'];
        }
        if (is_string($row) && $row !== '') {
          return $row;
        }
      }

      return $key;
    }

    return '';
  }

  // Normalize a RouterOS count-only API result to an integer.
  // ROS 7 returns array [['ret'=>'N']], ROS 6 may return string 'N' or integer N.
  function mikhmon_count_only_result($result)
  {
    if (is_array($result)) {
      return (int)($result[0]['ret'] ?? 0);
    }
    return (int)$result;
  }

  function mikhmon_revenue_visibility_key($role)
  {
    $role = preg_replace('/[^a-z0-9_]/i', '', (string) $role);
    return 'mikhmon_' . strtolower($role) . '_revenue_visible';
  }

  function mikhmon_revenue_handle_toggle($role)
  {
    if (!isset($_GET['revenue'])) {
      return;
    }
    $value = strtolower((string) $_GET['revenue']);
    if ($value !== 'show' && $value !== 'hide') {
      return;
    }
    $_SESSION[mikhmon_revenue_visibility_key($role)] = ($value === 'show');
    $params = $_GET;
    unset($params['revenue']);
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    $query = http_build_query($params);
    header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
    exit;
  }

  function mikhmon_revenue_is_visible($role)
  {
    $key = mikhmon_revenue_visibility_key($role);
    return !isset($_SESSION[$key]) || (bool) $_SESSION[$key];
  }

  function mikhmon_revenue_money($visible, $amount, $currency, $cekindo)
  {
    if (!$visible) {
      return '----';
    }
    return mikhmon_format_money_amount($amount, $currency, $cekindo);
  }

  function mikhmon_revenue_toggle_url($visible)
  {
    $params = $_GET;
    $params['revenue'] = $visible ? 'hide' : 'show';
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return htmlspecialchars($path . '?' . http_build_query($params));
  }

  function mikhmon_revenue_toggle_button($visible)
  {
    $label = $visible ? 'Masquer les revenus' : 'Afficher les revenus';
    return '<a class="portal-nav-action portal-nav-revenue" href="' . mikhmon_revenue_toggle_url($visible) . '"><i class="fa fa-eye"></i><span>' . $label . '</span></a>';
  }
}
