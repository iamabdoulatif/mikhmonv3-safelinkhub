<?php
/*
 * Portail Gérant - MIKHMON
 * Rôle intermédiaire : gestion des vendeurs, transfert de stock, comptabilité.
 * Hiérarchie : Admin > Gérant > Vendeur
 */
session_start();
error_reporting(0);
ob_start("ob_gzhandler");

$url    = $_SERVER['REQUEST_URI'];
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$idbl   = isset($_GET['idbl'])   ? $_GET['idbl']   : '';
$idhr   = isset($_GET['idhr'])   ? $_GET['idhr']   : '';
$managerAllowedActions = array('dashboard', 'overview', 'accounting', 'tickets', 'vendors', 'logout');
$idbls = array(1=>"jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec");
$idblf = array(1=>"Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec");

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include_once('./include/mikhmon_compat.php');
include('./include/lang.php');
include('./lang/' . $langid . '.php');
include('./include/quickbt.php');
include('./include/theme.php');
include('./settings/settheme.php');
include('./settings/setlang.php');
include('./include/sellers_config.php');
include_once('./include/seller_ticket_helper.php');
include_once('./include/accounting_notifications.php');
include_once('./include/accounting_expenses.php');
include('./include/managers_config.php');
include_once('./include/auth.php');
include_once('./include/csrf.php');
include_once('./include/transfer_log.php');

$overviewReportPeriod = isset($_GET['period']) ? strtolower(trim((string) $_GET['period'])) : 'month';
if (!in_array($overviewReportPeriod, array('week', 'month', 'year'), true)) {
    $overviewReportPeriod = 'month';
}
$overviewReportYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$overviewReportMonth = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$overviewReportWeek = isset($_GET['week']) ? (int) $_GET['week'] : (int) date('W');
if (preg_match('/^([a-z]{3})(\d{4})$/', strtolower($idbl), $overviewMonthMatch) && !isset($_GET['period'])) {
    $overviewMonths = array_flip(mikhmon_month_map());
    if (isset($overviewMonths[$overviewMonthMatch[1]])) {
        $overviewReportPeriod = 'month';
        $overviewReportMonth = (int) $overviewMonths[$overviewMonthMatch[1]];
        $overviewReportYear = (int) $overviewMonthMatch[2];
    }
}
$overviewReportBounds = mikhmon_sales_period_bounds($overviewReportPeriod, $overviewReportYear, $overviewReportMonth, $overviewReportWeek);
$overviewReportPeriod = $overviewReportBounds['period'];
$overviewReportYear = $overviewReportBounds['year'];
$overviewReportMonth = $overviewReportBounds['month'];
$overviewReportWeek = $overviewReportBounds['week'];
$overviewReportFromIso = $overviewReportBounds['from'];
$overviewReportToIso = $overviewReportBounds['to'];
$overviewReportLabel = $overviewReportBounds['label'];
$overviewReportMonthKey = $overviewReportBounds['month_key'];
$overviewReportPeriodLabel = 'Cette période';
if ($overviewReportPeriod === 'week') {
    $overviewReportPeriodLabel = 'Cette semaine';
} elseif ($overviewReportPeriod === 'month') {
    $overviewReportPeriodLabel = 'Ce mois';
} elseif ($overviewReportPeriod === 'year') {
    $overviewReportPeriodLabel = 'Cette année';
}

if (!function_exists('mikhmon_filter_session_sellers')) {
    function mikhmon_filter_session_sellers($sellersData, $sessionName, $includeHistorical = false) {
        $filtered = array();
        if (!is_array($sellersData)) {
            return $filtered;
        }
        foreach ($sellersData as $sellerKey => $sellerData) {
            if (!$includeHistorical && function_exists('mikhmon_seller_is_historical') && mikhmon_seller_is_historical($sellerData)) {
                continue;
            }
            $sellerSession = isset($sellerData['session']) ? trim((string)$sellerData['session']) : '';
            if ($sellerSession === '' || $sellerSession === $sessionName) {
                $filtered[$sellerKey] = $sellerData;
            }
        }
        return $filtered;
    }
}

if ($_SESSION['theme'] == "") {
    $theme      = $theme;
    $themecolor = $themecolor;
} else {
    $theme      = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
}

// ── Déconnexion ──────────────────────────────────────────────────────────────
if ($action === 'logout') {
    unset($_SESSION['manager_username'], $_SESSION['manager_name'], $_SESSION['manager_session'], $_SESSION[mikhmon_revenue_visibility_key('manager')]);
    ob_end_clean();
    header("Location: ./admin.php?id=login");
    exit;
}

// ── Traitement de la connexion gérant ────────────────────────────────────────
$login_error = '';
if (isset($_POST['manager_login'])) {
    $mu = trim($_POST['manager_user']);
    $mp = $_POST['manager_pass'];
    if (isset($managers_data[$mu]) && mikhmon_account_password_matches($mp, $managers_data[$mu]['password'])) {
        $_SESSION['manager_username'] = $mu;
        $_SESSION['manager_name']     = $managers_data[$mu]['name'];
        $_SESSION['manager_session']  = $managers_data[$mu]['session'];
        ob_end_clean();
        header("Location: ./manager.php?action=dashboard");
        exit;
    } else {
        $login_error = '<div style="padding:8px;border-radius:5px;margin-top:8px;" class="bg-danger"><i class="fa fa-ban"></i> '
            . (isset($_please_login) ? $_please_login : 'Invalid credentials') . '</div>';
    }
}

// ── Vérifier si le gérant est connecté ──────────────────────────────────────
$manager_logged_in = isset($_SESSION['manager_username'])
    && isset($managers_data[$_SESSION['manager_username']]);

if ($manager_logged_in) {
    mikhmon_revenue_handle_toggle('manager');
}
$managerRevenueVisible = mikhmon_revenue_is_visible('manager');

if ($manager_logged_in && !in_array($action, $managerAllowedActions, true)) {
    ob_end_clean();
    header("Location: ./manager.php?action=dashboard");
    exit;
}

// ── Si connecté : charger config routeur ────────────────────────────────────
$API        = null;
$identity   = '';
$getData    = array();
$TotalReg   = 0;
$sellerStats = array();   // stats de ventes par vendeur
$managerSellersData = array();
$allSellerStock = array(); // stock non utilisé par vendeur/profil
$globalStock = array();
$globalStockIds = array();
$allStockUsers  = array();
$manager_session_missing = false;
$manager_session_message = '';
$manager_router_connected = false;
$manager_connection_error = '';
$managerConnectedCount = 0;

if ($manager_logged_in) {
    $managerUsername      = $_SESSION['manager_username'];
    $managerName          = $_SESSION['manager_name'];
    $manager_session_name = $_SESSION['manager_session'];

    include('./include/config.php');
    $session = $manager_session_name;
    include('./include/readcfg.php');
    if (empty($mikhmon_router_session_valid)) {
        $manager_session_missing = true;
        $manager_session_message = 'La session routeur "' . $manager_session_name . '" est introuvable dans la configuration locale.';
    }

    $managerSellersData = mikhmon_filter_session_sellers($sellers_data, $manager_session_name);

    date_default_timezone_set('UTC');
    $managerShouldLoadRouterData = true;
    $managerShouldLoadFullTicketData = ($action !== 'dashboard');
    if (!$manager_session_missing && $managerShouldLoadRouterData) {
        $API = new RouterosAPI();
        $API->debug = false;
        mikhmon_configure_routeros_api($API);
    }

    if (!$manager_session_missing && $managerShouldLoadRouterData) {
        $manager_router_connected = $API->connect($iphost, $userhost, decrypt($passwdhost));
        if (!$manager_router_connected) {
            $manager_connection_error = 'Connexion impossible au routeur "' . $manager_session_name . '" (' . $iphost . ':8728). Vérifiez l’IP, le service API MikroTik et les identifiants.';
        }
    }

    if ($manager_router_connected) {
        $gettimezone = $API->comm("/system/clock/print");
        if (!empty($gettimezone[0]['time-zone-name'])) {
            date_default_timezone_set(mikhmon_safe_timezone($gettimezone[0]['time-zone-name']));
        }
        $gi = $API->comm("/system/identity/print");
        $identity = isset($gi[0]['name']) ? $gi[0]['name'] : '';
        $activeHotspotUsers = $API->comm("/ip/hotspot/active/print");
        $managerConnectedCount = is_array($activeHotspotUsers) ? count($activeHotspotUsers) : 0;

        // ── Récupérer toutes les ventes ──────────────────────────────────────
        $getSales = $API->comm("/system/script/print", array("?comment" => "mikhmon"));
        if (strlen($idhr) > 0) {
            $allSales = mikhmon_filter_sale_scripts($getSales, $idhr, '');
        } elseif ($action === 'overview') {
            $allSales = mikhmon_filter_sale_scripts_by_iso_range($getSales, $overviewReportFromIso, $overviewReportToIso);
        } elseif (strlen($idbl) > 0) {
            $allSales = mikhmon_filter_sale_scripts($getSales, '', $idbl);
        } else {
            $allSales = mikhmon_filter_sale_scripts($getSales, '', '');
        }

        // ── Stats par vendeur ────────────────────────────────────────────────
        $today_str = mikhmon_normalize_sale_date(date("Y-m-d"));
        foreach ($allSales as $sale) {
            $matchedSeller = mikhmon_comment_seller_key(isset($sale['comment']) ? $sale['comment'] : '', $managerSellersData);
            foreach ($managerSellersData as $sk => $sd) {
                if ($matchedSeller === $sk) {
                    if (!isset($sellerStats[$sk])) {
                        $sellerStats[$sk] = array('total' => 0, 'today' => 0, 'rev_total' => 0.0, 'rev_today' => 0.0, 'profiles' => array());
                    }
                    $price = mikhmon_parse_money_amount(isset($sale['price']) ? $sale['price'] : 0.0);
                    $prof  = isset($sale['profile']) ? $sale['profile'] : '—';
                    $sdate = mikhmon_normalize_sale_date(isset($sale['date']) ? $sale['date'] : '');
                    $sellerStats[$sk]['total']++;
                    $sellerStats[$sk]['rev_total'] += $price;
                    if ($sdate === $today_str) {
                        $sellerStats[$sk]['today']++;
                        $sellerStats[$sk]['rev_today'] += $price;
                    }
                    if (!isset($sellerStats[$sk]['profiles'][$prof])) {
                        $sellerStats[$sk]['profiles'][$prof] = array('total' => 0, 'today' => 0, 'price' => $price);
                    }
                    $sellerStats[$sk]['profiles'][$prof]['total']++;
                    if ($sdate === $today_str) $sellerStats[$sk]['profiles'][$prof]['today']++;
                    $getData[] = $sale;
                    break;
                }
            }
        }
        $TotalReg = count($getData);

        // ── Stock non utilisé par vendeur ────────────────────────────────────
        $unusedAll = $managerShouldLoadFullTicketData
            ? $API->comm("/ip/hotspot/user/print", array("?uptime" => "0s"))
            : array();
        if (is_array($unusedAll)) {
            foreach ($managerSellersData as $sk => $sd) {
                $allSellerStock[$sk] = array();
            }
            // Utilisateurs système à ignorer (pas de vrais tickets)
            $systemUserNames = array('default-trial', 'admin');
            foreach ($unusedAll as $u) {
                $uname    = isset($u['name']) ? trim($u['name']) : '';
                $prof     = isset($u['profile']) ? trim($u['profile']) : '';
                $comment  = isset($u['comment']) ? $u['comment'] : '';

                // Filtrer les utilisateurs système
                if (in_array(strtolower($uname), $systemUserNames, true)) continue;
                // Filtrer les entrées sans profil hotspot ou avec profil "default"
                if ($prof === '' || strtolower($prof) === 'default') continue;

                $matchedSeller = mikhmon_comment_seller_key($comment, $managerSellersData);
                if ($matchedSeller !== '') {
                    if (!isset($allSellerStock[$matchedSeller][$prof])) $allSellerStock[$matchedSeller][$prof] = 0;
                    $allSellerStock[$matchedSeller][$prof]++;
                } else {
                    if (!isset($globalStock[$prof])) $globalStock[$prof] = 0;
                    if (!isset($globalStockIds[$prof])) $globalStockIds[$prof] = array();
                    $globalStock[$prof]++;
                    $globalStockIds[$prof][] = $u['.id'];
                }
                $allStockUsers[] = $u;
            }
        }

        // ── Total par profil (toutes sources confondues) ─────────────────────
        $stockByProfile = array();
        foreach ($allSellerStock as $sk => $profs) {
            foreach ($profs as $prof => $qty) {
                if (!isset($stockByProfile[$prof])) $stockByProfile[$prof] = array('total' => 0, 'global' => 0, 'sellers' => array());
                $stockByProfile[$prof]['total'] += $qty;
                $stockByProfile[$prof]['sellers'][$sk] = $qty;
            }
        }
        foreach ($globalStock as $prof => $qty) {
            if (!isset($stockByProfile[$prof])) $stockByProfile[$prof] = array('total' => 0, 'global' => 0, 'sellers' => array());
            $stockByProfile[$prof]['total']  += $qty;
            $stockByProfile[$prof]['global'] += $qty;
        }
        ksort($stockByProfile, SORT_NATURAL);
    }
}

// ── Agrégation par profil (pivot vendeur→profil) ─────────────────────────────
$profileStats = array();
// Ventes par profil
foreach ($sellerStats as $sk => $ss) {
    foreach ($ss['profiles'] as $prof => $ps) {
        if (!isset($profileStats[$prof])) {
            $profileStats[$prof] = array(
                'total'     => 0,
                'today'     => 0,
                'rev_total' => 0.0,
                'rev_today' => 0.0,
                'price'     => $ps['price'],
                'vendors'   => array(),
            );
        }
        $profileStats[$prof]['total']     += $ps['total'];
        $profileStats[$prof]['today']     += $ps['today'];
        $profileStats[$prof]['rev_total'] += $ps['total'] * $ps['price'];
        $profileStats[$prof]['rev_today'] += $ps['today'] * $ps['price'];
        $profileStats[$prof]['vendors'][$sk] = array(
            'name'  => isset($managerSellersData[$sk]['name']) ? $managerSellersData[$sk]['name'] : $sk,
            'total' => $ps['total'],
            'today' => $ps['today'],
            'price' => $ps['price'],
            'stock' => isset($allSellerStock[$sk][$prof]) ? $allSellerStock[$sk][$prof] : 0,
        );
    }
}
// Ajouter stock des profils sans ventes (stock non nul mais 0 vente)
foreach ($allSellerStock as $sk => $profs) {
    foreach ($profs as $prof => $qty) {
        if (!isset($profileStats[$prof])) {
            $profileStats[$prof] = array('total'=>0,'today'=>0,'rev_total'=>0.0,'rev_today'=>0.0,'price'=>0,'vendors'=>array());
        }
        if (!isset($profileStats[$prof]['vendors'][$sk])) {
            $profileStats[$prof]['vendors'][$sk] = array(
                'name'  => isset($managerSellersData[$sk]['name']) ? $managerSellersData[$sk]['name'] : $sk,
                'total' => 0, 'today' => 0, 'price' => 0, 'stock' => $qty,
            );
        } else {
            $profileStats[$prof]['vendors'][$sk]['stock'] = $qty;
        }
    }
}
// Trier les profils par ordre naturel (Forfait 100, 200, 500…)
uksort($profileStats, 'strnatcasecmp');

