<?php

if (!function_exists('mikhmon_accounting_notification_file')) {
  function mikhmon_accounting_notification_file()
  {
    return dirname(__DIR__) . '/logs/accounting_notifications.json';
  }

  function mikhmon_accounting_notifications_load()
  {
    $file = mikhmon_accounting_notification_file();
    if (!is_file($file)) {
      return array();
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
      return array();
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
  }

  function mikhmon_accounting_notifications_save($notifications)
  {
    $file = mikhmon_accounting_notification_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
      mkdir($dir, 0775, true);
    }

    return file_put_contents($file, json_encode(array_values($notifications), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
  }

  function mikhmon_accounting_notification_text($sellerName, $fromIso, $toIso, $settlementTime, $nextFromIso = '', $nextToIso = '', $nextSettlementTime = '')
  {
    $sellerName = trim((string) $sellerName);
    if ($sellerName === '') {
      $sellerName = 'vendeur';
    }

    $settlementTime = mikhmon_accounting_settlement_time($settlementTime);
    $message = 'Bonjour ' . $sellerName . ', le compte de la période du ' . $fromIso . ' au ' . $toIso . ' sera fait à ' . $settlementTime . '.';

    if ($nextFromIso !== '' && $nextToIso !== '') {
      $nextSettlementTime = mikhmon_accounting_settlement_time($nextSettlementTime, $settlementTime);
      $message .= ' Le prochain compte sera du ' . $nextFromIso . ' au ' . $nextToIso . ' à ' . $nextSettlementTime . '.';
    }

    return $message;
  }

  function mikhmon_accounting_notification_targets($summary, $sellersData, $sellerFilter = '')
  {
    $sellerFilter = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $sellerFilter);
    if ($sellerFilter !== '' && isset($sellersData[$sellerFilter])) {
      return array($sellerFilter);
    }

    $targets = array();
    if (isset($summary['days']) && is_array($summary['days'])) {
      foreach ($summary['days'] as $day) {
        if (empty($day['sellers']) || !is_array($day['sellers'])) {
          continue;
        }
        foreach ($day['sellers'] as $sellerKey => $sellerRow) {
          if (isset($sellersData[$sellerKey])) {
            $targets[$sellerKey] = true;
          }
        }
      }
    }

    return array_keys($targets);
  }

  function mikhmon_accounting_publish_notifications($senderRole, $senderName, $session, $sellersData, $targetSellerKeys, $fromIso, $toIso, $settlementTime, $nextFromIso = '', $nextToIso = '', $nextSettlementTime = '')
  {
    $notifications = mikhmon_accounting_notifications_load();
    $createdAt = date('Y-m-d H:i:s');
    $count = 0;

    foreach ($targetSellerKeys as $sellerKey) {
      if (!isset($sellersData[$sellerKey])) {
        continue;
      }

      $sellerName = isset($sellersData[$sellerKey]['name']) ? $sellersData[$sellerKey]['name'] : $sellerKey;
      $notifications[] = array(
        'id' => sha1($createdAt . '|' . $session . '|' . $sellerKey . '|' . $fromIso . '|' . $toIso . '|' . $settlementTime),
        'type' => 'accounting',
        'session' => (string) $session,
        'seller' => (string) $sellerKey,
        'seller_name' => (string) $sellerName,
        'sender_role' => (string) $senderRole,
        'sender_name' => (string) $senderName,
        'from' => (string) $fromIso,
        'to' => (string) $toIso,
        'settlement_time' => mikhmon_accounting_settlement_time($settlementTime),
        'next_from' => (string) $nextFromIso,
        'next_to' => (string) $nextToIso,
        'next_settlement_time' => mikhmon_accounting_settlement_time($nextSettlementTime, $settlementTime),
        'message' => mikhmon_accounting_notification_text($sellerName, $fromIso, $toIso, $settlementTime, $nextFromIso, $nextToIso, $nextSettlementTime),
        'created_at' => $createdAt,
      );
      $count++;
    }

    if ($count > 0) {
      $notifications = array_slice($notifications, -200);
      mikhmon_accounting_notifications_save($notifications);
    }

    return $count;
  }

  function mikhmon_accounting_notifications_for_seller($sellerKey, $session, $limit = 5)
  {
    $sellerKey = (string) $sellerKey;
    $session = (string) $session;
    $limit = max(1, (int) $limit);
    $matches = array();

    foreach (array_reverse(mikhmon_accounting_notifications_load()) as $notification) {
      if (($notification['type'] ?? '') !== 'accounting') {
        continue;
      }
      if (($notification['seller'] ?? '') !== $sellerKey || ($notification['session'] ?? '') !== $session) {
        continue;
      }
      $matches[] = $notification;
      if (count($matches) >= $limit) {
        break;
      }
    }

    return $matches;
  }
}
