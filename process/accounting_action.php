<?php
/*
 * accounting_action.php — Actions AJAX sur la comptabilité vendeurs
 *
 * Actions :
 *   delete_day_sales     — supprime les scripts de ventes d'une journée sur MikroTik
 *   delete_notification  — supprime une notification comptable par ID
 *   clear_notifications  — efface tout l'historique pour une session (ou tout)
 */
session_start();
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['mikhmon'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../include/accounting_notifications.php';

$action  = isset($_POST['action'])  ? trim($_POST['action'])  : '';
$session = preg_replace('/[^a-zA-Z0-9_-]/', '', isset($_POST['session']) ? trim($_POST['session']) : '');

/* ── Supprimer une notification de l'historique ──────────────────────────── */
if ($action === 'delete_notification') {
    $notifId = isset($_POST['notif_id']) ? trim($_POST['notif_id']) : '';
    if ($notifId === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_id']);
        exit;
    }
    $list   = mikhmon_accounting_notifications_load();
    $before = count($list);
    $list   = array_values(array_filter($list, function ($n) use ($notifId) {
        return ($n['id'] ?? '') !== $notifId;
    }));
    mikhmon_accounting_notifications_save($list);
    echo json_encode(['ok' => true, 'deleted' => $before - count($list)]);
    exit;
}

/* ── Vider l'historique (pour une session ou tout) ───────────────────────── */
if ($action === 'clear_notifications') {
    $list = mikhmon_accounting_notifications_load();
    if ($session !== '') {
        $list = array_values(array_filter($list, function ($n) use ($session) {
            return ($n['session'] ?? '') !== $session;
        }));
    } else {
        $list = [];
    }
    mikhmon_accounting_notifications_save($list);
    echo json_encode(['ok' => true, 'cleared' => true]);
    exit;
}

/* ── Supprimer les ventes d'un jour depuis MikroTik ─────────────────────── */
if ($action === 'delete_day_sales') {
    $isoDate  = isset($_POST['iso_date'])  ? trim($_POST['iso_date'])  : '';
    $monthKey = isset($_POST['month_key']) ? trim($_POST['month_key']) : '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_date']);
        exit;
    }
    if (!preg_match('/^[a-z]{3}\d{4}$/', $monthKey)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_month']);
        exit;
    }
    if ($session === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_session']);
        exit;
    }

    @include_once __DIR__ . '/../include/config.php';
    @include_once __DIR__ . '/../include/readcfg.php';
    @include_once __DIR__ . '/../lib/routeros_api.class.php';
    @include_once __DIR__ . '/../include/mikhmon_compat.php';

    if (empty($iphost) || empty($userhost) || empty($passwdhost)) {
        echo json_encode(['ok' => false, 'error' => 'no_session_config']);
        exit;
    }

    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
            echo json_encode(['ok' => false, 'error' => 'mikrotik_connect_failed']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    // Récupère tous les scripts du mois
    $scripts = $API->comm('/system/script/print', array('?owner' => $monthKey));
    if (!is_array($scripts)) $scripts = [];

    // Filtre ceux qui correspondent au jour demandé
    $toDelete = [];
    foreach (mikhmon_unique_sale_scripts($scripts) as $script) {
        $sale    = mikhmon_parse_sale_script($script);
        $saleIso = mikhmon_iso_date_from_day_key($sale['date']);
        if ($saleIso === $isoDate && isset($script['.id'])) {
            $toDelete[] = $script['.id'];
        }
    }

    $deleted = 0;
    $errors  = [];
    foreach ($toDelete as $id) {
        try {
            $API->comm('/system/script/remove', array('.id' => $id));
            $deleted++;
        } catch (Exception $e) {
            $errors[] = $id;
        }
    }

    $API->disconnect();
    echo json_encode([
        'ok'       => true,
        'deleted'  => $deleted,
        'errors'   => $errors,
        'iso_date' => $isoDate,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