// ── Résumé global du rôle gérant ────────────────────────────────────────────
$managerVendorCount = is_array($managerSellersData) ? count($managerSellersData) : 0;
$managerVendorsWithSales = 0;
$managerVendorsWithStock = 0;
$managerStockTotal = 0;
$managerTodayTickets = 0;
$managerTodayRevenue = 0.0;
$managerTodayCommission = 0.0;
$managerMonthTickets = 0;
$managerMonthRevenue = 0.0;
$managerMonthCommission = 0.0;

foreach ($managerSellersData as $sk => $sd) {
    $ss = isset($sellerStats[$sk]) ? $sellerStats[$sk] : array('total' => 0, 'today' => 0, 'rev_total' => 0.0, 'rev_today' => 0.0);
    $stockQty = isset($allSellerStock[$sk]) ? array_sum($allSellerStock[$sk]) : 0;
    $commRate = isset($sd['commission']) ? (int)$sd['commission'] : 0;

    if ($ss['total'] > 0) {
        $managerVendorsWithSales++;
    }
    if ($stockQty > 0) {
        $managerVendorsWithStock++;
    }

    $managerStockTotal += $stockQty;
    $managerTodayTickets += $ss['today'];
    $managerTodayRevenue += $ss['rev_today'];
    $managerMonthTickets += $ss['total'];
    $managerMonthRevenue += $ss['rev_total'];
    $managerTodayCommission += ($ss['rev_today'] * $commRate / 100);
    $managerMonthCommission += ($ss['rev_total'] * $commRate / 100);
}

// ── Gestion des vendeurs (add/del/change password) par le gérant ─────────────
$sellers_file = './include/sellers_config.php';
$msg_vendors  = '';

if ($manager_logged_in && $action === 'vendors') {
    if (isset($_POST['add_seller']) || isset($_POST['change_pass'])) csrf_guard();
    if (isset($_POST['add_seller'])) {
        $nw = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['new_user']));
        $np = trim($_POST['new_pass']);
        $nn = trim($_POST['new_name']);
        $ns = trim($_POST['new_session']);
        if ($nw == '' || $np == '' || $nn == '') {
            $msg_vendors = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . (isset($_required_fields_msg) ? $_required_fields_msg : 'All fields required.') . '</div>';
        } elseif (isset($sellers_data[$nw])) {
            $msg_vendors = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . (isset($_seller_exists) ? $_seller_exists : 'Already exists.') . '</div>';
        } else {
            $ep = encrypt($np);
            file_put_contents($sellers_file, mikhmon_php_assignment_line('sellers_data', $nw, array(
                'password' => $ep,
                'name' => $nn,
                'session' => $ns,
                'commission' => 10,
            )), FILE_APPEND | LOCK_EX);
            $msg_vendors = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . (isset($_seller) ? $_seller : 'Vendor') . ' <b>' . $nw . '</b> OK.</div>';
            include($sellers_file);
            $managerSellersData = mikhmon_filter_session_sellers($sellers_data, $manager_session_name);
        }
    }
    if (isset($_POST['change_pass'])) {
        $cu = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['cp_user']));
        $cp = trim($_POST['cp_pass']);
        if ($cu != '' && $cp != '' && isset($sellers_data[$cu])) {
            $en        = encrypt($cp);
            $curr_comm = isset($sellers_data[$cu]['commission']) ? (int)$sellers_data[$cu]['commission'] : 10;
            $fc  = file($sellers_file);
            $f   = fopen($sellers_file, 'w');
            foreach ($fc as $ln) {
                if (strpos($ln, '$sellers_data[\'' . $cu . '\']') !== false) {
                    $ln = mikhmon_php_assignment_line('sellers_data', $cu, array(
                        'password' => $en,
                        'name' => $sellers_data[$cu]['name'],
                        'session' => $sellers_data[$cu]['session'],
                        'commission' => $curr_comm,
                    ));
                }
                fputs($f, $ln);
            }
            fclose($f);
            $msg_vendors = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . (isset($_password) ? $_password : 'Password') . ' <b>' . htmlspecialchars($cu) . '</b> OK.</div>';
            include($sellers_file);
            $managerSellersData = mikhmon_filter_session_sellers($sellers_data, $manager_session_name);
        }
    }
}

// ── Transfert de stock ────────────────────────────────────────────────────────
$transfer_msg   = '';
$transfer_error = '';

if ($manager_logged_in && $action === 'transfer' && isset($_POST['do_transfer'])) {
    csrf_guard();
    $src   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['src_seller']));
    $dst   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['dst_seller']));
    $tprof = trim($_POST['transfer_profile']);
    $tqty  = max(1, (int)$_POST['transfer_qty']);

    if (!isset($managerSellersData[$src]) || !isset($managerSellersData[$dst]) || $src === $dst) {
        $transfer_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select valid vendors.';
    } elseif ($tprof === '') {
        $transfer_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile.';
    } elseif (!isset($allSellerStock[$src][$tprof]) || $allSellerStock[$src][$tprof] < $tqty) {
        $transfer_error = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock.';
    } else {
        // Filtrer les tickets du vendeur source pour ce profil
        $profileUsers = array();
        foreach ($allStockUsers as $u) {
            if (!isset($u['profile']) || $u['profile'] !== $tprof) continue;
            if (mikhmon_comment_seller_key(isset($u['comment']) ? $u['comment'] : '', $managerSellersData) === $src) {
                $profileUsers[] = $u;
            }
        }
        $toTransfer = mikhmon_select_sequential($profileUsers, $tqty);
        if ($toTransfer === false) {
            $fmt = isset($_transfer_no_consecutive_src)
                ? $_transfer_no_consecutive_src
                : 'Transfer failed: no consecutive sequence of %d ticket(s) for profile %s (%d in stock with %s, no continuous sequence).';
            $transfer_error = sprintf($fmt, $tqty, htmlspecialchars($tprof), count($profileUsers), htmlspecialchars($managerSellersData[$src]['name']));
        } else {
            $done = 0;
            foreach ($toTransfer as $u) {
                $API->comm("/ip/hotspot/user/set", array(
                    ".id"     => $u['.id'],
                    "comment" => mikhmon_comment_assign_seller(isset($u['comment']) ? $u['comment'] : '', $dst, $managerSellersData)
                ));
                $done++;
            }
            if ($done > 0) {
                $allSellerStock[$src][$tprof] -= $done;
                if ($allSellerStock[$src][$tprof] <= 0) unset($allSellerStock[$src][$tprof]);
                if (!isset($allSellerStock[$dst][$tprof])) $allSellerStock[$dst][$tprof] = 0;
                $allSellerStock[$dst][$tprof] += $done;
            }
            $transfer_msg = $done . ' ' . (isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to')
                . ' <b>' . htmlspecialchars($managerSellersData[$dst]['name']) . '</b>';
            if ($done > 0) {
                log_transfer(
                    $src, $managerSellersData[$src]['name'],
                    $dst, $managerSellersData[$dst]['name'],
                    $tprof, $done,
                    'manager', $managerUsername
                );
            }
        }
    }
}

if ($manager_logged_in && $action === 'transfer' && isset($_POST['do_global_transfer'])) {
    csrf_guard();
    $dst   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['global_dst_seller']));
    $tprof = trim($_POST['global_transfer_profile']);
    $tqty  = max(1, (int)$_POST['global_transfer_qty']);

    if (!isset($managerSellersData[$dst])) {
        $transfer_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select a valid vendor.';
    } elseif ($tprof === '') {
        $transfer_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile.';
    } elseif (!isset($globalStock[$tprof]) || $globalStock[$tprof] < $tqty) {
        $transfer_error = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock.';
    } else {
        // Collecter les tickets du stock global (sans vendeur assigné) pour ce profil
        $profileUsers = array();
        foreach ($allStockUsers as $u) {
            $prof = isset($u['profile']) ? $u['profile'] : '';
            if ($prof !== $tprof) continue;
            $sellerKey = mikhmon_comment_seller_key(isset($u['comment']) ? $u['comment'] : '', $managerSellersData);
            if ($sellerKey !== '') continue;
            $profileUsers[] = $u;
        }
        $toTransfer = mikhmon_select_sequential($profileUsers, $tqty);
        if ($toTransfer === false) {
            $fmt = isset($_transfer_no_consecutive)
                ? $_transfer_no_consecutive
                : 'Transfer failed: no consecutive sequence of %d ticket(s) for profile %s (%d available, no continuous sequence).';
            $transfer_error = sprintf($fmt, $tqty, htmlspecialchars($tprof), count($profileUsers));
        } else {
            $done = 0;
            foreach ($toTransfer as $u) {
                $API->comm("/ip/hotspot/user/set", array(
                    ".id"     => $u['.id'],
                    "comment" => mikhmon_comment_assign_seller(isset($u['comment']) ? $u['comment'] : '', $dst, $managerSellersData)
                ));
                $done++;
            }
            if ($done > 0) {
                $globalStock[$tprof] -= $done;
                if ($globalStock[$tprof] <= 0) unset($globalStock[$tprof]);
                if (isset($globalStockIds[$tprof])) {
                    $globalStockIds[$tprof] = array_slice($globalStockIds[$tprof], $done);
                    if (empty($globalStockIds[$tprof])) unset($globalStockIds[$tprof]);
                }
                if (!isset($allSellerStock[$dst][$tprof])) $allSellerStock[$dst][$tprof] = 0;
                $allSellerStock[$dst][$tprof] += $done;
            }
            $transfer_msg = $done . ' ' . (isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to')
                . ' <b>' . htmlspecialchars($managerSellersData[$dst]['name']) . '</b>';
            if ($done > 0) {
                log_transfer('(global)', 'Stock gérant', $dst, $managerSellersData[$dst]['name'], $tprof, $done, 'manager', $managerUsername);
            }
        }
    }
}

