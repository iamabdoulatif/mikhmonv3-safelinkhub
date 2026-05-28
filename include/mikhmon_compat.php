<?php
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
    return 20;
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

    return $matches[3] . '-' . $months[$month] . '-' . $matches[2];
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
    $comment = strtolower(trim((string) $comment));
    if ($comment === '' || !is_array($sellersData)) {
      return '';
    }

    foreach ($sellersData as $sellerKey => $sellerData) {
      $sellerKey = trim((string) $sellerKey);
      if ($sellerKey === '') {
        continue;
      }
      $normalizedSeller = strtolower($sellerKey);
      $suffix = '-' . $normalizedSeller;
      if ($comment === $normalizedSeller || substr($comment, -strlen($suffix)) === $suffix) {
        return $sellerKey;
      }
    }

    return '';
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

  function mikhmon_fetch_sales_by_day($API, $dayKey)
  {
    $rows = array();
    $sources = array_values(array_unique(array_filter(array(
      mikhmon_normalize_sale_date($dayKey),
      mikhmon_iso_date_from_day_key($dayKey),
    ))));

    foreach ($sources as $source) {
      $data = $API->comm('/system/script/print', array('?source' => $source));
      if (is_array($data)) {
        $rows = array_merge($rows, $data);
      }
    }

    return mikhmon_unique_sale_scripts($rows);
  }

  function mikhmon_fetch_sales_by_month($API, $monthKey)
  {
    $data = $API->comm('/system/script/print', array('?owner' => strtolower(trim((string) $monthKey))));
    if (!is_array($data)) {
      return array();
    }

    return mikhmon_filter_sale_scripts(mikhmon_unique_sale_scripts($data), '', $monthKey);
  }

  function mikhmon_income_summary_from_scripts($scripts, $dayKey, $monthKey)
  {
    $dayKey = mikhmon_normalize_sale_date($dayKey);
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
      $summary['month_count']++;
      $summary['month_total'] += $price;

      if ($sale['date'] === $dayKey) {
        $summary['today_count']++;
        $summary['today_total'] += $price;
      }
    }

    return $summary;
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
    return ':local date [ /system clock get date ];'
      . ':local year [ :pick $date 7 11 ];'
      . ':local month [ :pick $date 0 3 ];'
      . ':local dateKey $date;'
      . ':if ([:find $date "-"] != nil) do={'
      . ':local yyyy [:pick $date 0 4];'
      . ':local mm [:pick $date 5 7];'
      . ':local dd [:pick $date 8 10];'
      . ':set year $yyyy;'
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
      . ':local ucode [:pick $comment 0 2]; '
      . ':if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ '
      . mikhmon_ros_date_compat_block()
      . '/sys sch remove [find where name="$user" and comment="mikhmon-temp-expire"]; '
      . '/sys sch add name="$user" disabled=no start-date=$date interval="' . $validity . '" comment="mikhmon-temp-expire"; '
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
}