// ── Sessions disponibles ─────────────────────────────────────────────────────
$available_sessions = array();
foreach (file('./include/config.php') as $ln) {
    $sn = explode("'", $ln)[1];
    if ($sn != '' && $sn != 'mikhmon') $available_sessions[] = $sn;
}
$available_sessions = array_unique($available_sessions);
$accountingMonthKey = strlen($idbl) > 0 ? strtolower($idbl) : strtolower(date("M")) . date("Y");
$accountingBounds = mikhmon_accounting_month_bounds($accountingMonthKey);
$accountingFrom = mikhmon_accounting_iso_date(isset($_GET['from']) ? $_GET['from'] : '', $accountingBounds['from']);
$accountingTo = mikhmon_accounting_iso_date(isset($_GET['to']) ? $_GET['to'] : '', $accountingBounds['to']);
if ($accountingFrom > $accountingTo) {
    $accountingTmp = $accountingFrom;
    $accountingFrom = $accountingTo;
    $accountingTo = $accountingTmp;
}
$accountingSeller = preg_replace('/[^a-zA-Z0-9_]/', '', isset($_GET['seller']) ? $_GET['seller'] : '');
if ($accountingSeller !== '' && !isset($managerSellersData[$accountingSeller])) {
    $accountingSeller = '';
}
$accountingSettlementTime = mikhmon_accounting_settlement_time(isset($_GET['settled_at']) ? $_GET['settled_at'] : (isset($_POST['settled_at']) ? $_POST['settled_at'] : ''), date('H:i:s'));
$accountingNextSettlementTime = mikhmon_accounting_settlement_time(isset($_GET['next_settled_at']) ? $_GET['next_settled_at'] : (isset($_POST['next_settled_at']) ? $_POST['next_settled_at'] : ''), $accountingSettlementTime);
$accountingSummary = mikhmon_accounting_period_summary($getData, $managerSellersData, $accountingFrom, $accountingTo, $accountingSeller, $accountingSettlementTime, $accountingNextSettlementTime);
$accountingNextFrom = '';
$accountingNextTo = $accountingBounds['to'];
if ($accountingTo !== '') {
    $accountingNextDate = new DateTime($accountingTo);
    $accountingNextDate->modify('+1 day');
    $nextIso = $accountingNextDate->format('Y-m-d');
    if ($nextIso <= $accountingBounds['to']) {
        $accountingNextFrom = $nextIso;
    }
}
$managerHomeUrl = './manager.php?action=dashboard';
$managerOverviewUrl = './manager.php?action=overview&idbl=' . strtolower(date("M")) . date("Y");
$managerAccountingUrl = './manager.php?action=accounting&idbl=' . urlencode($accountingMonthKey);
$accountingNoticeMsg = '';
$accountingNoticeError = '';
$accountingExpenseMsg = '';
$accountingExpenseError = '';
$accountingExpensesSession = isset($manager_session_name) ? $manager_session_name : (isset($session) ? $session : '');
$accountingAllExpenses = mikhmon_accounting_expenses_load();
if ($manager_logged_in && $action === 'accounting' && isset($_POST['add_accounting_expense'])) {
    csrf_guard();
    $expenseDate = isset($_POST['expense_date']) ? $_POST['expense_date'] : $accountingTo;
    $expenseType = isset($_POST['expense_type']) ? $_POST['expense_type'] : 'Autre';
    $expenseLabel = isset($_POST['expense_label']) ? $_POST['expense_label'] : '';
    $expenseAmount = isset($_POST['expense_amount']) ? $_POST['expense_amount'] : 0;
    $expenseRecord = mikhmon_accounting_expense_record($accountingExpensesSession, $expenseDate, $expenseType, $expenseLabel, $expenseAmount);
    if ($expenseRecord['amount'] <= 0) {
        $accountingExpenseError = 'Le montant de la dépense doit être supérieur à zéro.';
    } else {
        $accountingAllExpenses[] = $expenseRecord;
        if (mikhmon_accounting_expenses_save($accountingAllExpenses)) {
            $accountingExpenseMsg = 'Dépense ajoutée au compte du gérant.';
        } else {
            $accountingExpenseError = 'Impossible d’enregistrer la dépense.';
        }
    }
}
$accountingPeriodExpenses = mikhmon_accounting_expenses_for_period($accountingAllExpenses, $accountingExpensesSession, $accountingFrom, $accountingTo);
$accountingExpensesTotal = mikhmon_accounting_expenses_total($accountingPeriodExpenses);
$accountingNoticeTargets = mikhmon_accounting_notification_targets($accountingSummary, $managerSellersData, $accountingSeller);
$accountingNoticeTotals = mikhmon_accounting_notice_totals_for_targets($accountingSummary, $accountingNoticeTargets, $currency, $cekindo);
if ($manager_logged_in && $action === 'accounting' && isset($_POST['send_accounting_notice'])) {
    csrf_guard();
    $sentCount = mikhmon_accounting_publish_notifications(
        'manager',
        isset($managerName) ? $managerName : 'Gérant',
        isset($manager_session_name) ? $manager_session_name : $session,
        $managerSellersData,
        $accountingNoticeTargets,
        $accountingFrom,
        $accountingTo,
        $accountingSettlementTime,
        $accountingNextFrom,
        $accountingNextTo,
        $accountingNextSettlementTime,
        $accountingNoticeTotals
    );
    if ($sentCount > 0) {
        $accountingNoticeMsg = $sentCount . ' notification(s) envoyée(s) aux vendeurs concernés.';
    } else {
        $accountingNoticeError = 'Aucun vendeur concerné par cette période.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>MIKHMON – <?= isset($_manager_portal) ? $_manager_portal : 'Manager Portal' ?></title>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= $themecolor ?>">
  <link rel="stylesheet" type="text/css" href="css/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet" href="css/mikhmon-ui.<?= $theme ?>.min.css">
  <link rel="icon" href="./img/favicon.png">
  <link href="css/pace.<?= $theme ?>.css" rel="stylesheet">
  <script src="js/jquery.min.js"></script>
  <script src="js/pace.min.js"></script>
  <style>
/* ── Manager portal styles ──────────────────────────────────────── */
.manager-portal {
  color:#e5e7eb;
}
.mgr-badge {
  display:inline-block; padding:2px 10px; border-radius:12px;
  background:#f3e8fd; color:#8e44ad; font-size:12px; font-weight:bold;
}
.mgr-session-badge {
  display:inline-block;
  background:#e8f0fe;
  color:#1a6fa0 !important;
  border-radius:12px;
  padding:2px 10px;
  font-size:12px;
  font-weight:bold;
}
.seller-card {
  border:1px solid #e0e0e0; border-radius:8px;
  padding:14px 16px; margin-bottom:12px;
  background:#fff; transition: box-shadow .2s;
}
.seller-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.1); }
.seller-card h4 { margin:0 0 8px; font-size:15px; }
.stat-row { display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; }
.stat-pill {
  background:#f5f5f5; border-radius:20px;
  padding:4px 12px; font-size:13px;
  display:inline-flex; align-items:center; gap:5px;
}
.stat-pill.today { background:#e8f5e9; color:#2e7d32; }
.stat-pill.month { background:#e3f2fd; color:#1565c0; }
.stat-pill.stock { background:#fce4ec; color:#c62828; }
/* Transfer grid */
.transfer-form-grid {
  display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;
}
.transfer-form-group { display:flex; flex-direction:column; gap:5px; }
.transfer-label { font-weight:bold; font-size:13px; color:#555; }
.transfer-select { width:100%; padding:8px 10px; border-radius:5px; border:1px solid #ccc; font-size:14px; box-sizing:border-box; }
.btn-transfer { display:block; width:100%; max-width:220px; padding:10px 0; background:#8e44ad; color:#fff; border:none; border-radius:5px; font-size:15px; font-weight:bold; cursor:pointer; transition:background .2s; }
.btn-transfer:hover { background:#7d3c98; }
.admin-transfer-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.admin-transfer-group { display:flex; flex-direction:column; gap:4px; }
.mgr-sidenav-badge {
  float:right; background:#8e44ad; color:#fff;
  border-radius:10px; padding:1px 7px; font-size:11px;
}
/* Table responsive */
.table-responsive { overflow-x:auto; -webkit-overflow-scrolling:touch; }
/* Accounting summary */
.acct-total-row { background:#5b2c8d; color:#fff !important; font-weight:bold; }
.acct-total-row td {
  background:#5b2c8d !important;
  color:#fff !important;
}
.accounting-settlement-chip {
  display:inline-flex;
  align-items:center;
  gap:5px;
  padding:3px 9px;
  border-radius:14px;
  background:#edf2f7;
  color:#243447;
  font-size:12px;
  font-weight:bold;
  white-space:nowrap;
}
.accounting-mobile-settlement {
  display:none;
  margin-top:6px;
}
.accounting-notice-box {
  border:1px solid #d7deea;
  border-left:4px solid #34495e;
  border-radius:8px;
  background:#f8fafc;
  padding:14px 16px;
  margin-bottom:16px;
}
.accounting-notice-preview {
  background:#fff;
  border:1px solid #e2e8f0;
  border-radius:6px;
  padding:10px 12px;
  color:#243447;
  line-height:1.45;
  margin:10px 0;
}
.mgr-expense-box {
  border:1px solid #d7deea;
  border-left:4px solid #c0392b;
  border-radius:8px;
  background:#fffafa;
  padding:14px 16px;
  margin-bottom:16px;
}
.mgr-expense-form {
  display:grid;
  grid-template-columns:repeat(4, minmax(0, 1fr));
  gap:10px;
  align-items:end;
  margin:12px 0;
}
.mgr-expense-form .form-control {
  width:100%;
  min-width:0;
  box-sizing:border-box;
}
.mgr-expense-list {
  margin-bottom:0;
}
.mgr-expense-list th,
.mgr-expense-list td {
  vertical-align:middle !important;
}
.mgr-month-filter {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.mgr-month-filter .btn {
  background:#f4f7fb;
  border:1px solid #d7deea;
  color:#243447 !important;
}
.mgr-month-filter .btn.bg-primary {
  border-color:#2980b9;
  color:#fff !important;
}
.mgr-quick-actions {
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.mgr-quick-actions .btn {
  flex:1 1 180px;
  text-align:center;
}
.mgr-action-grid {
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:12px;
  align-items:stretch;
}
.mgr-action-card {
  min-height:76px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:14px 16px !important;
  border-radius:6px;
  color:#fff !important;
  font-weight:bold;
  text-align:center;
  line-height:1.25;
  box-sizing:border-box;
  white-space:normal;
}
.mgr-action-card:hover {
  filter:brightness(1.05);
  text-decoration:none;
}
.mgr-ticket-links {
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:12px;
  margin:0 auto 18px;
  max-width:1040px;
}
.mgr-ticket-link-card {
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:58px;
  padding:12px 14px;
  border-radius:6px;
  font-weight:bold;
  color:#fff !important;
  text-align:center;
  box-sizing:border-box;
}
.mgr-light-profile-card {
  background:#fff;
  border:1px solid #e0e0e0;
  border-radius:10px;
  margin-bottom:16px;
  overflow:hidden;
  box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.mgr-light-profile-card .table {
  margin-bottom:0;
  font-size:13px;
}
.mgr-light-profile-card .table thead th {
  background:#f4f7fb !important;
  color:#243447 !important;
}
.mgr-light-profile-card .table tbody td {
  background:#fff !important;
  color:#1f2d3d !important;
}
.mgr-light-profile-card .table code {
  color:#5b6472 !important;
}
.mgr-profile-total-row td {
  background:#5b2c8d !important;
  color:#fff !important;
  font-weight:bold;
}
.mgr-soft-note {
  color:#6b7280 !important;
}
@media (max-width:1100px) and (min-width:751px) {
  .mgr-action-grid,
  .mgr-ticket-links {
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
}
/* Responsive (750px = MIKHMON breakpoint) */
@media (max-width:750px) {
  .transfer-form-grid, .admin-transfer-grid { grid-template-columns:1fr; }
  .btn-transfer { max-width:100%; }
  .stat-row { flex-direction:column; }
  /* Profile card header : stack label/stats on mobile */
  .prof-card-header { flex-direction:column !important; align-items:flex-start !important; }
  .prof-card-stats  { flex-direction:column; gap:6px !important; }
  /* Global summary cards */
  .mgr-summary-cards > div { min-width:100% !important; flex:1 1 100% !important; }
  .mgr-quick-actions .btn { flex:1 1 100%; }
  .mgr-action-grid { grid-template-columns:1fr; }
  .mgr-ticket-links { grid-template-columns:1fr; }
  .mgr-month-filter .btn { flex:1 1 calc(33.333% - 8px); text-align:center; }
  .mgr-light-profile-card table { min-width:620px; }
  .accounting-responsive-table {
    min-width:0 !important;
    border:0 !important;
  }
  .accounting-responsive-table thead {
    display:none;
  }
  .accounting-responsive-table,
  .accounting-responsive-table tbody,
  .accounting-responsive-table tfoot,
  .accounting-responsive-table tr,
  .accounting-responsive-table td {
    display:block;
    width:100%;
    box-sizing:border-box;
  }
  .accounting-responsive-table tr {
    margin-bottom:10px;
    border:1px solid #d7deea;
    border-radius:8px;
    overflow:hidden;
    background:#fff;
  }
  .accounting-responsive-table td {
    display:flex;
    justify-content:space-between;
    gap:14px;
    text-align:right !important;
    padding:9px 12px !important;
    border-left:0 !important;
    border-right:0 !important;
  }
  .accounting-responsive-table td:before {
    content:attr(data-label);
    flex:0 0 42%;
    color:#64748b;
    font-weight:bold;
    text-align:left;
  }
  .accounting-responsive-table .accounting-seller-cell {
    display:block;
    text-align:left !important;
  }
  .accounting-responsive-table .accounting-seller-cell:before {
    content:'';
    display:none;
  }
  .accounting-responsive-table .accounting-time-cell {
    display:none;
  }
  .accounting-mobile-settlement {
    display:inline-flex;
  }
  .accounting-responsive-table tfoot tr {
    background:#5b2c8d;
  }
  .accounting-responsive-table tfoot td {
    background:#5b2c8d !important;
    color:#fff !important;
  }
  .accounting-responsive-table tfoot td:before {
    color:rgba(255,255,255,.82);
  }
  #navbar {
    height:auto !important;
    padding-bottom:8px;
  }
  #navbar .navbar-left,
  #navbar .navbar-right {
    display:flex;
    width:100%;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
  }
  #navbar .navbar-right {
    justify-content:flex-start;
    padding-left:14px;
  }
  #cpage {
    white-space:normal;
    line-height:1.3;
    font-size:15px;
  }
  }
  </style>
  <link rel="stylesheet" href="css/mikhmon-responsive.css">
  <link rel="stylesheet" href="css/mikhmon-portal.css">
</head>
<body class="<?= $manager_logged_in ? 'manager-portal' : 'auth-screen' ?>">
<div class="wrapper">

<?php if (!$manager_logged_in): ?>
<!-- ══════════════════════════ PAGE DE CONNEXION ═══════════════════════════ -->
<div class="portal-auth-wrap login-wrap-sm">
  <div class="login-card card portal-auth-card portal-auth-card-sm login-card-sm">
    <div class="card-header text-center">
      <h3><?= isset($_please_login) ? $_please_login : 'Veuillez vous connecter' ?></h3>
    </div>
    <div class="card-body login-card-body">
      <div class="login-logo">
        <img src="img/favicon.png" alt="MIKHMON Logo">
        <span>MIKHMON <small class="login-logo-subtitle">BY SafeLink Africa</small></span>
        <div class="login-logo-contact">+2250709100552</div>
      </div>
      <div class="text-center login-role-row">
        <span class="role-badge badge-manager">
          <i class="fa fa-briefcase"></i> <?= isset($_manager) ? $_manager : 'Gérant' ?>
        </span>
      </div>
      <form autocomplete="off" action="" method="post">
        <?= csrf_field() ?>
        <input class="login-field form-control" type="text" name="manager_user"
               placeholder="<?= isset($_seller_id) ? $_seller_id : 'Identifiant' ?>" required autofocus>
        <input class="login-field form-control" type="password" name="manager_pass"
               placeholder="<?= isset($_password) ? $_password : 'Mot de passe' ?>" required>
        <input class="login-submit btn-manager" type="submit" name="manager_login"
               value="<?= isset($_manager_login_title) ? $_manager_login_title : 'Connexion gérant' ?>">
        <?= $login_error ?>
      </form>
      <div class="portal-auth-footer-link">
        <a href="./admin.php?id=login">← <?= isset($_please_login) ? $_please_login : 'Connexion' ?></a>
      </div>
    </div>
    <div class="card-footer login-footer">
      <img src="img/safelink-africa.png" alt="SafeLink Africa">
    </div>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════════════ PORTAIL GÉRANT ════════════════════════════ -->

<!-- Navbar -->
<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="text-center" href="<?= $managerHomeUrl ?>">MIKHMON</a>
    <a id="openNav" class="navbar-hover" href="javascript:void(0)" title="Menu"><i class="fa fa-bars"></i></a>
    <a id="closeNav" class="navbar-hover" href="javascript:void(0)" title="Fermer"><i class="fa fa-bars"></i></a>
    <a id="cpage" class="navbar-left" href="javascript:void(0)">
      <i class="fa fa-<?= ($action === 'accounting') ? 'calculator' : (($action === 'transfer') ? 'exchange' : (($action === 'vendors') ? 'users' : (($action === 'logs') ? 'history' : 'briefcase'))) ?>"></i>
      <?= ($action === 'dashboard') ? 'Accueil gérant' : ((isset($_manager_portal) ? $_manager_portal : 'Manager') . ' — ' . htmlspecialchars($managerName)) ?>
    </a>
  </div>
  <div class="navbar-right portal-nav-actions">
    <?= mikhmon_revenue_toggle_button($managerRevenueVisible) ?>
    <a class="portal-nav-action" href="<?= $managerHomeUrl ?>"><i class="fa fa-home"></i><span>Tableau de bord</span></a>
    <a class="portal-nav-action" href="./manager.php?action=logout"><i class="fa fa-sign-out"></i><span><?= isset($_logout) ? $_logout : 'Logout' ?></span></a>
  </div>
</div>

<!-- Overlay mobile -->
<div id="sidenav-overlay"></div>

<!-- Sidenav -->
<div id="sidenav" class="sidenav">
  <div class="menu text-center align-middle card-header" style="border-radius:0;position:relative;">
    <h3><?= htmlspecialchars($identity ?: $manager_session_name) ?></h3>
    <small style="color:#cbd5e1;"><?= htmlspecialchars($managerName) ?></small><br>
    <span class="mgr-badge" style="margin-top:4px;"><i class="fa fa-briefcase"></i> <?= isset($_manager) ? $_manager : 'Manager' ?></span>
    <a id="closeSidenav" href="javascript:void(0)" title="Fermer le menu"
       style="position:absolute;top:8px;right:10px;font-size:18px;color:#aaa;display:none;text-decoration:none;">
      <i class="fa fa-times"></i>
    </a>
  </div>

  <a href="<?= $managerHomeUrl ?>" class="menu<?= ($action==='dashboard') ? ' active' : '' ?>">
    <i class="fa fa-home"></i> Accueil gérant
  </a>

  <a href="<?= $managerOverviewUrl ?>"
     class="menu<?= ($action==='overview') ? ' active' : '' ?>">
    <i class="fa fa-bar-chart"></i> Ventes vendeurs
  </a>

  <a href="<?= $managerAccountingUrl ?>"
     class="menu<?= ($action==='accounting') ? ' active' : '' ?>">
    <i class="fa fa-calculator"></i> Compte vendeur
  </a>

  <a href="./manager.php?action=tickets"
     class="menu<?= ($action==='tickets') ? ' active' : '' ?>">
    <i class="fa fa-ticket"></i> Générer &amp; imprimer
  </a>

  <a href="./manager.php?action=vendors"
     class="menu<?= ($action==='vendors') ? ' active' : '' ?>">
    <i class="fa fa-users"></i> Vendeurs
  </a>

  <a href="./manager.php?action=logout" class="menu">
    <i class="fa fa-sign-out"></i> <?= isset($_logout) ? $_logout : 'Logout' ?>
  </a>
</div>

<div id="notify"><div class="message"></div></div>
<div id="temp"></div>
<div id="main">
<div id="loading" class="lds-dual-ring"></div>
<div class="main-container">

<?php
// Auto-refresh header for overview & accounting (30s)
if (in_array($action, ['overview','accounting'])) {
    $refresh_url = './manager.php?action=' . $action . ($idbl ? '&idbl='.$idbl : '');
}
?>

<?php if ($manager_session_missing): ?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 style="margin:0;"><i class="fa fa-exclamation-triangle"></i> Session routeur introuvable</h3></div>
    <div class="card-body">
      <div class="portal-note-card">
        <b><?= htmlspecialchars($manager_session_message) ?></b><br>
        Le compte gérant est bien présent, mais la session routeur qui lui est associée n’existe plus dans la configuration locale de Mikhmon.
        L’administrateur doit recréer ou réassocier cette session avant de reprendre la génération, l’impression et les transferts.
      </div>
      <div class="mgr-quick-actions" style="margin-top:16px;">
        <a href="./manager.php?action=logout" class="btn" style="background:#34495e;color:#fff;padding:10px 16px;">
          <i class="fa fa-sign-out"></i> <?= $_logout ?>
        </a>
      </div>
    </div>
  </div>
</div></div>
<?php elseif ($manager_connection_error !== ''): ?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 style="margin:0;"><i class="fa fa-exclamation-triangle"></i> Routeur indisponible</h3></div>
    <div class="card-body">
      <div class="portal-note-card">
        <b><?= htmlspecialchars($manager_connection_error) ?></b><br>
        Le portail gérant reste ouvert, mais les ventes, stocks, impressions et transferts sont désactivés tant que Mikhmon ne peut pas joindre RouterOS.
      </div>
      <div class="mgr-quick-actions" style="margin-top:16px;">
        <a href="./manager.php?action=dashboard" class="btn bg-primary" style="padding:10px 16px;">
          <i class="fa fa-refresh"></i> Réessayer
        </a>
        <a href="./manager.php?action=logout" class="btn" style="background:#34495e;color:#fff;padding:10px 16px;">
          <i class="fa fa-sign-out"></i> <?= $_logout ?>
        </a>
      </div>
    </div>
  </div>
</div></div>
<?php elseif ($action === 'dashboard'): ?>
<!-- ══════════════════════════ ACCUEIL GÉRANT ═════════════════════════════ -->

<!-- ROW 1 — Hotspot -->
<div class="row manager-dashboard-row manager-dashboard-hotspot-row">
  <div class="col-12">
    <div class="card mgr-dash-card" style="margin-bottom:14px;">
      <div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div>
      <div class="card-body">
        <div class="row dashboard-hotspot-grid manager-dashboard-grid">
          <div class="col-3 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="<?= $managerHomeUrl ?>">
                <h1><?= (int)$managerConnectedCount ?><span class="box-stat-unit"> utilisateurs</span></h1>
                <div><i class="fa fa-wifi"></i> Connectés maintenant</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-green bmh-75">
              <a href="<?= $managerOverviewUrl ?>">
                <h1><?= (int)$managerStockTotal ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-archive"></i> Stock disponible</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-yellow bmh-75">
              <a href="<?= $managerOverviewUrl ?>">
                <h1><?= (int)$managerTodayTickets ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-ticket"></i> Tickets aujourd’hui</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-red bmh-75">
              <a href="<?= $managerAccountingUrl ?>">
                <h1><?= (int)$managerMonthTickets ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-print"></i> Tickets du mois</div>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ROW 2 — Gestion vendeurs -->
<div class="row manager-dashboard-row manager-dashboard-vendor-row">
  <div class="col-12">
    <div class="card mgr-dash-card" style="margin-bottom:14px;">
      <div class="card-header"><h3><i class="fa fa-users"></i> Gestion vendeurs</h3></div>
      <div class="card-body">
        <div class="row dashboard-hotspot-grid manager-dashboard-grid">
          <div class="col-3 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="<?= $managerHomeUrl ?>">
                <h1><i class="fa fa-home"></i></h1>
                <div>Accueil gérant</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-green bmh-75">
              <a href="<?= $managerOverviewUrl ?>">
                <h1><i class="fa fa-line-chart"></i></h1>
                <div>Ventes des vendeurs</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-yellow bmh-75">
              <a href="<?= $managerAccountingUrl ?>">
                <h1><i class="fa fa-calculator"></i></h1>
                <div>Compte vendeur</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-red bmh-75">
              <a href="./manager.php?action=tickets">
                <h1><i class="fa fa-ticket"></i></h1>
                <div>Générer &amp; imprimer</div>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ROW 3 — Rôle du gérant -->
<div class="row manager-dashboard-row manager-dashboard-role-row">
  <div class="col-12">
    <div class="card" style="margin-bottom:14px;">
      <div class="card-header"><h3><i class="fa fa-info-circle"></i> Rôle du gérant</h3></div>
      <div class="card-body">
        <p style="margin:0;color:#dbe4ee;font-size:13.5px;line-height:1.6;">
          Le gérant consulte uniquement l’activité utile à la production : connectés en cours, profils disponibles et tickets à générer ou imprimer.
          Il n’accède pas au tableau de bord technique admin.
        </p>
      </div>
    </div>
  </div>
</div>

<?php elseif ($action === 'overview'): ?>
<!-- ══════════════════════ VUE D'ENSEMBLE PAR PROFIL ══════════════════════ -->
<div class="row manager-overview-shell"><div class="col-12">
<div class="card manager-overview-page-card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <h3 style="margin:0;"><i class="fa fa-bar-chart"></i>
      <?= isset($_manager_overview) ? $_manager_overview : 'Vendors Overview' ?>
      — <span style="color:#8e44ad;"><?= htmlspecialchars($overviewReportLabel) ?></span>
    </h3>
    <small style="color:#cbd5e1;font-size:12px;">
      <i class="fa fa-refresh"></i> <?= isset($_auto_reload) ? $_auto_reload : 'Auto-refresh' ?>
      <span id="mgrRefreshCountdown" style="font-weight:bold;color:#8e44ad;">60s</span>
    </small>
  </div>
  <div class="card-body">
  <div class="container-fluid manager-overview-container">

    <!-- Filtre période -->
    <div class="row manager-overview-row manager-overview-filter-row" style="margin-bottom:18px;">
      <div class="col-12">
      <form method="get" action="./manager.php" class="manager-overview-period-form">
        <input type="hidden" name="action" value="overview">
        <div class="portal-filter-item">
          <label><i class="fa fa-sliders"></i> Période</label>
          <select name="period" class="form-control">
            <option value="week" <?= $overviewReportPeriod === 'week' ? 'selected' : '' ?>>Semaine</option>
            <option value="month" <?= $overviewReportPeriod === 'month' ? 'selected' : '' ?>>Mois</option>
            <option value="year" <?= $overviewReportPeriod === 'year' ? 'selected' : '' ?>>Année</option>
          </select>
        </div>
        <div class="portal-filter-item">
          <label><i class="fa fa-calendar-o"></i> Mois</label>
          <select name="month" class="form-control">
            <?php for ($mi = 1; $mi <= 12; $mi++): ?>
              <option value="<?= $mi ?>" <?= $overviewReportMonth === $mi ? 'selected' : '' ?>><?= htmlspecialchars($idblf[$mi]) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="portal-filter-item">
          <label><i class="fa fa-calendar-check-o"></i> Semaine</label>
          <input type="number" name="week" class="form-control" min="1" max="53" value="<?= (int)$overviewReportWeek ?>">
        </div>
        <div class="portal-filter-item">
          <label><i class="fa fa-calendar"></i> Année</label>
          <select name="year" class="form-control">
            <?php for ($yy = (int)date('Y') + 1; $yy >= 2018; $yy--): ?>
              <option value="<?= $yy ?>" <?= $overviewReportYear === $yy ? 'selected' : '' ?>><?= $yy ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="portal-filter-item manager-overview-period-actions">
          <label>&nbsp;</label>
          <button type="submit" class="btn bg-primary"><i class="fa fa-filter"></i> Afficher</button>
        </div>
      </form>
      <div class="mgr-month-filter manager-overview-month-shortcuts">
        <?php
          $curY = date("Y");
          for ($mi = 1; $mi <= 12; $mi++) {
              $ml  = $idbls[$mi]; $ms = $idblf[$mi];
              $tag = $ml . $curY;
              $active = ($overviewReportPeriod === 'month' && $overviewReportMonth === $mi && $overviewReportYear === (int)$curY) ? 'bg-primary' : '';
              echo '<a href="./manager.php?action=overview&period=month&month=' . $mi . '&year=' . $curY . '&idbl=' . $tag . '" class="btn btn-sm ' . $active . '" style="padding:4px 10px;">' . $ms . '</a>';
          }
        ?>
      </div>
      </div>
    </div>

    <?php if (empty($profileStats) && empty($managerSellersData)): ?>
      <p class="text-center" style="color:#888;"><i class="fa fa-info-circle"></i> <?= isset($_no_seller_registered) ? $_no_seller_registered : 'No vendor registered.' ?></p>
    <?php elseif (empty($profileStats)): ?>
      <p class="text-center" style="color:#888;padding:20px;"><i class="fa fa-info-circle"></i>
        <?= isset($idbl) && $idbl !== '' ? (isset($_no_sales_period) ? $_no_sales_period : 'No sales for this period') : (isset($_no_sales_recorded) ? $_no_sales_recorded : 'No sales recorded.') ?>
      </p>
    <?php else: ?>

    <!-- ── Résumé global (totaux toutes profils) ── -->
    <?php
      $gtAllToday = 0; $gtAllRevToday = 0.0;
      $gtAllMonth = 0; $gtAllRevMonth = 0.0; $gtAllStock = 0;
      foreach ($profileStats as $ps) {
          $gtAllToday    += $ps['today'];
          $gtAllRevToday += $ps['rev_today'];
          $gtAllMonth    += $ps['total'];
          $gtAllRevMonth += $ps['rev_total'];
          foreach ($ps['vendors'] as $v) $gtAllStock += $v['stock'];
      }
      foreach ($allSellerStock as $profs) {
          // stock déjà comptabilisé via profileStats
      }
    ?>
    <div class="row mgr-summary-cards manager-overview-row manager-overview-summary-row manager-overview-grid" style="margin-bottom:20px;">
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-blue" style="background:#f3e8fd;border-radius:8px;padding:12px 16px;border-left:4px solid #8e44ad;">
        <div style="font-size:11px;color:#8e44ad;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-sun-o"></i> Aujourd'hui</div>
        <div style="font-size:22px;font-weight:bold;color:#8e44ad;"><?= $gtAllToday ?> <small style="font-size:13px;">vcr</small></div>
        <div style="font-size:13px;color:#6c3483;"><?= mikhmon_revenue_money($managerRevenueVisible, $gtAllRevToday, $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-green" style="background:#eaf4fb;border-radius:8px;padding:12px 16px;border-left:4px solid #2980b9;">
        <div style="font-size:11px;color:#2980b9;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-calendar"></i> <?= htmlspecialchars($overviewReportPeriodLabel) ?></div>
        <div style="font-size:22px;font-weight:bold;color:#2980b9;"><?= $gtAllMonth ?> <small style="font-size:13px;">vcr</small></div>
        <div style="font-size:13px;color:#1a6fa0;"><?= mikhmon_revenue_money($managerRevenueVisible, $gtAllRevMonth, $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-yellow" style="background:#e8f8f5;border-radius:8px;padding:12px 16px;border-left:4px solid #27ae60;">
        <div style="font-size:11px;color:#27ae60;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-archive"></i> Stock total</div>
        <div style="font-size:22px;font-weight:bold;color:#27ae60;"><?= array_sum(array_map('array_sum', $allSellerStock)) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-red" style="background:#fff8e1;border-radius:8px;padding:12px 16px;border-left:4px solid #e67e22;">
        <div style="font-size:11px;color:#e67e22;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-percent"></i> Commissions</div>
        <?php
          $gtComm = 0.0;
          foreach ($managerSellersData as $sk => $sd) {
              $rate = isset($sd['commission']) ? (int)$sd['commission'] : 0;
              $rev  = isset($sellerStats[$sk]) ? $sellerStats[$sk]['rev_total'] : 0;
              $gtComm += $rev * $rate / 100;
          }
        ?>
        <div style="font-size:22px;font-weight:bold;color:#e67e22;"><?= mikhmon_revenue_money($managerRevenueVisible, $gtComm, $currency, $cekindo) ?></div>
      </div>
      </div>
    </div>

    <!-- ── Cartes par profil ── -->
    <?php foreach ($profileStats as $profName => $ps): ?>
    <?php
      $profStock = array_sum(array_column($ps['vendors'], 'stock'));
      $unitPrice = $ps['price'];
    ?>
    <div class="row manager-overview-row manager-overview-profile-row">
    <div class="col-12">
    <div class="mgr-light-profile-card manager-overview-profile-card">
      <!-- En-tête profil -->
      <div class="prof-card-header manager-overview-profile-header" style="background:linear-gradient(90deg,#f3e8fd,#fafafa);padding:12px 18px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
          <span style="font-size:17px;font-weight:bold;color:#5b2c8d;">
            <i class="fa fa-tag" style="color:#8e44ad;margin-right:6px;"></i><?= htmlspecialchars($profName) ?>
          </span>
          <?php if ($unitPrice > 0): ?>
          <span style="margin-left:10px;background:#8e44ad;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;">
            <?= mikhmon_revenue_money($managerRevenueVisible, $unitPrice, $currency, $cekindo) ?> / vcr
          </span>
          <?php endif; ?>
        </div>
        <div class="prof-card-stats manager-overview-profile-stats" style="display:flex;gap:14px;font-size:13px;">
          <span class="manager-overview-stat bg-blue" style="color:#c0392b;font-weight:bold;">
            <i class="fa fa-sun-o"></i> <?= $ps['today'] ?> vcr
            <small class="mgr-soft-note" style="font-weight:normal;">/ <?= mikhmon_revenue_money($managerRevenueVisible, $ps['rev_today'], $currency, $cekindo) ?></small>
          </span>
          <span class="manager-overview-stat bg-green" style="color:#2980b9;font-weight:bold;">
            <i class="fa fa-calendar"></i> <?= $ps['total'] ?> vcr
            <small class="mgr-soft-note" style="font-weight:normal;">/ <?= mikhmon_revenue_money($managerRevenueVisible, $ps['rev_total'], $currency, $cekindo) ?></small>
          </span>
          <span class="manager-overview-stat bg-yellow" style="color:#27ae60;font-weight:bold;">
            <i class="fa fa-archive"></i> <?= $profStock ?> stock
          </span>
          <span class="manager-overview-stat bg-red" style="color:#c0392b;font-weight:bold;">
            <i class="fa fa-money"></i> <?= $unitPrice > 0 ? mikhmon_revenue_money($managerRevenueVisible, $unitPrice, $currency, $cekindo) . ' / vcr' : 'Prix non défini' ?>
          </span>
        </div>
      </div>
      <!-- Tableau vendeurs pour ce profil -->
      <div class="manager-overview-table-wrap" style="overflow-x:auto;">
        <table class="table table-bordered portal-table-min-lg manager-overview-table">
          <thead class="thead-light">
            <tr>
              <th><?= isset($_seller) ? $_seller : 'Vendeur' ?></th>
              <th class="text-center" style="color:#c0392b;"><i class="fa fa-sun-o"></i> <?= isset($_today) ? $_today : 'Aujourd\'hui' ?></th>
              <th class="text-center" style="color:#c0392b;">CA <?= isset($_today) ? $_today : 'Auj.' ?></th>
              <th class="text-center" style="color:#2980b9;"><i class="fa fa-calendar"></i> <?= htmlspecialchars($overviewReportPeriodLabel) ?></th>
              <th class="text-center" style="color:#2980b9;">CA période</th>
              <th class="text-center" style="color:#27ae60;"><i class="fa fa-archive"></i> Stock</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ps['vendors'] as $vk => $vd): ?>
            <tr>
              <td data-label="<?= isset($_seller) ? $_seller : 'Vendeur' ?>">
                <span class="manager-overview-cell-value">
                <b><?= htmlspecialchars($vd['name']) ?></b>
                <small class="mgr-soft-note" style="margin-left:4px;"><code><?= htmlspecialchars($vk) ?></code></small>
                </span>
              </td>
              <td class="text-center" data-label="<?= isset($_today) ? $_today : 'Aujourd\'hui' ?>">
                <span class="manager-overview-cell-value">
                <?php if ($vd['today'] > 0): ?>
                  <span style="background:#fde8e8;color:#c0392b;border-radius:10px;padding:1px 8px;font-weight:bold;"><?= $vd['today'] ?></span>
                <?php else: ?>
                  <span style="color:#ccc;">—</span>
                <?php endif; ?>
                </span>
              </td>
              <td class="text-center" data-label="CA <?= isset($_today) ? $_today : 'Auj.' ?>" style="color:#c0392b;">
                <span class="manager-overview-cell-value">
                <?= $vd['today'] > 0 ? mikhmon_revenue_money($managerRevenueVisible, $vd['today'] * $vd['price'], $currency, $cekindo) : '<span style="color:#ccc;">—</span>' ?>
                </span>
              </td>
              <td class="text-center" data-label="<?= htmlspecialchars($overviewReportPeriodLabel) ?>">
                <span class="manager-overview-cell-value">
                <?php if ($vd['total'] > 0): ?>
                  <span style="background:#e8f0fe;color:#2980b9;border-radius:10px;padding:1px 8px;font-weight:bold;"><?= $vd['total'] ?></span>
                <?php else: ?>
                  <span style="color:#ccc;">—</span>
                <?php endif; ?>
                </span>
              </td>
              <td class="text-center" data-label="CA période" style="color:#2980b9;">
                <span class="manager-overview-cell-value">
                <?= $vd['total'] > 0 ? mikhmon_revenue_money($managerRevenueVisible, $vd['total'] * $vd['price'], $currency, $cekindo) : '<span style="color:#ccc;">—</span>' ?>
                </span>
              </td>
              <td class="text-center" data-label="Stock">
                <span class="manager-overview-cell-value">
                <?php if ($vd['stock'] > 0): ?>
                  <span style="background:#e8f8f5;color:#27ae60;border-radius:10px;padding:1px 8px;font-weight:bold;"><?= $vd['stock'] ?></span>
                <?php else: ?>
                  <span style="color:#ccc;">0</span>
                <?php endif; ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <!-- Ligne total du profil -->
          <?php
            $profTotToday = array_sum(array_column($ps['vendors'], 'today'));
            $profTotMonth = array_sum(array_column($ps['vendors'], 'total'));
            $profTotStock = array_sum(array_column($ps['vendors'], 'stock'));
          ?>
          <tfoot>
            <tr class="mgr-profile-total-row">
              <td data-label="Total"><span class="manager-overview-cell-value"><i class="fa fa-sigma"></i> TOTAL</span></td>
              <td class="text-center" data-label="<?= isset($_today) ? $_today : 'Aujourd\'hui' ?>"><span class="manager-overview-cell-value"><?= $profTotToday ?></span></td>
              <td class="text-center" data-label="CA <?= isset($_today) ? $_today : 'Auj.' ?>"><span class="manager-overview-cell-value"><?= mikhmon_revenue_money($managerRevenueVisible, $profTotToday * $unitPrice, $currency, $cekindo) ?></span></td>
              <td class="text-center" data-label="<?= htmlspecialchars($overviewReportPeriodLabel) ?>"><span class="manager-overview-cell-value"><?= $profTotMonth ?></span></td>
              <td class="text-center" data-label="CA période"><span class="manager-overview-cell-value"><?= mikhmon_revenue_money($managerRevenueVisible, $profTotMonth * $unitPrice, $currency, $cekindo) ?></span></td>
              <td class="text-center" data-label="Stock"><span class="manager-overview-cell-value"><?= $profTotStock ?></span></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

  </div>
  </div>
</div>
</div></div>

<?php elseif ($action === 'accounting'): ?>
<!-- ══════════════════════════ COMPTABILITÉ ════════════════════════════════ -->
<div class="row manager-accounting-shell"><div class="col-12">
<div class="card manager-accounting-page-card">
  <div class="card-header">
    <h3><i class="fa fa-calculator"></i>
      Compte vendeur
      <?php
        if (strlen($idbl) > 0) {
            $m1 = substr($idbl,0,3); $y1 = substr($idbl,3,4);
            $fm = array_search($m1, $idbls);
            echo ' — ' . ($idblf[$fm] ?? ucfirst($m1)) . ' ' . $y1;
        }
      ?>
    </h3>
  </div>
  <div class="card-body">
  <div class="container-fluid manager-accounting-container">

    <!-- Filtre mois -->
    <div class="row manager-accounting-row manager-accounting-filter-row" style="margin-bottom:16px;">
      <div class="col-12">
      <div class="mgr-month-filter">
        <?php
          for ($mi = 1; $mi <= 12; $mi++) {
              $ml  = $idbls[$mi]; $ms = $idblf[$mi];
              $tag = $ml . date("Y");
              $active = ($idbl === $tag) ? 'bg-primary' : '';
              echo '<a href="./manager.php?action=accounting&idbl=' . $tag . '" class="btn btn-sm ' . $active . '" style="padding:4px 10px;">' . $ms . '</a>';
          }
        ?>
      </div>
      </div>
    </div>

    <div class="portal-note-card mgr-accounting-help" style="margin-bottom:16px;text-align:left;">
      <b><i class="fa fa-scissors"></i> Compte vendeur par période exacte</b><br>
      Choisissez le vendeur, une date X avec heure de début, puis une date Y avec heure de fin. Le gérant encaisse la vente totale et octroie automatiquement 10% de commission au vendeur.
    </div>

    <form method="get" action="./manager.php" class="portal-card-section mgr-accounting-shell" style="margin-bottom:18px;">
      <input type="hidden" name="action" value="accounting">
      <input type="hidden" name="idbl" value="<?= htmlspecialchars($accountingMonthKey) ?>">
      <div class="row mgr-accounting-form manager-accounting-row manager-accounting-form-row">
        <div class="portal-filter-item col-4 col-box-12 manager-bootstrap-col">
          <label class="transfer-label"><i class="fa fa-calendar-o"></i> Début</label>
          <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($accountingFrom) ?>">
        </div>
        <div class="portal-filter-item col-4 col-box-12 manager-bootstrap-col">
          <label class="transfer-label"><i class="fa fa-calendar-check-o"></i> Arrêt</label>
          <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($accountingTo) ?>">
        </div>
        <div class="portal-filter-item col-4 col-box-12 manager-bootstrap-col">
          <label class="transfer-label"><i class="fa fa-user"></i> Vendeur à compter</label>
          <select name="seller" class="form-control" required>
            <option value="">Choisir un vendeur</option>
            <?php foreach ($managerSellersData as $sk => $sd): ?>
              <option value="<?= htmlspecialchars($sk) ?>" <?= $accountingSeller === $sk ? 'selected' : '' ?>>
                <?= htmlspecialchars($sd['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="portal-filter-item col-6 col-box-12 manager-bootstrap-col">
          <label class="transfer-label"><i class="fa fa-clock-o"></i> Heure début</label>
          <input type="time" name="settled_at" class="form-control" step="60" value="<?= htmlspecialchars(substr($accountingSettlementTime, 0, 5)) ?>">
        </div>
        <div class="portal-filter-item col-6 col-box-12 manager-bootstrap-col">
          <label class="transfer-label"><i class="fa fa-clock-o"></i> Heure fin</label>
          <input type="time" name="next_settled_at" class="form-control" step="60" value="<?= htmlspecialchars(substr($accountingNextSettlementTime, 0, 5)) ?>">
        </div>
      </div>
      <div class="mgr-accounting-actions">
        <button type="submit" class="btn bg-primary">
          <i class="fa fa-filter"></i> Afficher les comptes
        </button>
        <a class="btn" style="background:#eee;color:#333;" href="./manager.php?action=accounting&idbl=<?= urlencode($accountingMonthKey) ?>">
          <i class="fa fa-refresh"></i> Mois complet
        </a>
      </div>
    </form>

    <?php
      $acctTotal = $accountingSummary['total'];
      $acctSellerLabel = $accountingSeller !== '' && isset($managerSellersData[$accountingSeller])
        ? $managerSellersData[$accountingSeller]['name']
        : 'Tous les vendeurs';
      $accountingExpensesDeducted = ($accountingSeller === '');
      $acctNetAfterExpenses = $accountingExpensesDeducted ? mikhmon_accounting_net_after_expenses($acctTotal, $accountingPeriodExpenses) : $acctTotal['net'];
    ?>
    <div class="row mgr-summary-cards manager-accounting-row manager-accounting-summary-row manager-accounting-grid" style="margin-bottom:18px;">
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-blue" style="background:#eaf4fb;border-radius:8px;padding:14px 16px;border-left:4px solid #2980b9;">
        <div style="font-size:11px;color:#2980b9;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-calendar"></i> Période arrêtée</div>
        <div style="font-size:18px;font-weight:bold;color:#2980b9;"><?= htmlspecialchars($accountingFrom) ?> <?= htmlspecialchars(substr($accountingSettlementTime, 0, 5)) ?> → <?= htmlspecialchars($accountingTo) ?> <?= htmlspecialchars(substr($accountingNextSettlementTime, 0, 5)) ?></div>
        <div style="font-size:12px;color:#1a6fa0;"><?= htmlspecialchars($acctSellerLabel) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-green" style="background:#f3e8fd;border-radius:8px;padding:14px 16px;border-left:4px solid #8e44ad;">
        <div style="font-size:11px;color:#8e44ad;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-ticket"></i> Tickets</div>
        <div style="font-size:24px;font-weight:bold;color:#8e44ad;"><?= $acctTotal['count'] ?> <small style="font-size:13px;">vcr</small></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-yellow" style="background:#e8f8f5;border-radius:8px;padding:14px 16px;border-left:4px solid #27ae60;">
        <div style="font-size:11px;color:#27ae60;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-money"></i> Total encaissé</div>
        <div style="font-size:22px;font-weight:bold;color:#27ae60;"><?= mikhmon_revenue_money($managerRevenueVisible, $acctTotal['revenue'], $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-red" style="background:#fff8e1;border-radius:8px;padding:14px 16px;border-left:4px solid #e67e22;">
        <div style="font-size:11px;color:#e67e22;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-percent"></i> Commission vendeur (10%)</div>
        <div style="font-size:22px;font-weight:bold;color:#e67e22;"><?= mikhmon_revenue_money($managerRevenueVisible, $acctTotal['commission'], $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-blue" style="background:#fdeef7;border-radius:8px;padding:14px 16px;border-left:4px solid #c0398f;">
        <div style="font-size:11px;color:#c0398f;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-bank"></i> Net à remettre</div>
        <div style="font-size:22px;font-weight:bold;color:#c0398f;"><?= mikhmon_revenue_money($managerRevenueVisible, $acctTotal['net'], $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-green" style="background:#fff1f0;border-radius:8px;padding:14px 16px;border-left:4px solid #c0392b;">
        <div style="font-size:11px;color:#c0392b;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-minus-circle"></i> Dépenses</div>
        <div style="font-size:22px;font-weight:bold;color:#c0392b;"><?= mikhmon_revenue_money($managerRevenueVisible, $accountingExpensesTotal, $currency, $cekindo) ?></div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-yellow" style="background:#ecfdf3;border-radius:8px;padding:14px 16px;border-left:4px solid #15803d;">
        <div style="font-size:11px;color:#15803d;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-calculator"></i> Net après dépenses</div>
        <div style="font-size:22px;font-weight:bold;color:#15803d;"><?= mikhmon_revenue_money($managerRevenueVisible, $acctNetAfterExpenses, $currency, $cekindo) ?></div>
        <?php if (!$accountingExpensesDeducted): ?>
          <div style="font-size:11px;color:#64748b;">Non déduit du vendeur sélectionné</div>
        <?php endif; ?>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col">
      <div class="manager-color-card bg-red" style="background:#eef2f7;border-radius:8px;padding:14px 16px;border-left:4px solid #34495e;">
        <div style="font-size:11px;color:#34495e;font-weight:bold;text-transform:uppercase;letter-spacing:.5px;"><i class="fa fa-clock-o"></i> Plage horaire</div>
        <div style="font-size:22px;font-weight:bold;color:#34495e;"><?= htmlspecialchars(substr($accountingSettlementTime, 0, 5)) ?> → <?= htmlspecialchars(substr($accountingNextSettlementTime, 0, 5)) ?></div>
      </div>
      </div>
    </div>

    <div class="mgr-expense-box">
      <b><i class="fa fa-credit-card"></i> Dépenses du gérant</b>
      <div style="font-size:12px;color:#64748b;margin-top:4px;">
        Les dépenses datées dans cette période sont déduites de la vente globale lorsque tous les vendeurs sont affichés.
      </div>
      <?php if ($accountingExpenseMsg !== ''): ?>
        <div class="bg-success" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-check"></i> <?= htmlspecialchars($accountingExpenseMsg) ?></div>
      <?php endif; ?>
      <?php if ($accountingExpenseError !== ''): ?>
        <div class="bg-warning" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-warning"></i> <?= htmlspecialchars($accountingExpenseError) ?></div>
      <?php endif; ?>
      <form method="post" action="./manager.php?action=accounting&idbl=<?= urlencode($accountingMonthKey) ?>&from=<?= urlencode($accountingFrom) ?>&to=<?= urlencode($accountingTo) ?>&settled_at=<?= urlencode($accountingSettlementTime) ?>&next_settled_at=<?= urlencode($accountingNextSettlementTime) ?><?= $accountingSeller !== '' ? '&seller=' . urlencode($accountingSeller) : '' ?>" class="mgr-expense-form">
        <?= csrf_field() ?>
        <div>
          <label class="transfer-label"><i class="fa fa-calendar-o"></i> Date dépense</label>
          <input type="date" name="expense_date" class="form-control" value="<?= htmlspecialchars($accountingTo) ?>" required>
        </div>
        <div>
          <label class="transfer-label"><i class="fa fa-tags"></i> Type</label>
          <select name="expense_type" class="form-control" required>
            <?php foreach (mikhmon_accounting_expense_types() as $expenseType): ?>
              <option value="<?= htmlspecialchars($expenseType) ?>"><?= htmlspecialchars($expenseType) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="transfer-label"><i class="fa fa-pencil"></i> Libellé</label>
          <input type="text" name="expense_label" class="form-control" placeholder="Ex: Facture CIE avril" maxlength="120">
        </div>
        <div>
          <label class="transfer-label"><i class="fa fa-money"></i> Montant</label>
          <input type="number" name="expense_amount" class="form-control" min="1" step="1" placeholder="0" required>
        </div>
        <button type="submit" name="add_accounting_expense" class="btn" style="background:#c0392b;color:#fff;">
          <i class="fa fa-plus"></i> Ajouter la dépense
        </button>
      </form>

      <?php if (empty($accountingPeriodExpenses)): ?>
        <p style="margin:8px 0 0;color:#64748b;"><i class="fa fa-info-circle"></i> Aucune dépense enregistrée sur cette période.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered accounting-responsive-table mgr-expense-list">
            <thead class="thead-light">
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Libellé</th>
                <th class="text-center">Montant</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accountingPeriodExpenses as $expense): ?>
              <tr>
                <td data-label="Date"><?= htmlspecialchars($expense['date']) ?></td>
                <td data-label="Type"><?= htmlspecialchars($expense['type']) ?></td>
                <td data-label="Libellé"><?= htmlspecialchars($expense['label']) ?></td>
                <td class="text-center" data-label="Montant" style="font-weight:bold;color:#c0392b;"><?= mikhmon_revenue_money($managerRevenueVisible, $expense['amount'], $currency, $cekindo) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="acct-total-row">
                <td colspan="3" data-label="Total dépenses"><i class="fa fa-sigma"></i> Total dépenses</td>
                <td class="text-center" data-label="Montant"><?= mikhmon_revenue_money($managerRevenueVisible, $accountingExpensesTotal, $currency, $cekindo) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($accountingNextFrom !== ''): ?>
    <div class="mgr-accounting-next-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
      <a class="btn" style="background:#5b2c8d;color:#fff;" href="./manager.php?action=accounting&idbl=<?= urlencode($accountingMonthKey) ?>&from=<?= urlencode($accountingNextFrom) ?>&to=<?= urlencode($accountingNextFrom) ?>&settled_at=<?= urlencode($accountingNextSettlementTime) ?>&next_settled_at=<?= urlencode($accountingNextSettlementTime) ?><?= $accountingSeller !== '' ? '&seller=' . urlencode($accountingSeller) : '' ?>">
        <i class="fa fa-step-forward"></i> Jour suivant : <?= htmlspecialchars($accountingNextFrom) ?>
      </a>
      <a class="btn" style="background:#34495e;color:#fff;" href="./manager.php?action=accounting&idbl=<?= urlencode($accountingMonthKey) ?>&from=<?= urlencode($accountingNextFrom) ?>&to=<?= urlencode($accountingNextTo) ?>&settled_at=<?= urlencode($accountingNextSettlementTime) ?>&next_settled_at=<?= urlencode($accountingNextSettlementTime) ?><?= $accountingSeller !== '' ? '&seller=' . urlencode($accountingSeller) : '' ?>">
        <i class="fa fa-calendar-plus-o"></i> Reste du mois
      </a>
    </div>
    <?php endif; ?>

    <?php
      $accountingNoticeSampleName = 'vendeur';
      if (!empty($accountingNoticeTargets) && isset($managerSellersData[$accountingNoticeTargets[0]]['name'])) {
        $accountingNoticeSampleName = $managerSellersData[$accountingNoticeTargets[0]]['name'];
      } elseif ($accountingSeller !== '' && isset($managerSellersData[$accountingSeller]['name'])) {
        $accountingNoticeSampleName = $managerSellersData[$accountingSeller]['name'];
      }
      $accountingNoticePreviewTotals = array();
      if (!empty($accountingNoticeTargets) && isset($accountingNoticeTotals[$accountingNoticeTargets[0]])) {
        $accountingNoticePreviewTotals = $accountingNoticeTotals[$accountingNoticeTargets[0]];
      } elseif ($accountingSeller !== '' && isset($accountingNoticeTotals[$accountingSeller])) {
        $accountingNoticePreviewTotals = $accountingNoticeTotals[$accountingSeller];
      }
      $accountingNoticePreview = mikhmon_accounting_notification_text($accountingNoticeSampleName, $accountingFrom, $accountingTo, $accountingSettlementTime, $accountingNextFrom, $accountingNextTo, $accountingNextSettlementTime, $accountingNoticePreviewTotals);
    ?>
    <div class="accounting-notice-box">
      <b><i class="fa fa-bell"></i> Notification aux vendeurs</b>
      <div style="font-size:12px;color:#64748b;margin-top:4px;">
        Cibles : <?= count($accountingNoticeTargets) ?> vendeur(s) concerné(s) par cette période.
      </div>
      <?php if ($accountingNoticeMsg !== ''): ?>
        <div class="bg-success" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-check"></i> <?= htmlspecialchars($accountingNoticeMsg) ?></div>
      <?php endif; ?>
      <?php if ($accountingNoticeError !== ''): ?>
        <div class="bg-warning" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-warning"></i> <?= htmlspecialchars($accountingNoticeError) ?></div>
      <?php endif; ?>
      <div class="accounting-notice-preview"><?= htmlspecialchars($accountingNoticePreview) ?></div>
      <form method="post" action="./manager.php?action=accounting&idbl=<?= urlencode($accountingMonthKey) ?>&from=<?= urlencode($accountingFrom) ?>&to=<?= urlencode($accountingTo) ?>&settled_at=<?= urlencode($accountingSettlementTime) ?>&next_settled_at=<?= urlencode($accountingNextSettlementTime) ?><?= $accountingSeller !== '' ? '&seller=' . urlencode($accountingSeller) : '' ?>" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="settled_at" value="<?= htmlspecialchars($accountingSettlementTime) ?>">
        <input type="hidden" name="next_settled_at" value="<?= htmlspecialchars($accountingNextSettlementTime) ?>">
        <button type="submit" name="send_accounting_notice" class="btn" style="background:#34495e;color:#fff;">
          <i class="fa fa-paper-plane"></i> Notifier les vendeurs
        </button>
      </form>
    </div>

    <?php if (empty($accountingSummary['days'])): ?>
      <p class="text-center" style="color:#888;padding:20px;"><i class="fa fa-info-circle"></i> Aucune période valide.</p>
    <?php else: ?>
      <?php foreach ($accountingSummary['days'] as $dayKey => $day): ?>
      <div class="card box-bordered" style="margin-bottom:14px;border-left:4px solid <?= $day['total']['count'] > 0 ? '#27ae60' : '#cbd5e1' ?>;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:#fafafa;">
          <h4 style="margin:0;color:#243447;">
            <i class="fa fa-calendar"></i> <?= htmlspecialchars($day['iso']) ?>
            <small style="color:#888;">(<?= htmlspecialchars($dayKey) ?>)</small>
          </h4>
          <div style="font-size:13px;color:#555;">
            <b><?= $day['total']['count'] ?></b> vcr ·
            <b><?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['revenue'], $currency, $cekindo) ?></b> ·
            Commission <?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['commission'], $currency, $cekindo) ?> ·
            Net <?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['net'], $currency, $cekindo) ?> ·
            <span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($accountingSettlementTime) ?></span>
          </div>
        </div>
        <div class="card-body">
          <?php if (empty($day['sellers'])): ?>
            <p class="text-center" style="color:#999;margin:0;"><i class="fa fa-info-circle"></i> Aucun compte à régler pour cette journée. <span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> Heure du compte : <?= htmlspecialchars($accountingSettlementTime) ?></span></p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-bordered portal-table-min-md accounting-responsive-table">
            <thead class="thead-light">
              <tr>
                <th><?= isset($_seller) ? $_seller : 'Vendeur' ?></th>
                <th class="text-center">Heure du compte</th>
                <th>Profils vendus</th>
                <th class="text-center">Tickets</th>
                <th class="text-center">Total encaissé</th>
                <th class="text-center">Commission</th>
                <th class="text-center">Net à remettre</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($day['sellers'] as $sellerRow): ?>
              <tr>
                <td class="accounting-seller-cell" data-label="<?= isset($_seller) ? $_seller : 'Vendeur' ?>">
                  <b><?= htmlspecialchars($sellerRow['name']) ?></b><br>
                  <small style="color:#888;"><code><?= htmlspecialchars($sellerRow['key']) ?></code> · <?= (int)$sellerRow['commission_rate'] ?>%</small>
                  <span class="accounting-settlement-chip accounting-mobile-settlement"><i class="fa fa-clock-o"></i> Heure du compte : <?= htmlspecialchars($accountingSettlementTime) ?></span>
                </td>
                <td class="text-center accounting-time-cell" data-label="Heure du compte"><span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($accountingSettlementTime) ?></span></td>
                <td data-label="Profils vendus">
                  <?php foreach ($sellerRow['profiles'] as $profileName => $profileTotal): ?>
                    <span class="acct-profile-badge">
                      <span class="acct-profile-name"><?= htmlspecialchars($profileName) ?></span>
                      <span class="acct-profile-count"><?= (int)$profileTotal['count'] ?></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td class="text-center" data-label="Tickets"><?= (int)$sellerRow['count'] ?></td>
                <td class="text-center" data-label="Total encaissé"><?= mikhmon_revenue_money($managerRevenueVisible, $sellerRow['revenue'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Commission" style="color:#e67e22;font-weight:bold;"><?= mikhmon_revenue_money($managerRevenueVisible, $sellerRow['commission'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Net à remettre" style="color:#c0398f;font-weight:bold;"><?= mikhmon_revenue_money($managerRevenueVisible, $sellerRow['net'], $currency, $cekindo) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="acct-total-row">
                <td colspan="3" data-label="Arrêt"><i class="fa fa-stop-circle"></i> Arrêt du jour · <?= htmlspecialchars($accountingSettlementTime) ?></td>
                <td class="text-center" data-label="Tickets"><?= (int)$day['total']['count'] ?></td>
                <td class="text-center" data-label="Total encaissé"><?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['revenue'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Commission"><?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['commission'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Net à remettre"><?= mikhmon_revenue_money($managerRevenueVisible, $day['total']['net'], $currency, $cekindo) ?></td>
              </tr>
            </tfoot>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
  </div>
</div>
</div></div>

<?php elseif ($action === 'transfer'): ?>
<!-- ══════════════════════════ TRANSFERT DE STOCK ══════════════════════════ -->
<div class="row"><div class="col-12">
<div class="card">
  <div class="card-header">
    <h3><i class="fa fa-exchange"></i> <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?></h3>
  </div>
  <div class="card-body">

    <?php if ($transfer_msg): ?>
      <div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-bottom:14px;">
        <i class="fa fa-check-circle"></i> <?= $transfer_msg ?>
      </div>
    <?php endif; ?>
    <?php if ($transfer_error): ?>
      <div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-bottom:14px;">
        <i class="fa fa-ban"></i> <?= htmlspecialchars($transfer_error) ?>
      </div>
    <?php endif; ?>

    <!-- Récapitulatif total par profil -->
    <?php if (!empty($stockByProfile)): ?>
    <h4 style="margin-bottom:10px;"><i class="fa fa-tags"></i> Total par profil</h4>
    <div class="table-responsive table-wrap-md mb-20">
    <table class="table table-bordered portal-table-min-sm" style="font-size:13px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center" style="color:#1a6fa0;">Vendeurs</th>
          <th class="text-center" style="color:#5b2c8d;">Gérant</th>
          <th class="text-center"><b>Total</b></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stockByProfile as $prof => $info):
          $sellersQty = $info['total'] - $info['global'];
        ?>
          <tr>
            <td><b><?= htmlspecialchars($prof) ?></b></td>
            <td class="text-center"><?= $sellersQty ?></td>
            <td class="text-center"><?= (int)$info['global'] ?></td>
            <td class="text-center"><b><?= (int)$info['total'] ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="acct-total-row">
          <td><i class="fa fa-sigma"></i> <?= isset($_total) ? $_total : 'TOTAL' ?></td>
          <td class="text-center"><?= array_sum(array_map(function($i){return $i['total']-$i['global'];}, $stockByProfile)) ?></td>
          <td class="text-center"><?= array_sum(array_column($stockByProfile, 'global')) ?></td>
          <td class="text-center"><b><?= array_sum(array_column($stockByProfile, 'total')) ?></b></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>

    <!-- Stock global par vendeur -->
    <h4 style="margin-bottom:10px;"><i class="fa fa-archive"></i> <?= isset($_transfer_available) ? $_transfer_available : 'Available stock' ?></h4>
    <div class="table-responsive table-wrap-sm mb-20">
    <table class="table table-bordered portal-table-min-sm" style="font-size:13px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_seller) ? $_seller : 'Vendor' ?></th>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center"><?= isset($_seller_qty) ? $_seller_qty : 'Qty' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php
          $hasAny = false;
          foreach ($allSellerStock as $sk => $profs):
            if (empty($profs)) continue;
            $hasAny  = true;
            $first   = true;
            $rowspan = count($profs);
            foreach ($profs as $prof => $qty):
        ?>
          <tr>
            <?php if ($first): ?>
            <td rowspan="<?= $rowspan ?>" style="vertical-align:middle;font-weight:bold;">
              <?= htmlspecialchars($managerSellersData[$sk]['name']) ?>
              <br><small style="color:#999;font-weight:normal;"><code><?= htmlspecialchars($sk) ?></code></small>
            </td>
            <?php $first = false; endif; ?>
            <td><?= htmlspecialchars($prof) ?></td>
            <td class="text-center"><b><?= $qty ?></b></td>
          </tr>
        <?php endforeach; endforeach; ?>
        <?php if (!$hasAny): ?>
          <tr><td colspan="3" class="text-center" style="color:#888;">
            <i class="fa fa-info-circle"></i> <?= isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets.' ?>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Formulaire transfert -->
    <?php if (($hasAny || !empty($globalStock)) && count($managerSellersData) > 0): ?>
    <div class="portal-admin-shell" style="margin-bottom:18px;">
      <div class="portal-toolbar">
        <div class="portal-toolbar-group">
          <h4 class="portal-section-title"><i class="fa fa-inbox"></i> Stock gérant non attribué</h4>
          <span class="portal-chip portal-chip-purple"><?= array_sum($globalStock) ?> tickets</span>
        </div>
      </div>
      <div class="table-responsive" style="margin-bottom:16px;">
        <table class="table table-bordered portal-table-min-sm">
          <thead class="thead-light">
            <tr>
              <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
              <th class="text-center"><?= isset($_seller_qty) ? $_seller_qty : 'Qty' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($globalStock)): ?>
              <tr><td colspan="2" class="text-center portal-empty-note"><i class="fa fa-info-circle"></i> <?= isset($_no_manager_stock) ? $_no_manager_stock : 'No manager stock available.' ?></td></tr>
            <?php else: ?>
              <?php foreach ($globalStock as $prof => $qty): ?>
              <tr>
                <td><?= htmlspecialchars($prof) ?></td>
                <td class="text-center"><b><?= $qty ?></b></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($globalStock) && !empty($managerSellersData)): ?>
      <form method="post" action="./manager.php?action=transfer" class="portal-card-section form-wrap-lg m-center-auto mb-20" id="mgrGlobalTransferForm">
        <?= csrf_field() ?>
        <input type="hidden" name="do_global_transfer" value="1">
        <div class="portal-filter-grid">
          <div class="portal-filter-item">
            <label class="transfer-label"><i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></label>
            <select name="global_transfer_profile" class="form-control" required>
              <option value=""><?= isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile' ?></option>
              <?php foreach ($globalStock as $prof => $qty): ?>
                <option value="<?= htmlspecialchars($prof) ?>"><?= htmlspecialchars($prof) ?> (<?= (int)$qty ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="portal-filter-item">
            <label class="transfer-label"><i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></label>
            <input type="number" name="global_transfer_qty" class="form-control" min="1" value="1" required>
          </div>
          <div class="portal-filter-item">
            <label class="transfer-label"><i class="fa fa-arrow-right"></i> <?= isset($_transfer_to) ? $_transfer_to : 'To' ?></label>
            <select name="global_dst_seller" class="form-control" required>
              <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
              <?php foreach ($managerSellersData as $sk => $sd): ?>
                <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="portal-toolbar" style="justify-content:center;">
          <button type="submit" class="btn-transfer" style="background:#5b2c8d;">
            <i class="fa fa-random"></i> Distribuer depuis le stock gérant
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <?php if ($hasAny && count($managerSellersData) > 1): ?>
    <hr>
    <h4 style="margin-bottom:10px;"><i class="fa fa-share"></i> Réattribuer le stock d'un vendeur</h4>
    <p style="color:#666;font-size:13px;margin-bottom:14px;">
      <i class="fa fa-info-circle"></i> <?= isset($_transfer_info) ? $_transfer_info : 'Select profile, quantity and destination vendor.' ?>
    </p>
    <form method="post" action="./manager.php?action=transfer" class="form-wrap-sm" id="mgrTransferForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do_transfer" value="1">
      <div class="admin-transfer-grid">
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-user"></i> <?= isset($_transfer_from) ? $_transfer_from : 'From' ?></label>
          <select name="src_seller" id="srcSeller" class="form-control" onchange="updateProfiles()" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($managerSellersData as $sk => $sd): ?>
              <?php if (!empty($allSellerStock[$sk])): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></label>
          <select name="transfer_profile" id="transferProf" class="form-control" required>
            <option value=""><?= isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile' ?></option>
          </select>
        </div>
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></label>
          <input type="number" name="transfer_qty" class="form-control" min="1" value="1" required>
        </div>
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-arrow-right"></i> <?= isset($_transfer_to) ? $_transfer_to : 'To' ?></label>
          <select name="dst_seller" class="form-control" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($managerSellersData as $sk => $sd): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn-transfer" style="background:#8e44ad;">
        <i class="fa fa-exchange"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?>
      </button>
    </form>
    <script>
    var mgrStock = <?= json_encode($allSellerStock) ?>;
    function updateProfiles() {
      var src = document.getElementById('srcSeller').value;
      var sel = document.getElementById('transferProf');
      sel.innerHTML = '<option value=""><?= addslashes(isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile') ?></option>';
      if (src && mgrStock[src]) {
        for (var p in mgrStock[src]) {
          sel.innerHTML += '<option value="'+p+'">'+p+' ('+mgrStock[src][p]+')</option>';
        }
      }
    }
    </script>
    <?php endif; ?>
    <?php endif; ?>

  </div>
</div>
</div></div>

<?php elseif ($action === 'vendors'): ?>
<!-- ══════════════════════════ GESTION VENDEURS ════════════════════════════ -->
<div class="row manager-vendors-shell"><div class="col-12">
<div class="card manager-vendors-row manager-vendors-page-card">
  <div class="card-header">
    <h3><i class="fa fa-users"></i> <?= isset($_manager_my_vendors) ? $_manager_my_vendors : 'My Vendors' ?></h3>
  </div>
  <div class="card-body">
  <div class="container-fluid manager-vendors-container">

    <?= $msg_vendors ?>

    <div class="row mgr-summary-cards manager-vendors-row manager-vendors-summary-row manager-vendors-grid" style="margin-bottom:18px;">
      <div class="col-3 col-box-6 manager-bootstrap-col manager-vendors-summary-col">
      <div class="manager-color-card bg-blue" style="border-radius:8px;padding:14px 16px;">
        <div><i class="fa fa-users"></i> Vendeurs</div>
        <div><?= count($managerSellersData) ?></div>
        <div>comptes liés au gérant</div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col manager-vendors-summary-col">
      <div class="manager-color-card bg-green" style="border-radius:8px;padding:14px 16px;">
        <div><i class="fa fa-server"></i> Session</div>
        <div><?= htmlspecialchars($manager_session_name) ?></div>
        <div>routeur assigné</div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col manager-vendors-summary-col">
      <div class="manager-color-card bg-yellow" style="border-radius:8px;padding:14px 16px;">
        <div><i class="fa fa-archive"></i> Stock vendeurs</div>
        <div><?= array_sum(array_map('array_sum', $allSellerStock)) ?></div>
        <div>tickets non utilisés</div>
      </div>
      </div>
      <div class="col-3 col-box-6 manager-bootstrap-col manager-vendors-summary-col">
      <div class="manager-color-card bg-red" style="border-radius:8px;padding:14px 16px;">
        <div><i class="fa fa-plus"></i> Ajout</div>
        <div>Actif</div>
        <div>création encadrée</div>
      </div>
      </div>
    </div>

    <!-- Stock par vendeur -->
    <div class="row manager-vendors-row manager-vendors-stock-row">
    <div class="col-12">
    <div class="card box-bordered manager-vendors-stock-card" style="margin-bottom:15px;">
      <div class="card-header"><h4><i class="fa fa-archive"></i> Stock par vendeur</h4></div>
      <div class="card-body">
        <?php if (empty($managerSellersData)): ?>
          <p class="text-center stock-empty-note"><i class="fa fa-info-circle"></i> <?= isset($_no_seller_registered) ? $_no_seller_registered : 'No vendor registered.' ?></p>
        <?php else: ?>
        <div class="stock-board-grid manager-stock-board-grid">
          <?php foreach ($managerSellersData as $sk => $sd): ?>
          <?php
            $profiles = isset($allSellerStock[$sk]) ? $allSellerStock[$sk] : array();
            $sellerStockTotal = array_sum($profiles);
          ?>
          <div class="stock-board-card">
            <div class="stock-board-card-header">
              <div class="stock-board-card-heading">
                <div class="stock-board-card-title">
                  <i class="fa fa-user-o"></i> <?= htmlspecialchars(isset($sd['name']) ? $sd['name'] : $sk) ?>
                </div>
                <div class="stock-board-card-self-label"><?= htmlspecialchars($sk) ?></div>
              </div>
              <span class="stock-board-total-badge<?= $sellerStockTotal === 0 ? ' empty' : '' ?>">
                <?= $sellerStockTotal ?> vcr
              </span>
            </div>
            <div class="stock-board-card-body">
              <?php if (empty($profiles)): ?>
                <div class="stock-empty-note"><i class="fa fa-inbox"></i> <?= isset($_stock_no_ticket) ? $_stock_no_ticket : 'No tickets available' ?></div>
              <?php else: ?>
                <?php foreach ($profiles as $prof => $qty): ?>
                <div class="stock-profile-row manager-stock-profile-row">
                  <span class="stock-profile-name"><?= htmlspecialchars($prof) ?></span>
                  <span class="stock-profile-qty <?= $qty <= 5 ? 'qty-low' : 'qty-ok' ?>"><?= (int)$qty ?></span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    </div>
    </div>

    <!-- Liste -->
    <div class="row manager-vendors-row manager-vendors-list-row">
    <div class="col-12">
    <div class="card box-bordered manager-vendors-list-card" style="margin-bottom:15px;">
      <div class="card-header"><h4><i class="fa fa-list"></i> <?= isset($_registered_sellers) ? $_registered_sellers : 'Registered Vendors' ?></h4></div>
      <div class="card-body">
        <?php if (empty($managerSellersData)): ?>
          <p class="text-center" style="color:#888;"><i class="fa fa-info-circle"></i> <?= isset($_no_seller_registered) ? $_no_seller_registered : 'No vendor registered.' ?></p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered table-hover portal-table-min-md">
          <thead class="thead-light">
            <tr>
              <th><?= isset($_seller_id) ? $_seller_id : 'ID' ?></th>
              <th><?= isset($_seller_display_name) ? $_seller_display_name : 'Name' ?></th>
              <th><?= isset($_seller_session_router) ? $_seller_session_router : 'Session' ?></th>
              <th><?= isset($_action) ? $_action : 'Action' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($managerSellersData as $su => $sd): ?>
            <tr>
              <td><code><?= htmlspecialchars($su) ?></code></td>
              <td><?= htmlspecialchars($sd['name']) ?></td>
              <td><span class="mgr-session-badge"><?= htmlspecialchars($sd['session']) ?></span></td>
              <td>
                <a href="#chgpass_mgr_<?= htmlspecialchars($su) ?>"
                   class="btn bg-warning btn-sm" title="<?= isset($_password) ? $_password : 'Password' ?>">
                  <i class="fa fa-key"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <!-- Change password modals -->
        <?php foreach ($managerSellersData as $su => $sd): ?>
        <div class="modal-window" id="chgpass_mgr_<?= htmlspecialchars($su) ?>" aria-hidden="true">
          <div>
            <header><h1><i class="fa fa-key"></i> <?= isset($_password) ? $_password : 'Password' ?> — <?= htmlspecialchars($su) ?></h1></header>
            <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
            <form autocomplete="off" method="post" action="./manager.php?action=vendors">
              <?= csrf_field() ?>
              <table class="table">
                <tr>
                  <td><?= isset($_password) ? $_password : 'Password' ?></td>
                  <td><input class="form-control" type="password" name="cp_pass" required></td>
                </tr>
                <tr>
                  <td colspan="2">
                    <input type="hidden" name="cp_user" value="<?= htmlspecialchars($su) ?>">
                    <button type="submit" name="change_pass" class="btn bg-primary">
                      <i class="fa fa-save"></i> <?= isset($_save) ? $_save : 'Save' ?>
                    </button>
                  </td>
                </tr>
              </table>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    </div>
    </div>

    <!-- Ajouter vendeur -->
    <div class="row manager-vendors-row manager-vendors-form-row">
    <div class="col-12">
    <div class="card box-bordered manager-vendors-form-card">
      <div class="card-header"><h4><i class="fa fa-user-plus"></i> <?= isset($_add_seller) ? $_add_seller : 'Add Vendor' ?></h4></div>
      <div class="card-body">
        <form autocomplete="off" method="post" action="./manager.php?action=vendors">
          <div class="row manager-vendors-row manager-vendors-form-grid">
            <div class="admin-transfer-group col-6 col-box-12 manager-bootstrap-col">
              <label class="transfer-label"><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?> <small>(a-z,0-9,_)</small></label>
              <input class="form-control" type="text" name="new_user" pattern="[a-zA-Z0-9_]+" placeholder="ex: korotoum" required>
            </div>
            <div class="admin-transfer-group col-6 col-box-12 manager-bootstrap-col">
              <label class="transfer-label"><?= isset($_password) ? $_password : 'Password' ?></label>
              <input class="form-control" type="password" name="new_pass" placeholder="Password" required>
            </div>
            <div class="admin-transfer-group col-6 col-box-12 manager-bootstrap-col">
              <label class="transfer-label"><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></label>
              <input class="form-control" type="text" name="new_name" placeholder="ex: Korotoum Market" required>
            </div>
            <div class="admin-transfer-group col-6 col-box-12 manager-bootstrap-col">
              <label class="transfer-label"><?= isset($_seller_session_router) ? $_seller_session_router : 'Session' ?></label>
              <input type="hidden" name="new_session" value="<?= htmlspecialchars($manager_session_name) ?>">
              <div class="portal-note-card" style="padding:12px 14px;text-align:left;">
                <span class="mgr-session-badge"><?= htmlspecialchars($manager_session_name) ?></span>
                <small style="display:block;margin-top:6px;">Les vendeurs créés par ce gérant restent sur sa session routeur.</small>
              </div>
            </div>
          </div>
          <?= csrf_field() ?>
          <button type="submit" name="add_seller" class="btn bg-primary">
            <i class="fa fa-save"></i> <?= isset($_add_seller) ? $_add_seller : 'Add Vendor' ?>
          </button>
        </form>
      </div>
    </div>
    </div>
    </div>

  </div>
  </div>
</div>
</div></div>

<?php elseif ($action === 'logs'): ?>
<!-- ══════════════════════════ JOURNAL DES TRANSFERTS ══════════════════════ -->
<div class="row"><div class="col-12">
<div class="card">
  <div class="card-header">
    <h3><i class="fa fa-history"></i> <?= isset($_transfer_logs) ? $_transfer_logs : 'Transfer Log' ?></h3>
  </div>
  <div class="card-body">
    <?php $logs = get_transfer_logs(200); ?>
    <?php if (empty($logs)): ?>
      <p class="text-center" style="color:#888;padding:20px;">
        <i class="fa fa-info-circle"></i> <?= isset($_transfer_log_empty) ? $_transfer_log_empty : 'No transfers recorded yet.' ?>
      </p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table table-bordered portal-table-min-md" style="font-size:13px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_date) ? $_date : 'Date' ?></th>
          <th><?= isset($_transfer_from_col) ? $_transfer_from_col : 'From' ?></th>
          <th><?= isset($_transfer_to) ? $_transfer_to : 'To' ?></th>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center"><?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></th>
          <th><?= isset($_transfer_by) ? $_transfer_by : 'By' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td style="white-space:nowrap;"><?= htmlspecialchars($log['ts']) ?></td>
          <td>
            <b><?= htmlspecialchars($log['from']) ?></b>
            <small class="portal-muted-light" style="color:#999;display:block;"><code><?= htmlspecialchars($log['from_key']) ?></code></small>
          </td>
          <td>
            <b><?= htmlspecialchars($log['to']) ?></b>
            <small class="portal-muted-light" style="color:#999;display:block;"><code><?= htmlspecialchars($log['to_key']) ?></code></small>
          </td>
          <td><?= htmlspecialchars($log['profile']) ?></td>
          <td class="text-center"><b><?= (int)$log['qty'] ?></b></td>
          <td>
            <span class="badge" style="background:<?= $log['by_role']==='admin' ? '#007bff' : ($log['by_role']==='manager' ? '#8e44ad' : '#27ae60') ?>;color:#fff;">
              <?= htmlspecialchars($log['by_role']) ?>
            </span>
            <small><?= htmlspecialchars($log['by_user']) ?></small>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <small style="color:#cbd5e1;"><i class="fa fa-info-circle"></i> <?= count($logs) ?> <?= isset($_transfer_log_entries) ? $_transfer_log_entries : 'entries' ?></small>
    <?php endif; ?>
  </div>
</div>
</div></div>

<?php elseif ($action === 'tickets'): ?>
<!-- ═════════════════════════════════════ PAGE TICKETS GÉRANT ═════════════════════ -->
<div class="row manager-tickets-shell"><div class="col-12">
<?php
  $hotspotProfiles = array();
  $ticketServers = array();
  if ($API) {
      $allProfs = $API->comm('/ip/hotspot/user/profile/print');
      if (is_array($allProfs)) {
          foreach ($allProfs as $p) {
              $pname = isset($p['name']) ? trim($p['name']) : '';
              if ($pname === '') continue;
              $price = '';
              $valid = '';
              $lock  = '';
              if (!empty($p['on-login'])) {
                  $parts = explode(',', $p['on-login']);
                  $price = isset($parts[2]) ? trim($parts[2]) : '';
                  $valid = isset($parts[3]) ? trim($parts[3]) : '';
                  $lock  = isset($parts[6]) ? trim($parts[6]) : '';
              }
              $hotspotProfiles[] = array('name' => $pname, 'price' => $price, 'valid' => $valid, 'lock' => $lock);
          }
      }
      $ticketServers = $API->comm('/ip/hotspot/print');
  }

  $sellersList = array();
  foreach ($managerSellersData as $sk => $sd) {
      $sellersList[] = array(
          'key'  => $sk,
          'name' => isset($sd['name']) ? $sd['name'] : $sk,
          'stock' => isset($allSellerStock[$sk]) ? array_sum($allSellerStock[$sk]) : 0,
      );
  }
  $managerGlobalStockCount = array_sum($globalStock);
?>

<div class="card manager-tickets-row manager-tickets-production-row">
  <div class="card-header">
    <h3><i class="fa fa-ticket"></i> Générer &amp; imprimer des tickets</h3>
  </div>
  <div class="card-body">
  <div class="container-fluid manager-tickets-container">
    <div class="portal-admin-shell">
      <div class="portal-toolbar">
        <div class="portal-toolbar-group">
          <h4 class="portal-section-title"><i class="fa fa-magic"></i> Atelier de production gérant</h4>
          <span class="portal-chip portal-chip-blue"><?= count($hotspotProfiles) ?> profils</span>
          <span class="portal-chip portal-chip-green"><?= count($managerSellersData) ?> vendeurs</span>
          <span class="portal-chip portal-chip-purple"><?= $managerGlobalStockCount ?> en stock gérant</span>
        </div>
      </div>

      <div class="portal-note-card note-card-lg mb-20">
        <strong>Deux modes au moment de la génération :</strong>
        <span style="display:block;margin-top:6px;">attribuer immédiatement le lot à un vendeur pour impression directe, ou le garder en <b>stock gérant</b> pour le redistribuer plus tard.</span>
      </div>

      <?php if (empty($hotspotProfiles)): ?>
        <div class="portal-note-card note-card-md">
          <i class="fa fa-warning"></i> <?= isset($_no_profile) ? $_no_profile : 'No hotspot profile available on this router.' ?>
        </div>
      <?php else: ?>
      <form method="post" action="./hotspot/generateuser.php" class="portal-card-section portal-card-section-tight form-wrap-xl m-center-auto">
        <?= csrf_field() ?>
        <input type="hidden" name="session" value="<?= htmlspecialchars($manager_session_name) ?>">
        <input type="hidden" name="user" value="vc">
        <input type="hidden" name="char" value="mix2">
        <input type="hidden" name="timelimit" value="">
        <input type="hidden" name="datalimit" value="">
        <input type="hidden" name="mbgb" value="1048576">

        <div class="row manager-tickets-row manager-tickets-form-row">
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Serveur</label>
            <select name="server" class="form-control" required>
              <option value="all">all</option>
              <?php if (is_array($ticketServers)): ?>
                <?php foreach ($ticketServers as $serverRow): ?>
                  <?php $serverName = isset($serverRow['name']) ? trim($serverRow['name']) : ''; if ($serverName === '') continue; ?>
                  <option value="<?= htmlspecialchars($serverName) ?>"><?= htmlspecialchars($serverName) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Profil</label>
            <select name="profile" required class="form-control" id="mgrTicketProfile">
              <option value="">— Sélectionner —</option>
              <?php foreach ($hotspotProfiles as $hp): ?>
                <option value="<?= htmlspecialchars($hp['name']) ?>"
                        data-price="<?= htmlspecialchars($hp['price']) ?>"
                        data-valid="<?= htmlspecialchars($hp['valid']) ?>"
                        data-lock="<?= htmlspecialchars($hp['lock']) ?>">
                  <?= htmlspecialchars($hp['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Quantité</label>
            <input type="number" name="qty" min="1" max="<?= (int)mikhmon_generate_ticket_limit() ?>" value="10" required class="form-control">
            <small style="display:block;color:#aaa;margin-top:4px;">Max <?= (int)mikhmon_generate_ticket_limit() ?> tickets par lot.</small>
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Longueur du code</label>
            <select name="userl" class="form-control">
              <option value="4">4</option>
              <option value="5">5</option>
              <option value="6" selected>6</option>
              <option value="7">7</option>
              <option value="8">8</option>
            </select>
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Préfixe</label>
            <input type="text" name="prefix" maxlength="6" placeholder="ex: ADA" class="form-control">
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Destination du lot</label>
            <select name="manager_assign_mode" class="form-control" id="mgrAssignMode">
              <option value="global">Stock gérant</option>
              <option value="seller">Attribuer à un vendeur</option>
            </select>
          </div>
          <div class="portal-filter-item col-3 col-box-12 manager-bootstrap-col" id="mgrSellerTargetWrap" style="display:none;">
            <label class="transfer-label">Vendeur destinataire</label>
            <select name="seller_id" class="form-control" id="mgrSellerTarget">
              <option value="">— Sélectionner —</option>
              <?php foreach ($sellersList as $sl): ?>
                <option value="<?= htmlspecialchars($sl['key']) ?>"><?= htmlspecialchars($sl['name']) ?> (stock: <?= (int)$sl['stock'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="portal-filter-item portal-filter-item-wide col-6 col-box-12 manager-bootstrap-col">
            <label class="transfer-label">Commentaire / nom du lot</label>
            <input type="text" name="adcomment" class="form-control" placeholder="ex: lot-03H-2026-05-07">
          </div>
        </div>

        <div id="mgrTicketProfileInfo" class="portal-note-card note-card-sm mt-14 hidden"></div>

        <div class="portal-toolbar" style="justify-content:center;margin-top:18px;">
          <button type="submit" class="btn bg-primary btn-generate">
            <i class="fa fa-bolt"></i> Générer puis imprimer
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  </div>
</div>

<div class="container-fluid manager-tickets-summary-container">
<div class="row manager-tickets-row manager-tickets-summary-row manager-tickets-grid" style="margin-top:18px;">
  <div class="col-3 col-box-6 manager-bootstrap-col">
  <div class="portal-summary-card portal-summary-card-blue manager-color-card bg-blue">
    <span class="portal-summary-label">Stock gérant</span>
    <span class="portal-summary-value"><?= $managerGlobalStockCount ?></span>
    <span class="portal-summary-sub">lots non encore attribués</span>
  </div>
  </div>
  <div class="col-3 col-box-6 manager-bootstrap-col">
  <div class="portal-summary-card portal-summary-card-green manager-color-card bg-green">
    <span class="portal-summary-label">Vendeurs actifs</span>
    <span class="portal-summary-value"><?= count($managerSellersData) ?></span>
    <span class="portal-summary-sub">même session que le gérant</span>
  </div>
  </div>
  <div class="col-3 col-box-6 manager-bootstrap-col">
  <div class="portal-summary-card portal-summary-card-violet manager-color-card bg-yellow">
    <span class="portal-summary-label">Profils disponibles</span>
    <span class="portal-summary-value"><?= count($hotspotProfiles) ?></span>
    <span class="portal-summary-sub">forfaits MikroTik chargés</span>
  </div>
  </div>
  <div class="col-3 col-box-6 manager-bootstrap-col">
  <div class="portal-summary-card portal-summary-card-violet manager-color-card bg-red">
    <span class="portal-summary-label">Stock vendeur total</span>
    <span class="portal-summary-value"><?= array_sum(array_map('array_sum', $allSellerStock)) ?></span>
    <span class="portal-summary-sub">tickets transférés et non utilisés</span>
  </div>
  </div>
</div>
</div>
</div></div>

<script>
(function(){
  var sel = document.getElementById('mgrTicketProfile');
  var info = document.getElementById('mgrTicketProfileInfo');
  var mode = document.getElementById('mgrAssignMode');
  var sellerWrap = document.getElementById('mgrSellerTargetWrap');
  var sellerSelect = document.getElementById('mgrSellerTarget');
  function syncTicketProfileInfo() {
    if (!sel || !info) return;
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) {
      info.style.display = 'none';
      info.innerHTML = '';
      return;
    }
    var price = o.getAttribute('data-price') || '';
    var valid = o.getAttribute('data-valid') || '';
    var lock  = o.getAttribute('data-lock')  || '';
    info.innerHTML = '<i class="fa fa-clock-o"></i> Validité <b>' + (valid || '—') + '</b>'
      + (price ? ' · <i class="fa fa-money"></i> Prix <b><?= addslashes($currency) ?> ' + price + '</b>' : '')
      + (lock ? ' · <i class="fa fa-lock"></i> MAC-lock <b>' + lock + '</b>' : '');
    info.style.display = 'block';
  }
  function syncAssignMode() {
    if (!mode || !sellerWrap || !sellerSelect) return;
    var needsSeller = mode.value === 'seller';
    sellerWrap.style.display = needsSeller ? '' : 'none';
    sellerSelect.required = needsSeller;
    if (!needsSeller) sellerSelect.value = '';
  }
  if (sel) {
    sel.addEventListener('change', syncTicketProfileInfo);
    syncTicketProfileInfo();
  }
  if (mode) {
    mode.addEventListener('change', syncAssignMode);
    syncAssignMode();
  }
})();
</script>

<?php endif; // end action switch ?>

</div><!-- main-container -->
</div><!-- main -->

<?php endif; // end manager_logged_in ?>

<!-- Confirmation modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;justify-content:center;align-items:center;padding:16px;">
  <div style="background:#fff;border-radius:10px;padding:28px 24px;max-width:380px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;">
    <h3 style="margin:0 0 8px;font-size:17px;"><i class="fa fa-exchange" style="color:#8e44ad;"></i> <span id="confirmModalTitle"></span></h3>
    <p id="confirmModalBody" style="color:#555;margin-bottom:20px;font-size:15px;line-height:1.5;"></p>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button id="confirmCancel" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#eee;color:#555;">
        <i class="fa fa-times"></i> <?= isset($_cancel) ? $_cancel : 'Cancel' ?>
      </button>
      <button id="confirmOk" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#8e44ad;color:#fff;">
        <i class="fa fa-check"></i> <?= isset($_confirm) ? $_confirm : 'Confirm' ?>
      </button>
    </div>
  </div>
</div>

<script src="js/mikhmon-ui.<?= $theme ?>.min.js"></script>
<script src="js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")) ?>"></script>
<?php if ($manager_logged_in): ?>
<script>
$(document).ready(function(){
  function openSidenav() {
    $("#sidenav").css("width","220px");
    if ($(window).width() <= 750) {
      $("#sidenav-overlay").addClass("active");
      $("#closeSidenav").css("display","block");
    }
    $("#sidenav a.menu").css("display","");
  }
  function closeSidenav() {
    $("#sidenav").css("width","0");
    $("#sidenav-overlay").removeClass("active");
    $("#closeSidenav").css("display","none");
    $("#openNav").css("display","");
    $("#closeNav").css("display","");
  }
  $("#openNav").on("click", openSidenav);
  $("#closeNav").on("click", closeSidenav);
  $("#closeSidenav").on("click", closeSidenav);
  $("#sidenav-overlay").on("click", closeSidenav);

  // ── Auto-refresh overview / accounting (toutes les 60s) ──────────────────
  <?php if (in_array($action, ['overview','accounting'])): ?>
  var mgrRefreshTimer = setTimeout(function(){
    window.location.reload();
  }, 60000);
  // Afficher un compte à rebours discret
  var remaining = 60;
  var tick = setInterval(function(){
    remaining--;
    var el = document.getElementById('mgrRefreshCountdown');
    if (el) el.textContent = remaining + 's';
    if (remaining <= 0) clearInterval(tick);
  }, 1000);
  <?php endif; ?>

  // ── Confirmation modal pour les transferts ────────────────────────────────
  var pending = null;
  var modal   = document.getElementById('confirmModal');
  var form    = document.getElementById('mgrTransferForm');
  if (form && modal) {
    form.addEventListener('submit', function(e) {
      var src   = form.querySelector('[name="src_seller"]');
      var prof  = document.getElementById('transferProf');
      var qty   = form.querySelector('[name="transfer_qty"]');
      var dst   = form.querySelector('[name="dst_seller"]');
      if (!src || !src.value || !prof || !prof.value || !dst || !dst.value) return;
      e.preventDefault();
      var srcName = src.options[src.selectedIndex].text;
      var dstName = dst.options[dst.selectedIndex].text;
      document.getElementById('confirmModalTitle').textContent =
        '<?= addslashes(isset($_transfer_submit) ? $_transfer_submit : "Transfer") ?>';
      document.getElementById('confirmModalBody').innerHTML =
        '<b>' + qty.value + '</b> ticket(s) [' + prof.value + ']<br>'+
        srcName + ' → <b>' + dstName + '</b>';
      modal.style.display = 'flex';
      pending = form;
    });
  }
  document.getElementById('confirmOk')?.addEventListener('click', function(){
    modal.style.display='none'; if(pending) pending.submit();
  });
  document.getElementById('confirmCancel')?.addEventListener('click', function(){
    modal.style.display='none'; pending=null;
  });
  if (modal) modal.addEventListener('click', function(e){
    if(e.target===modal){ modal.style.display='none'; pending=null; }
  });
});
</script>
<?php endif; ?>
</div><!-- wrapper -->
</body>
</html>
