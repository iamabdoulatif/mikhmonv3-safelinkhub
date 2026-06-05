<?php
/*
 * Portail Vendeurs - MIKHMON
 * Permet aux vendeurs de consulter uniquement leurs propres ventes de tickets.
 * Les revenus journaliers/mensuels ne sont PAS visibles.
 */
session_start();
error_reporting(0);
ob_start("ob_gzhandler");

$url    = $_SERVER['REQUEST_URI'];
$action = isset($_GET['action']) && $_GET['action'] !== '' ? $_GET['action'] : 'dashboard';
$idbl   = isset($_GET['idbl'])   ? $_GET['idbl']   : '';
$idhr   = isset($_GET['idhr'])   ? $_GET['idhr']   : '';
$prefix = isset($_GET['prefix']) ? $_GET['prefix']  : '';

// Charger les dépendances de base
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
include_once('./include/auth.php');
include_once('./include/csrf.php');
include_once('./include/transfer_log.php');
include_once('./include/transfer_requests.php');
include_once('./include/accounting_notifications.php');

if ($_SESSION['theme'] == "") {
    $theme      = $theme;
    $themecolor = $themecolor;
} else {
    $theme      = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
}

// ── Déconnexion vendeur ──────────────────────────────────────────────────────
if ($action == 'logout') {
    unset($_SESSION['seller_username'], $_SESSION['seller_name'], $_SESSION['seller_session'], $_SESSION[mikhmon_revenue_visibility_key('seller')]);
    ob_end_clean();
    header("Location: ./sellers.php");
    exit;
}

// ── Traitement de la connexion ───────────────────────────────────────────────
$login_error = '';
if (isset($_POST['seller_login'])) {
    $su = trim($_POST['seller_user']);
    $sp = $_POST['seller_pass'];
    if (isset($sellers_data[$su]) && mikhmon_account_password_matches($sp, $sellers_data[$su]['password'])) {
        $_SESSION['seller_username'] = $su;
        $_SESSION['seller_name']     = $sellers_data[$su]['name'];
        $_SESSION['seller_session']  = $sellers_data[$su]['session'];
        ob_end_clean();
        header("Location: ./sellers.php?action=dashboard");
        exit;
    } else {
        $login_error = '<div style="padding:8px;border-radius:5px;margin-top:8px;" class="bg-danger"><i class="fa fa-ban"></i> ' . ($_please_login ?? 'Invalid credentials') . '</div>';
    }
}

// ── Vérifier si le vendeur est connecté ─────────────────────────────────────
$seller_logged_in = isset($_SESSION['seller_username'])
    && isset($sellers_data[$_SESSION['seller_username']]);

if ($seller_logged_in) {
    mikhmon_revenue_handle_toggle('seller');
}
$sellerRevenueVisible = mikhmon_revenue_is_visible('seller');

// ── Si connecté : charger les données de vente ──────────────────────────────
$getData    = array();
$TotalReg   = 0;
$seller_session_name = '';
$seller_session_missing = false;
$seller_session_message = '';
$seller_router_connected = false;
$seller_connection_error = '';
$sellerShouldLoadRouterData = ($action !== 'dashboard');
date_default_timezone_set('UTC');

if ($seller_logged_in) {
    $sellerUsername      = $_SESSION['seller_username'];
    $sellerName          = $_SESSION['seller_name'];
    $seller_session_name = $_SESSION['seller_session'];

    // Charger la config du routeur lié à ce vendeur
    include('./include/config.php');
    $session = $seller_session_name;
    include('./include/readcfg.php');
    if (empty($mikhmon_router_session_valid)) {
        $seller_session_missing = true;
        $seller_session_message = 'La session routeur "' . $seller_session_name . '" est introuvable dans la configuration locale.';
    }

    // Configurer le fuseau horaire
    if (!$seller_session_missing && $sellerShouldLoadRouterData) {
        $API = new RouterosAPI();
        $API->debug = false;
        $API->timeout = 2;
        $API->attempts = 1;
        $API->delay = 0;
    }
    if (!$seller_session_missing && $sellerShouldLoadRouterData) {
        $seller_router_connected = $API->connect($iphost, $userhost, decrypt($passwdhost));
        if (!$seller_router_connected) {
            $seller_connection_error = 'Connexion impossible au routeur "' . $seller_session_name . '" (' . $iphost . ':8728). Vérifiez l’IP, le service API MikroTik et les identifiants.';
        }
    }
    if ($seller_router_connected) {
        $gettimezone = $API->comm("/system/clock/print");
        $timezone    = mikhmon_safe_timezone(isset($gettimezone[0]['time-zone-name']) ? $gettimezone[0]['time-zone-name'] : 'UTC');
        date_default_timezone_set($timezone);

        // Récupérer tous les scripts de vente
        $getSales = $API->comm("/system/script/print", array("?comment" => "mikhmon"));

        // Filtrer par mois/jour si demandé
        if (strlen($idhr) > 0) {
            $allSales = mikhmon_filter_sale_scripts($getSales, $idhr, '');
        } elseif (strlen($idbl) > 0) {
            $allSales = mikhmon_filter_sale_scripts($getSales, '', $idbl);
        } else {
            $allSales = mikhmon_filter_sale_scripts($getSales, '', '');
        }

        // Filtrer uniquement les ventes du vendeur connecté
        // Le commentaire du voucher doit se terminer par le nom du vendeur
        // (ex: "up-123-05.05.26-alpha" → dernière partie = "alpha")
        foreach ($allSales as $sale) {
            $comment   = strtolower(trim($sale['comment']));
            $sellerKey = strtolower($sellerUsername);
            $suffix    = '-' . $sellerKey;
            if ($comment === $sellerKey || substr($comment, -strlen($suffix)) === $suffix) {
                $getData[] = $sale;
            }
        }
        $TotalReg = count($getData);
    }
}

// ── Chiffre d'affaires & Commission du vendeur ───────────────────────────────
$totalRevenue            = 0.0;
$sellerCommissionRate    = 0;
$sellerCommissionAmount  = 0.0;
$totalNetRevenue         = 0.0;
// Stats aujourd'hui / ce mois (calculées sur getData = ventes sans filtre pour dashboard)
$todaySalesCount  = 0;  $todayRevenue  = 0.0;
$monthSalesCount  = 0;  $monthRevenue  = 0.0;
if ($seller_logged_in) {
    $today_str = mikhmon_normalize_sale_date(date("Y-m-d"));
    $curMonth  = strtolower(date("M")) . date("Y"); // ex: may2026
    foreach ($getData as $sale) {
        $price = mikhmon_parse_money_amount(isset($sale['price']) ? $sale['price'] : 0);
        $totalRevenue += $price;
        $sdate = mikhmon_normalize_sale_date(isset($sale['date']) ? $sale['date'] : '');
        if ($sdate === $today_str) {
            $todaySalesCount++;
            $todayRevenue += $price;
        }
        $sm = isset($sale['month_key']) ? $sale['month_key'] : '';
        if ($sm === '') {
            $sm = mikhmon_sale_month_key(isset($sale['date']) ? $sale['date'] : '');
        }
        if ($sm === $curMonth) {
            $monthSalesCount++;
            $monthRevenue += $price;
        }
    }
    if ($monthSalesCount === 0 && strlen($idbl) === 0 && $TotalReg > 0) {
        $monthSalesCount = $TotalReg;
        $monthRevenue    = $totalRevenue;
    }
    $sellerCommissionRate   = isset($sellers_data[$sellerUsername]['commission']) ? (int)$sellers_data[$sellerUsername]['commission'] : 0;
    $sellerCommissionAmount = $totalRevenue * $sellerCommissionRate / 100;
    $totalNetRevenue        = $totalRevenue - $sellerCommissionAmount;
}

// ── Stock disponible (tickets non utilisés) du vendeur connecté ─────────────
$sellerStock      = array(); // ['profile' => count]
$sellerStockUsers = array(); // tous les utilisateurs non utilisés du vendeur

if ($seller_logged_in && isset($API)) {
    $unusedAll = $API->comm("/ip/hotspot/user/print", array("?uptime" => "0s"));
    if (is_array($unusedAll)) {
        $sellerKey = strtolower($sellerUsername);
        $sfxKey    = '-' . $sellerKey;
        foreach ($unusedAll as $u) {
            $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
            if ($cmt === $sellerKey || substr($cmt, -strlen($sfxKey)) === $sfxKey) {
                $prof = isset($u['profile']) ? $u['profile'] : '(unknown)';
                if (!isset($sellerStock[$prof])) $sellerStock[$prof] = 0;
                $sellerStock[$prof]++;
                $sellerStockUsers[] = $u;
            }
        }
    }
}

// ── Traitement du transfert de stock ────────────────────────────────────────
$transfer_msg   = '';
$transfer_error = '';

if ($seller_logged_in && $action === 'transfer' && isset($_POST['do_transfer'])) {
    csrf_guard();
    $targetSeller    = trim($_POST['target_seller']);
    $transferProfile = trim($_POST['transfer_profile']);
    $transferQty     = max(1, (int)$_POST['transfer_qty']);

    if (!isset($sellers_data[$targetSeller])) {
        $transfer_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Invalid vendor.';
    } elseif ($transferProfile === '') {
        $transfer_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile.';
    } elseif (!isset($sellerStock[$transferProfile]) || $sellerStock[$transferProfile] < $transferQty) {
        $transfer_error = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock.';
    } else {
        // Filtrer les tickets du bon profil puis chercher un bloc consécutif
        $profileUsers = array();
        foreach ($sellerStockUsers as $u) {
            if (isset($u['profile']) && $u['profile'] === $transferProfile) {
                $profileUsers[] = $u;
            }
        }
        $toTransfer = mikhmon_select_sequential($profileUsers, $transferQty);
        if ($toTransfer === false) {
            $fmt = isset($_transfer_no_consecutive)
                ? $_transfer_no_consecutive
                : 'Transfer failed: no consecutive sequence of %d ticket(s) for profile %s (%d in stock, no continuous sequence).';
            $transfer_error = sprintf($fmt, $transferQty, htmlspecialchars($transferProfile), count($profileUsers));
        } else {
            $transferred = 0;
            foreach ($toTransfer as $u) {
                $API->comm("/ip/hotspot/user/set", array(
                    ".id"     => $u['.id'],
                    "comment" => mikhmon_comment_assign_seller(isset($u['comment']) ? $u['comment'] : '', $targetSeller, $sellers_data)
                ));
                $transferred++;
            }
            // Rafraîchir le stock local après transfert
            foreach ($toTransfer as $u) {
                $sellerStock[$transferProfile]--;
                if ($sellerStock[$transferProfile] <= 0) unset($sellerStock[$transferProfile]);
                foreach ($sellerStockUsers as $k => $su) {
                    if ($su['.id'] === $u['.id']) { unset($sellerStockUsers[$k]); break; }
                }
            }
            $sellerStockUsers = array_values($sellerStockUsers);
            $targetName = $sellers_data[$targetSeller]['name'];
            $transfer_msg = $transferred . ' ' . (isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to')
                . ' <b>' . htmlspecialchars($targetName) . '</b>';
            if ($transferred > 0) {
                log_transfer(
                    $sellerUsername, $sellerName,
                    $targetSeller,   $targetName,
                    $transferProfile, $transferred,
                    'seller', $sellerUsername
                );
            }
        }
    }
}

// ── Demande de transfert de stock (vendeur → vendeur) ────────────────────────
$req_msg   = '';
$req_error = '';
if ($seller_logged_in && $action === 'request-transfer' && isset($_POST['do_request'])) {
    csrf_guard();
    $req_to      = trim(isset($_POST['req_to'])      ? $_POST['req_to']      : '');
    $req_profile = trim(isset($_POST['req_profile']) ? $_POST['req_profile'] : '');
    $req_qty     = max(1, (int)(isset($_POST['req_qty']) ? $_POST['req_qty'] : 1));
    if (!isset($sellers_data[$req_to])) {
        $req_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Vendeur invalide.';
    } elseif ($req_to === $sellerUsername) {
        $req_error = isset($_transfer_req_to_self_err) ? $_transfer_req_to_self_err : 'You cannot request a transfer to yourself.';
    } elseif ($req_profile === '') {
        $req_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Sélectionnez un profil.';
    } elseif ($req_qty < 1) {
        $req_error = isset($_transfer_req_invalid_qty) ? $_transfer_req_invalid_qty : 'Invalid quantity.';
    } else {
        tr_create($sellerUsername, $sellerName, $req_to, $sellers_data[$req_to]['name'], $req_profile, $req_qty);
        $_sent_to_lbl = isset($_transfer_req_sent_to) ? $_transfer_req_sent_to : 'request sent to';
        $req_msg = $req_qty . ' × <b>' . htmlspecialchars($req_profile) . '</b> — ' . $_sent_to_lbl . ' <b>' . htmlspecialchars($sellers_data[$req_to]['name']) . '</b>.';
    }
    // Afficher la page stock-board après traitement
    if (!$req_error) $action = 'stock-board';
}

// ── Réponse à une demande (accepter / refuser) ───────────────────────────────
$accept_msg   = '';
$accept_error = '';
if ($seller_logged_in && $action === 'respond-request' && isset($_POST['req_id'])) {
    csrf_guard();
    $req_id     = trim(isset($_POST['req_id'])     ? $_POST['req_id']     : '');
    $req_action = trim(isset($_POST['req_action']) ? $_POST['req_action'] : '');
    $req_data   = tr_get_by_id($req_id);

    if (!$req_data || $req_data['to_key'] !== $sellerUsername || $req_data['status'] !== 'pending') {
        $accept_error = isset($_transfer_req_not_found) ? $_transfer_req_not_found : 'Request not found or already processed.';
    } elseif ($req_action === 'decline') {
        tr_respond($req_id, 'declined', $sellerUsername);
        $_declined_lbl = isset($_transfer_req_declined_lbl) ? $_transfer_req_declined_lbl : 'declined';
        $accept_msg = 'Request from <b>' . htmlspecialchars($req_data['from_name']) . '</b> ' . $_declined_lbl . '.';
    } elseif ($req_action === 'accept') {
        $reqProfile = $req_data['profile'];
        $reqQty     = (int)$req_data['qty'];
        $reqFrom    = $req_data['from_key'];
        if (!isset($sellerStock[$reqProfile]) || $sellerStock[$reqProfile] < $reqQty) {
            $_insuf_lbl = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock';
            $accept_error = $_insuf_lbl . ': ' . (isset($sellerStock[$reqProfile]) ? $sellerStock[$reqProfile] : 0)
                . ' available, ' . $reqQty . ' requested [' . htmlspecialchars($reqProfile) . '].';
        } else {
            // Exécuter le transfert : mes tickets → vendeur qui a demandé (bloc consécutif)
            $profileUsers = [];
            foreach ($sellerStockUsers as $u) {
                if (isset($u['profile']) && $u['profile'] === $reqProfile) {
                    $profileUsers[] = $u;
                }
            }
            $toTransfer  = mikhmon_select_sequential($profileUsers, $reqQty);
            $transferred = 0;
            if ($toTransfer !== false) {
                foreach ($toTransfer as $u) {
                    $API->comm("/ip/hotspot/user/set", [
                        ".id"     => $u['.id'],
                        "comment" => mikhmon_comment_assign_seller(
                            isset($u['comment']) ? $u['comment'] : '', $reqFrom, $sellers_data
                        ),
                    ]);
                    $transferred++;
                }
            }
            if ($transferred > 0) {
                // Mettre à jour le stock local
                foreach ($toTransfer as $u) {
                    $sellerStock[$reqProfile]--;
                    if ($sellerStock[$reqProfile] <= 0) unset($sellerStock[$reqProfile]);
                    foreach ($sellerStockUsers as $k => $su) {
                        if ($su['.id'] === $u['.id']) { unset($sellerStockUsers[$k]); break; }
                    }
                }
                $sellerStockUsers = array_values($sellerStockUsers);
                tr_respond($req_id, 'accepted', $sellerUsername);
                log_transfer(
                    $sellerUsername, $sellerName,
                    $reqFrom, $sellers_data[$reqFrom]['name'],
                    $reqProfile, $transferred, 'seller_request', $sellerUsername
                );
                $_done_lbl = isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to';
                $accept_msg = $transferred . ' <b>' . htmlspecialchars($reqProfile) . '</b> ' . $_done_lbl . ' <b>'
                    . htmlspecialchars($req_data['from_name']) . '</b>.';
            } else {
                $fmt = isset($_transfer_no_consecutive)
                    ? $_transfer_no_consecutive
                    : 'Transfer failed: no consecutive sequence of %d ticket(s) for profile %s (%d in stock, no continuous sequence).';
                $accept_error = sprintf($fmt, $reqQty, htmlspecialchars($reqProfile), count($profileUsers));
            }
        }
    }
    // Rester sur le stock board après réponse
    $action = 'stock-board';
}

// ── Helpers pour le filtre date ──────────────────────────────────────────────
$idbls  = array(1=>"jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec");
$idblf  = array(1=>"Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec");

if (strlen($idhr) > 0) {
    $filedownload = $idhr;
    $shd = "inline-block";
} elseif (strlen($idbl) > 0) {
    $filedownload = $idbl;
    $shd = "inline-block";
} else {
    $filedownload = "tous";
    $shd = "none";
}

// ── Stock de tous les vendeurs (même session, même routeur) ─────────────────
// Utilisé pour : stock board, demandes de transfert, dashboard
$allSellersStock = [];
if ($seller_logged_in && isset($API)) {
    foreach ($sellers_data as $sk => $sd) {
        $sdSession = isset($sd['session']) ? $sd['session'] : '';
        if ($sdSession === $seller_session_name) {
            $allSellersStock[$sk] = [
                'name'    => isset($sd['name']) ? $sd['name'] : $sk,
                'stock'   => [],
                'profiles' => [],
                'is_self' => ($sk === $sellerUsername),
            ];
        }
    }
    if (count($allSellersStock) > 1 || $action === 'stock-board') {
        $unusedAllSellers = $API->comm("/ip/hotspot/user/print", ["?uptime" => "0s"]);
        if (is_array($unusedAllSellers)) {
            foreach ($unusedAllSellers as $u) {
                $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                foreach ($allSellersStock as $sk => &$sdata) {
                    $sfx = '-' . strtolower($sk);
                    if ($cmt === strtolower($sk) || substr($cmt, -strlen($sfx)) === $sfx) {
                        $prof = isset($u['profile']) ? $u['profile'] : '(unknown)';
                        if (!isset($sdata['stock'][$prof])) $sdata['stock'][$prof] = 0;
                        $sdata['stock'][$prof]++;
                        break;
                    }
                }
                unset($sdata);
            }
        }
    }

    $availableStockBySeller = [];
    foreach ($allSellersStock as $sk => $sdata) {
        $availableStockBySeller[$sk] = $sdata['stock'];
    }
    $sellerProfileMetrics = mikhmon_seller_profile_metrics(
        isset($getSales) ? $getSales : [],
        $availableStockBySeller,
        $sellers_data
    );
    foreach ($allSellersStock as $sk => &$sdata) {
        $sdata['profiles'] = isset($sellerProfileMetrics[$sk]) ? $sellerProfileMetrics[$sk] : [];
    }
    unset($sdata);
}

// ── Demandes en attente pour le vendeur connecté (notifications) ─────────────
$pendingRequests      = [];
$pendingRequestsCount = 0;
$accountingNotifications = [];
if ($seller_logged_in) {
    $pendingRequests      = tr_get_pending_for($sellerUsername);
    $pendingRequestsCount = count($pendingRequests);
    $accountingNotifications = mikhmon_accounting_notifications_for_seller($sellerUsername, $seller_session_name, 3);
}

// ── Identité du routeur ──────────────────────────────────────────────────────
$identity = '';
if ($seller_router_connected) {
    $gi = $API->comm("/system/identity/print");
    $identity = isset($gi[0]['name']) ? $gi[0]['name'] : '';
}
$sellerDashboardUrl = './sellers.php?action=dashboard';
$sellerVoucherPrintUrl = './voucher/print.php?session=' . urlencode($seller_session_name);

if ($seller_logged_in && $action === 'generate') {
    ob_end_clean();
    header("Location: ./sellers.php?action=tickets");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>MIKHMON - <?= $_seller_portal ?></title>
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
/* ── Transfer form grid ─────────────────────────────────────────── */
.transfer-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 18px;
}
.transfer-form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.transfer-label {
    font-weight: bold;
    font-size: 13px;
    color: #555;
    margin-bottom: 2px;
}
.transfer-select {
    width: 100%;
    padding: 8px 10px;
    border-radius: 5px;
    border: 1px solid #ccc;
    font-size: 14px;
    box-sizing: border-box;
}
.btn-transfer {
    display: inline-block;
    padding: 10px 28px;
    background: #20a8d8;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
    transition: background .2s;
    width: 100%;
    max-width: 220px;
}
.btn-transfer:hover { background: #1a8bae; }
.seller-total-row {
    background: #eef6fb;
}
.seller-total-row td {
    color: #1f2d3d !important;
    font-weight: bold;
}
.seller-sales-total-row {
    background: #5b2c8d;
}
.seller-sales-total-row td {
    color: #ffffff !important;
    font-weight: bold;
}
.seller-sales-total-row td.total-gross {
    color: #ffe082 !important;
}
.seller-sales-total-row td.total-commission {
    color: #f3c4ff !important;
}
.seller-sales-total-row td.total-net {
    color: #b8f5cf !important;
}

/* ── Confirmation modal ─────────────────────────────────────────── */
#confirmModal {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:9999; justify-content:center; align-items:center; padding:16px;
}
.confirm-box {
    background:#fff; border-radius:10px; padding:28px 24px;
    max-width:380px; width:100%; box-shadow:0 8px 32px rgba(0,0,0,.2); text-align:center;
}
.confirm-box h3 { margin:0 0 8px; font-size:17px; }
.confirm-box p  { color:#555; margin-bottom:20px; font-size:15px; line-height:1.5; }
.confirm-actions { display:flex; gap:10px; justify-content:center; }
.confirm-actions button { flex:1; padding:10px; border:none; border-radius:6px;
    font-size:15px; font-weight:bold; cursor:pointer; transition:opacity .2s; }
.btn-confirm-ok     { background:#2980b9; color:#fff; }
.btn-confirm-ok:hover { opacity:.85; }
.btn-confirm-cancel { background:#eee; color:#555; }

/* ── Responsive (750px = MIKHMON breakpoint) ─────────────────────── */
@media (max-width: 750px) {
    .transfer-form-grid {
        grid-template-columns: 1fr;
    }
    .transfer-form-group[style*="grid-column"] {
        grid-column: 1 !important;
    }
    .btn-transfer { max-width: 100%; }

    /* Commission banner : stack formula cells on mobile */
    .comm-formula-row {
        flex-direction: column !important;
        gap: 6px !important;
    }
    .comm-formula-op {
        transform: rotate(90deg);
        align-self: center;
    }
    .comm-formula-cell {
        min-width: unset !important;
        width: 100% !important;
    }

    /* Summary cards : make them 2-col on mobile */
    .seller-summary-cards {
        gap: 8px !important;
    }
    .seller-summary-cards > div {
        min-width: calc(50% - 4px) !important;
        flex: 1 1 calc(50% - 4px) !important;
    }

    /* Tickets table font */
    .tickets-table th, .tickets-table td {
        font-size: 12px;
        padding: 4px 6px;
    }
}

/* ── Dashboard stat cards ────────────────────────────────────────── */
.dash-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.dash-card {
    border-radius: 12px;
    padding: 22px 18px 18px;
    color: #fff;
    position: relative;
    overflow: hidden;
    transition: opacity .2s, transform .15s;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 140px;
    text-decoration: none !important;
}
.dash-card:hover { opacity: .9; transform: translateY(-2px); }
.dash-card-bg-icon {
    position: absolute; right: -12px; top: -12px;
    font-size: 80px; opacity: .13; pointer-events: none;
}
.dash-card-num {
    font-size: clamp(32px, 5vw, 52px);
    font-weight: bold;
    line-height: 1;
    margin-bottom: 8px;
}
.dash-card-label {
    font-size: 13px;
    opacity: .9;
    margin-bottom: 4px;
}
.dash-card-sub {
    font-size: 12px;
    opacity: .72;
}

/* ── Dashboard responsive ────────────────────────────────────────── */
@media (max-width: 900px) {
    .dash-cards-grid { grid-template-columns: repeat(2, 1fr); }
    .dash-card { min-height: 120px; }
    .dash-card-num { font-size: clamp(28px, 6vw, 40px); }
}
@media (max-width: 480px) {
    .dash-cards-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .dash-card { padding: 16px 14px 14px; min-height: 100px; }
    .dash-card-num { font-size: 28px; }
    .dash-card-label { font-size: 11px; }
    .dash-card-sub { font-size: 11px; }
    .dash-card-bg-icon { font-size: 55px; }
}

/* ── Revenue panel ───────────────────────────────────────────────── */
.dash-revenue-row {
    display: flex;
    gap: 0;
    flex-wrap: wrap;
}
.dash-revenue-col {
    padding: 8px 20px 8px 0;
    margin-right: 20px;
    border-right: 1px solid #e0e0e0;
}
.dash-revenue-col:last-child { border-right: none; }
@media (max-width: 480px) {
    .dash-revenue-col {
        border-right: none;
        border-bottom: 1px solid #e0e0e0;
        padding: 8px 0;
        margin-right: 0;
        width: 100%;
    }
}

/* ── Notifications comptabilité ──────────────────────────────────── */
.accounting-notif-panel {
    background: #fff8e1;
    border: 1px solid #ffe082;
    border-left: 5px solid #f39c12;
    border-radius: 8px;
    margin-bottom: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
}
.accounting-notif-title {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    color: #7a4f00;
    font-weight: bold;
    border-bottom: 1px solid rgba(122,79,0,.12);
}
.accounting-notif-item {
    padding: 12px 14px;
    color: #2f2f2f;
    line-height: 1.55;
    border-bottom: 1px solid rgba(122,79,0,.1);
}
.accounting-notif-item:last-child {
    border-bottom: none;
}
.accounting-notif-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    color: #805b13;
    font-size: 12px;
    margin-top: 7px;
}
@media (max-width: 600px) {
    .accounting-notif-title {
        align-items: flex-start;
        line-height: 1.35;
    }
    .accounting-notif-item {
        font-size: 13px;
    }
    .accounting-notif-meta {
        display: block;
    }
    .accounting-notif-meta span {
        display: block;
        margin-top: 3px;
    }
}

/* ── Overlay mobile pour fermer le sidenav ──────────────────── */
#sidenav-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 8;
}
#sidenav-overlay.active { display: block; }

    </style>
    <link rel="stylesheet" href="css/mikhmon-portal.css">
    <link rel="stylesheet" href="css/mikhmon-responsive.css">
</head>
<body class="<?= $seller_logged_in ? 'seller-portal' : 'auth-screen' ?>">
<div class="wrapper">

<?php if (!$seller_logged_in): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     PAGE DE CONNEXION VENDEUR
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="portal-auth-wrap" style="max-width:400px;margin:0 auto;padding:5% 0 32px;min-height:auto;">
  <div class="login-card card portal-auth-card portal-auth-card-sm" style="width:100%;max-width:400px;margin:0 auto;">
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
        <span class="role-badge badge-vendor">
          <i class="fa fa-ticket"></i> <?= isset($_seller) ? $_seller : 'Vendeur' ?>
        </span>
      </div>
      <form autocomplete="off" action="" method="post">
        <?= csrf_field() ?>
        <input class="login-field form-control" type="text" name="seller_user"
               placeholder="<?= isset($_seller_id) ? $_seller_id : 'Identifiant' ?>" required autofocus>
        <input class="login-field form-control" type="password" name="seller_pass"
               placeholder="<?= isset($_password) ? $_password : 'Mot de passe' ?>" required>
        <input class="login-submit btn-vendor" type="submit" name="seller_login"
               value="<?= isset($_seller_login_title) ? $_seller_login_title : 'Connexion vendeur' ?>">
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
<!-- ═══════════════════════════════════════════════════════════════════════════
     TABLEAU DE BORD VENDEUR
     ═══════════════════════════════════════════════════════════════════════ -->

<!-- Navbar -->
<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="text-center" href="<?= $sellerDashboardUrl ?>">MIKHMON</a>
    <a id="openNav" class="navbar-hover" href="javascript:void(0)" title="Menu"><i class="fa fa-bars"></i></a>
    <a id="closeNav" class="navbar-hover" href="javascript:void(0)" title="Fermer"><i class="fa fa-bars"></i></a>
    <a id="cpage" class="navbar-left" href="javascript:void(0)">
      <?php
        $navIcon  = 'dashboard';
        $navLabel = $_dashboard;
        if ($action === 'tickets')      { $navIcon = 'list-ul';   $navLabel = 'Mes Tickets'; }
        elseif ($action === 'transfer') { $navIcon = 'exchange';  $navLabel = isset($_transfer_stock) ? $_transfer_stock : 'Transfer'; }
        elseif ($action === 'stock-board' || $action === 'request-transfer' || $action === 'respond-request')
                                        { $navIcon = 'eye';       $navLabel = isset($_stock_vendors) ? $_stock_vendors : 'Vendor Stock'; }
        elseif ($action === 'sales' || strlen($idbl) > 0 || strlen($idhr) > 0)
                                        { $navIcon = 'ticket';    $navLabel = $_seller_my_sales; }
      ?>
      <i class="fa fa-<?= $navIcon ?>"></i> <?= $navLabel ?> — <?= htmlspecialchars($sellerName) ?>
    </a>
  </div>
  <div class="navbar-right portal-nav-actions">
    <?= mikhmon_revenue_toggle_button($sellerRevenueVisible) ?>
    <a class="portal-nav-action" href="<?= $sellerDashboardUrl ?>"><i class="fa fa-dashboard"></i><span><?= $_dashboard ?></span></a>
    <a class="portal-nav-action" href="./sellers.php?action=logout"><i class="fa fa-sign-out"></i><span><?= $_logout ?></span></a>
  </div>
</div>

<!-- Overlay mobile -->
<div id="sidenav-overlay"></div>

<!-- Sidenav -->
<div id="sidenav" class="sidenav">
  <div class="menu text-center align-middle card-header" style="border-radius:0;position:relative;">
    <h3><?= htmlspecialchars($identity ?: $seller_session_name) ?></h3>
    <small style="color:#aaa;"><?= htmlspecialchars($sellerName) ?></small>
    <a id="closeSidenav" href="javascript:void(0)" title="Fermer le menu"
       style="position:absolute;top:8px;right:10px;font-size:18px;color:#aaa;display:none;text-decoration:none;">
      <i class="fa fa-times"></i>
    </a>
  </div>
  <a href="<?= $sellerDashboardUrl ?>"
     class="menu<?= ($action === 'dashboard' || $action === '') ? ' active' : '' ?>">
    <i class="fa fa-dashboard"></i> <?= $_dashboard ?>
  </a>
  <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>"
     class="menu<?= (!in_array($action, ['dashboard','transfer','tickets']) && strlen($idbl) > 0) ? ' active' : '' ?>">
    <i class="fa fa-ticket"></i> <?= $_seller_my_sales ?> (<?= $_this_month ?>)
  </a>
  <a href="./sellers.php?action=sales"
     class="menu<?= ($action === 'sales') ? ' active' : '' ?>">
    <i class="fa fa-list"></i> <?= $_seller_my_sales ?> (<?= $_all ?>)
  </a>
  <a href="./sellers.php?action=transfer"
     class="menu<?= ($action === 'transfer') ? ' active' : '' ?>">
    <i class="fa fa-exchange"></i> <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?>
    <?php $totalStock = array_sum($sellerStock); if ($totalStock > 0): ?>
      <span class="notif-badge"><?= $totalStock ?></span>
    <?php endif; ?>
  </a>
  <a href="./sellers.php?action=stock-board"
     class="menu<?= in_array($action, ['stock-board','request-transfer','respond-request']) ? ' active' : '' ?>">
    <i class="fa fa-eye"></i> <?= isset($_stock_vendors) ? $_stock_vendors : 'Vendor Stock' ?>
    <?php if ($pendingRequestsCount > 0): ?>
      <span class="notif-badge"><?= $pendingRequestsCount ?></span>
    <?php endif; ?>
  </a>
  <a href="./sellers.php?action=tickets"
     class="menu<?= ($action === 'tickets') ? ' active' : '' ?>">
    <i class="fa fa-list-ul"></i> Mes Tickets
  </a>
  <a href="./sellers.php?action=logout" class="menu">
    <i class="fa fa-sign-out"></i> <?= $_logout ?>
  </a>
</div>

<div id="notify"><div class="message"></div></div>
<div id="temp"></div>
<div id="main">
<div id="loading" class="lds-dual-ring"></div>
<div class="main-container">

<?php if (!empty($accountingNotifications)): ?>
<div class="row"><div class="col-12">
  <div class="accounting-notif-panel">
    <div class="accounting-notif-title">
      <i class="fa fa-bell"></i>
      Notification de comptabilité
    </div>
    <?php foreach ($accountingNotifications as $notice): ?>
      <div class="accounting-notif-item">
        <div><?= htmlspecialchars(isset($notice['message']) ? $notice['message'] : '') ?></div>
        <div class="accounting-notif-meta">
          <span><i class="fa fa-user"></i> <?= htmlspecialchars(isset($notice['sender_name']) ? $notice['sender_name'] : '') ?></span>
          <span><i class="fa fa-clock-o"></i> <?= htmlspecialchars(isset($notice['created_at']) ? $notice['created_at'] : '') ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<?php if ($seller_session_missing): ?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 style="margin:0;"><i class="fa fa-exclamation-triangle"></i> Session routeur introuvable</h3></div>
    <div class="card-body">
      <div class="portal-note-card">
        <b><?= htmlspecialchars($seller_session_message) ?></b><br>
        Le compte vendeur est bien présent, mais la session routeur qui lui est associée n’existe plus dans la configuration locale de Mikhmon.
        L’administrateur doit recréer ou réassocier cette session avant de reprendre les ventes et les impressions.
      </div>
      <div class="mgr-quick-actions" style="margin-top:16px;">
        <a href="./sellers.php?action=logout" class="btn" style="background:#34495e;color:#fff;padding:10px 16px;">
          <i class="fa fa-sign-out"></i> <?= $_logout ?>
        </a>
      </div>
    </div>
  </div>
</div></div>
<?php elseif ($seller_connection_error !== ''): ?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 style="margin:0;"><i class="fa fa-exclamation-triangle"></i> Routeur indisponible</h3></div>
    <div class="card-body">
      <div class="portal-note-card">
        <b><?= htmlspecialchars($seller_connection_error) ?></b><br>
        Le portail vendeur reste ouvert, mais les tickets, ventes et transferts sont désactivés tant que Mikhmon ne peut pas joindre RouterOS.
      </div>
      <div class="mgr-quick-actions" style="margin-top:16px;">
        <a href="./sellers.php?action=dashboard" class="btn bg-primary" style="padding:10px 16px;">
          <i class="fa fa-refresh"></i> Réessayer
        </a>
        <a href="./sellers.php?action=logout" class="btn" style="background:#34495e;color:#fff;padding:10px 16px;">
          <i class="fa fa-sign-out"></i> <?= $_logout ?>
        </a>
      </div>
    </div>
  </div>
</div></div>
<?php elseif ($action === 'stock-board'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     PAGE STOCK VENDEURS — Visibilité mutuelle + demandes de transfert
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="row"><div class="col-12">

<?php
if ($req_msg):      echo '<div class="alert-success"><i class="fa fa-check-circle"></i> ' . $req_msg . '</div>'; endif;
if ($req_error):    echo '<div class="alert-danger"><i class="fa fa-ban"></i> ' . htmlspecialchars($req_error) . '</div>'; endif;
if ($accept_msg):   echo '<div class="alert-success"><i class="fa fa-check-circle"></i> ' . $accept_msg . '</div>'; endif;
if ($accept_error): echo '<div class="alert-danger"><i class="fa fa-ban"></i> ' . htmlspecialchars($accept_error) . '</div>'; endif;
?>

<?php if (!empty($pendingRequests)): ?>
<div class="notif-panel">
  <div class="notif-panel-title">
    <i class="fa fa-bell"></i>
    <?= $pendingRequestsCount ?> <?= $pendingRequestsCount > 1 ? (isset($_tr_requests_pending_many) ? $_tr_requests_pending_many : 'pending requests') : (isset($_tr_requests_pending_one) ? $_tr_requests_pending_one : 'pending request') ?>
  </div>
  <?php foreach ($pendingRequests as $req): ?>
  <div class="notif-item">
    <div class="notif-item-info">
      <b><?= htmlspecialchars($req['from_name']) ?></b>
      <?= isset($_transfer_req_asks) ? $_transfer_req_asks : 'requests' ?> <b><?= (int)$req['qty'] ?> × <?= htmlspecialchars($req['profile']) ?></b>
      <span class="notif-item-ts"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($req['ts']) ?></span>
    </div>
    <div class="notif-item-actions">
      <form method="post" action="./sellers.php?action=respond-request">
        <?= csrf_field() ?>
        <input type="hidden" name="req_id"     value="<?= htmlspecialchars($req['id']) ?>">
        <input type="hidden" name="req_action" value="accept">
        <button type="submit" class="btn-accept"><i class="fa fa-check"></i> <?= isset($_transfer_req_accept) ? $_transfer_req_accept : 'Accept' ?></button>
      </form>
      <form method="post" action="./sellers.php?action=respond-request">
        <?= csrf_field() ?>
        <input type="hidden" name="req_id"     value="<?= htmlspecialchars($req['id']) ?>">
        <input type="hidden" name="req_action" value="decline">
        <button type="submit" class="btn-decline"><i class="fa fa-times"></i> <?= isset($_transfer_req_decline) ? $_transfer_req_decline : 'Decline' ?></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($allSellersStock)): ?>
<div class="card seller-dash-card">
  <div class="card-body">
    <p class="stock-empty-note">
      <i class="fa fa-info-circle"></i>
      <?= isset($_stock_no_seller) ? $_stock_no_seller : 'No other vendor registered on this session' ?> (<?= htmlspecialchars($seller_session_name) ?>).
    </p>
  </div>
</div>
<?php else: ?>

<?php if (count($allSellersStock) === 1): ?>
<div class="card seller-dash-card" style="margin-bottom:14px;">
  <div class="card-body">
    <p class="stock-empty-note" style="margin:0;">
      <i class="fa fa-info-circle"></i>
      <?= isset($_stock_no_seller) ? $_stock_no_seller : 'No other vendor registered on this session' ?> (<?= htmlspecialchars($seller_session_name) ?>).
    </p>
  </div>
</div>
<?php endif; ?>

<div class="stock-board-grid">
<?php $stockToneClasses = array('stock-board-card-blue', 'stock-board-card-green', 'stock-board-card-yellow', 'stock-board-card-red'); ?>
<?php $stockToneIndex = 0; ?>
<?php foreach ($allSellersStock as $sk => $sdata): ?>
<?php $sellerTotal = array_sum($sdata['stock']); ?>
<?php $stockToneClass = $sdata['is_self'] ? 'stock-board-card-self' : $stockToneClasses[$stockToneIndex % count($stockToneClasses)]; ?>
<?php $stockToneIndex++; ?>
<div class="stock-board-card <?= $stockToneClass ?>">
  <div class="stock-board-card-header">
    <div class="stock-board-card-heading">
      <div class="stock-board-card-title">
        <i class="fa fa-<?= $sdata['is_self'] ? 'user' : 'user-o' ?>"></i>
        <?= htmlspecialchars($sdata['name']) ?>
      </div>
      <?php if ($sdata['is_self']): ?>
      <div class="stock-board-card-self-label"><?= isset($_stock_your) ? $_stock_your : 'Votre stock' ?></div>
      <?php endif; ?>
    </div>
    <span class="stock-board-total-badge<?= $sellerTotal === 0 ? ' empty' : '' ?>">
      <?= $sellerTotal ?> vcr
    </span>
  </div>
  <div class="stock-board-card-body">
    <?php if (empty($sdata['profiles'])): ?>
      <div class="stock-empty-note"><i class="fa fa-inbox"></i> <?= isset($_stock_no_ticket) ? $_stock_no_ticket : 'No tickets available' ?></div>
    <?php else: ?>
      <?php foreach ($sdata['profiles'] as $prof => $profileMetrics): ?>
      <?php $qty = isset($profileMetrics['available']) ? (int)$profileMetrics['available'] : 0; ?>
      <?php $sold = isset($profileMetrics['sold']) ? (int)$profileMetrics['sold'] : 0; ?>
      <?php $attributed = isset($profileMetrics['total']) ? (int)$profileMetrics['total'] : $qty; ?>
      <div class="stock-profile-row">
        <span class="stock-profile-name"><?= htmlspecialchars($prof) ?></span>
        <span class="stock-profile-qty <?= $qty <= 5 ? 'qty-low' : 'qty-ok' ?>"
              title="Vendus / total attribué">
          <span class="stock-profile-sold"><?= $sold ?></span>
          <span class="stock-profile-separator">/</span>
          <span class="stock-profile-total"><?= $attributed ?></span>
        </span>
        <?php if ($qty > 0): ?>
        <?php if ($sdata['is_self']): ?>
        <a class="stock-request-btn stock-transfer-link" href="./sellers.php?action=transfer">
          <i class="fa fa-exchange"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?>
        </a>
        <?php else: ?>
        <button type="button" class="stock-request-btn"
                onclick="openReqModal('<?= htmlspecialchars($sk, ENT_QUOTES) ?>','<?= htmlspecialchars($sdata['name'], ENT_QUOTES) ?>','<?= htmlspecialchars($prof, ENT_QUOTES) ?>',<?= (int)$qty ?>);return false;">
          <i class="fa fa-arrow-circle-left"></i> <?= isset($_transfer_req_send) ? $_transfer_req_send : 'Demander' ?>
        </button>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$sdata['is_self']): ?>
      <button type="button" class="stock-request-all-btn"
              onclick="openReqModal('<?= htmlspecialchars($sk, ENT_QUOTES) ?>','<?= htmlspecialchars($sdata['name'], ENT_QUOTES) ?>','',0)">
        <i class="fa fa-exchange"></i> <?= isset($_stock_request_all) ? $_stock_request_all : 'Request a transfer…' ?>
      </button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php $sentRequests = tr_get_sent_by($sellerUsername, 15); ?>
<?php if (!empty($sentRequests)): ?>
<div class="card" style="margin-top:14px;">
  <div class="card-header">
    <h4 style="margin:0;"><i class="fa fa-history"></i> <?= isset($_transfer_req_recent) ? $_transfer_req_recent : 'My recent requests' ?></h4>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-responsive">
      <table class="table table-bordered dashboard-table-sm" style="margin-bottom:0;">
        <thead class="thead-light">
          <tr>
            <th><?= isset($_date) ? $_date : 'Date' ?></th>
            <th><?= isset($_transfer_req_recipient) ? $_transfer_req_recipient : 'Recipient' ?></th>
            <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
            <th class="text-center"><?= isset($_seller_qty) ? $_seller_qty : 'Qty' ?></th>
            <th class="text-center"><?= isset($_status) ? $_status : 'Status' ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sentRequests as $sr):
            $sb = [
                'pending'  => '<span class="badge-status pending"><i class="fa fa-clock-o"></i> ' . (isset($_tr_status_pending)  ? $_tr_status_pending  : 'Pending')  . '</span>',
                'accepted' => '<span class="badge-status accepted"><i class="fa fa-check"></i> '  . (isset($_tr_status_accepted) ? $_tr_status_accepted : 'Accepted') . '</span>',
                'declined' => '<span class="badge-status declined"><i class="fa fa-times"></i> '  . (isset($_tr_status_declined) ? $_tr_status_declined : 'Declined') . '</span>',
            ];
            $badge = isset($sb[$sr['status']]) ? $sb[$sr['status']] : htmlspecialchars($sr['status']);
          ?>
          <tr>
            <td><?= htmlspecialchars($sr['ts']) ?></td>
            <td><b><?= htmlspecialchars($sr['to_name']) ?></b></td>
            <td><?= htmlspecialchars($sr['profile']) ?></td>
            <td class="text-center"><b><?= (int)$sr['qty'] ?></b></td>
            <td class="text-center"><?= $badge ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

</div></div>

<div id="reqModal">
  <div class="req-box">
    <h3><i class="fa fa-exchange"></i> <?= isset($_transfer_req_modal_title) ? $_transfer_req_modal_title : 'Request a transfer' ?></h3>
    <p class="req-box-desc" id="reqModalDesc"></p>
    <form method="post" action="./sellers.php?action=request-transfer">
      <?= csrf_field() ?>
      <input type="hidden" name="do_request" value="1">
      <input type="hidden" name="req_to"     id="reqTo">
      <label><i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></label>
      <select name="req_profile" id="reqProfile" required></select>
      <label><i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Quantity' ?></label>
      <input type="number" name="req_qty" id="reqQty" min="1" value="1" required>
      <div class="req-box-actions">
        <button type="submit" class="btn-req-send"><i class="fa fa-paper-plane"></i> <?= isset($_transfer_req_send) ? $_transfer_req_send : 'Send' ?></button>
        <button type="button" class="btn-req-cancel" onclick="closeReqModal()"><?= isset($_cancel) ? $_cancel : 'Cancel' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
var _asSt = <?= json_encode(
    array_map(function ($s) { return ['name' => $s['name'], 'stock' => $s['stock']]; }, $allSellersStock),
    JSON_UNESCAPED_UNICODE
) ?>;
function openReqModal(sk, sn, profile, maxQty) {
    document.getElementById('reqTo').value = sk;
    document.getElementById('reqModalDesc').textContent = '<?= addslashes(isset($_transfer_req_to_prefix) ? $_transfer_req_to_prefix : "Request from:") ?> ' + sn;
    var sel = document.getElementById('reqProfile');
    sel.innerHTML = '';
    var stock = (_asSt[sk] && _asSt[sk].stock) ? _asSt[sk].stock : {};
    var _availLbl = '<?= addslashes(isset($_transfer_available) ? $_transfer_available : "available") ?>';
    var firstMax = 1;
    if (profile !== '') {
        var o = document.createElement('option');
        o.value = profile; o.textContent = profile + ' (' + maxQty + ' ' + _availLbl + ')';
        sel.appendChild(o); firstMax = maxQty;
    } else {
        var first = true;
        for (var p in stock) {
            var o = document.createElement('option');
            o.value = p; o.textContent = p + ' (' + stock[p] + ' ' + _availLbl + ')';
            sel.appendChild(o);
            if (first) { firstMax = stock[p]; first = false; }
        }
        sel.onchange = function () {
            var v = this.value;
            if (stock[v]) {
                document.getElementById('reqQty').max = stock[v];
                if (parseInt(document.getElementById('reqQty').value) > stock[v])
                    document.getElementById('reqQty').value = stock[v];
            }
        };
    }
    document.getElementById('reqQty').max   = firstMax;
    document.getElementById('reqQty').value = 1;
    document.getElementById('reqModal').style.display = 'flex';
}
function closeReqModal() {
    document.getElementById('reqModal').style.display = 'none';
}
document.getElementById('reqModal').addEventListener('click', function (e) {
    if (e.target === this) closeReqModal();
});
</script>

<?php elseif ($action === 'transfer'): ?>
<!-- ═══════════════════════════════════════════════════════ PAGE TRANSFERT ══ -->
<div class="row">
<div class="col-12">
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

    <!-- Stock disponible -->
    <div class="card seller-dash-card" style="margin-bottom:20px;">
      <div class="card-header">
        <h4 style="margin:0;"><i class="fa fa-archive"></i>
          <?= isset($_transfer_available) ? $_transfer_available : 'Stock disponible' ?>
          — <?= htmlspecialchars($sellerName) ?>
        </h4>
      </div>
      <div class="card-body" style="padding:10px 12px 4px;">
        <?php if (empty($sellerStock)): ?>
          <div class="portal-empty-note" style="padding:12px;background:#f8f9fa;border-radius:5px;color:#888;">
            <i class="fa fa-info-circle"></i> <?= isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets available' ?>
          </div>
        <?php else: ?>
        <div class="row dashboard-hotspot-grid">
          <?php
            $stockColors = ['bg-blue','bg-green','bg-yellow','bg-red'];
            $sci = 0;
            foreach ($sellerStock as $prof => $qty):
          ?>
          <div class="col-3 col-box-6">
            <div class="box <?= $stockColors[$sci++ % 4] ?> bmh-75">
              <a href="#">
                <h1><?= $qty ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-tag"></i> <?= htmlspecialchars($prof) ?></div>
              </a>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="col-3 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="#">
                <h1><?= array_sum($sellerStock) ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-archive"></i> Total</div>
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Formulaire de transfert -->
    <?php if (!empty($sellerStock) && count($sellers_data) > 1): ?>
    <hr>
    <h4 style="margin-bottom:12px;"><i class="fa fa-share"></i> <?= isset($_transfer_to) ? $_transfer_to : 'Transfer to' ?></h4>
    <p style="color:#666;font-size:13px;">
      <i class="fa fa-info-circle"></i> <?= isset($_transfer_info) ? $_transfer_info : 'Select a profile, a quantity and the receiving vendor.' ?>
    </p>
    <form method="post" action="./sellers.php?action=transfer" style="max-width:500px;" id="sellerTransferForm" onsubmit="return confirmTransfer(this)">
      <?= csrf_field() ?>
      <input type="hidden" name="do_transfer" value="1">

      <div class="transfer-form-grid">
        <!-- Profil -->
        <div class="transfer-form-group">
          <label class="transfer-label">
            <i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?>
          </label>
          <select name="transfer_profile" class="form-control transfer-select" required>
            <option value=""><?= isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile' ?></option>
            <?php foreach ($sellerStock as $prof => $qty): ?>
              <option value="<?= htmlspecialchars($prof) ?>"><?= htmlspecialchars($prof) ?> (<?= $qty ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Quantité -->
        <div class="transfer-form-group">
          <label class="transfer-label">
            <i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Quantity' ?>
          </label>
          <input type="number" name="transfer_qty" class="form-control transfer-select"
                 min="1" value="1" required>
        </div>

        <!-- Vendeur cible -->
        <div class="transfer-form-group" style="grid-column: 1 / -1;">
          <label class="transfer-label">
            <i class="fa fa-user"></i> <?= isset($_transfer_to) ? $_transfer_to : 'Transfer to' ?>
          </label>
          <select name="target_seller" class="form-control transfer-select" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select a vendor' ?></option>
            <?php foreach ($sellers_data as $sk => $sd): ?>
              <?php if ($sk !== $sellerUsername): ?>
                <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" class="btn-transfer">
        <i class="fa fa-exchange"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?>
      </button>
    </form>
    <?php elseif (count($sellers_data) <= 1): ?>
      <p style="color:#888;font-size:13px;margin-top:10px;">
        <i class="fa fa-info-circle"></i>
        <?= isset($_no_seller_registered) ? $_no_seller_registered : 'No other vendor registered.' ?>
      </p>
    <?php endif; ?>

  </div><!-- card-body -->
</div><!-- card -->
</div><!-- col -->
</div><!-- row -->

<?php elseif ($action === 'tickets'): ?>
<!-- ══════════════════════════════════════════════════════ PAGE TICKETS ═══ -->
<div class="row">
<div class="col-12">
<div class="card">
  <div class="card-header">
    <h3><i class="fa fa-list-ul"></i> Mes Tickets — <span style="color:#27ae60;"><?= htmlspecialchars($sellerName) ?></span></h3>
  </div>
  <div class="card-body">
<?php
// ── Récupérer tous les tickets hotspot de ce vendeur ───────────────────────
$allTickets   = array();
$usedTickets  = array();
$unusedTickets = array();
if ($seller_router_connected) {
    $allUsers = $API->comm("/ip/hotspot/user/print");
    if (is_array($allUsers)) {
        $sellerKey = strtolower($sellerUsername);
        $sfxKey    = '-' . $sellerKey;
        foreach ($allUsers as $u) {
            $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
            if ($cmt === $sellerKey || substr($cmt, -strlen($sfxKey)) === $sfxKey) {
                $uptime = isset($u['uptime']) ? $u['uptime'] : '0s';
                if ($uptime === '0s' || $uptime === '') {
                    $unusedTickets[] = $u;
                } else {
                    $usedTickets[] = $u;
                }
                $allTickets[] = $u;
            }
        }
    }
}
$totalTickets  = count($allTickets);
$usedCount     = count($usedTickets);
$unusedCount   = count($unusedTickets);
$unusedTicketProfiles = array();
$unusedTicketComments = array();
if (!empty($unusedTickets)) {
    foreach ($unusedTickets as $unusedTicket) {
        $ticketProfile = isset($unusedTicket['profile']) ? trim($unusedTicket['profile']) : '';
        $ticketComment = isset($unusedTicket['comment']) ? trim($unusedTicket['comment']) : '';
        if ($ticketProfile !== '') {
            $unusedTicketProfiles[$ticketProfile] = true;
        }
        if ($ticketComment !== '') {
            $commentKey = $ticketComment . '||' . $ticketProfile;
            if (!isset($unusedTicketComments[$commentKey])) {
                $unusedTicketComments[$commentKey] = array(
                    'comment' => $ticketComment,
                    'profile' => $ticketProfile,
                    'count' => 0,
                );
            }
            $unusedTicketComments[$commentKey]['count']++;
        }
    }
    ksort($unusedTicketProfiles, SORT_NATURAL);
    uasort($unusedTicketComments, function ($left, $right) {
        $cmp = strnatcmp($left['comment'], $right['comment']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strnatcmp($left['profile'], $right['profile']);
    });
}
?>
    <!-- Stats rapides -->
    <div class="card seller-dash-card" style="margin-bottom:16px;">
      <div class="card-body" style="padding:10px 12px 4px;">
        <div class="row dashboard-hotspot-grid">
          <div class="col-3 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="#">
                <h1><?= $totalTickets ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-list-ul"></i> Total</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-green bmh-75">
              <a href="#">
                <h1><?= $unusedCount ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-archive"></i> Stock (non utilisé)</div>
              </a>
            </div>
          </div>
          <div class="col-3 col-box-6">
            <div class="box bg-yellow bmh-75">
              <a href="#">
                <h1><?= $usedCount ?><span class="box-stat-unit"> vcr</span></h1>
                <div><i class="fa fa-check-circle"></i> Utilisés</div>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (empty($allTickets)): ?>
      <div style="padding:20px;text-align:center;color:#888;">
        <i class="fa fa-info-circle" style="font-size:32px;margin-bottom:10px;display:block;"></i>
        <?= isset($_no_ticket_assigned) ? $_no_ticket_assigned : 'No tickets assigned to your account.' ?>
      </div>
    <?php else: ?>

    <!-- ── Stock non utilisé — AVEC IMPRESSION ────────────────────────── -->
    <?php if (!empty($unusedTickets)): ?>
    <div class="portal-print-panel">
      <div class="portal-print-toolbar">
        <div class="portal-print-filters">
          <div class="input-group">
            <div class="input-group-4 col-box-4">
              <input id="ticketSearch" type="text" class="group-item group-item-l" placeholder="<?= isset($_search) ? $_search : 'Rechercher' ?>" oninput="filterTicketRows()">
            </div>
            <div class="input-group-4 col-box-4">
              <select id="profileFilter" class="group-item group-item-md" onchange="filterTicketRows()">
                <option value=""><?= isset($_profile) ? $_profile : 'Profil' ?></option>
                <?php foreach (array_keys($unusedTicketProfiles) as $ticketProfile): ?>
                  <option value="<?= htmlspecialchars($ticketProfile) ?>"><?= htmlspecialchars($ticketProfile) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="input-group-4 col-box-4">
              <select id="commentFilter" class="group-item group-item-r" onchange="filterTicketRows()">
                <option value=""><?= isset($_comment) ? $_comment : 'Commentaire' ?></option>
                <?php foreach ($unusedTicketComments as $ticketCommentMeta): ?>
                  <option value="<?= htmlspecialchars($ticketCommentMeta['comment']) ?>">
                    <?= htmlspecialchars($ticketCommentMeta['comment']) ?>
                    <?php if ($ticketCommentMeta['profile'] !== ''): ?> <?= htmlspecialchars($ticketCommentMeta['profile']) ?><?php endif; ?>
                    [<?= (int)$ticketCommentMeta['count'] ?>]
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="portal-print-actions">
          <button type="button" class="btn bg-primary" onclick="printFilteredTickets('default')"><i class="fa fa-print"></i> <?= isset($_print_default) ? $_print_default : 'Défaut' ?></button>
          <button type="button" class="btn bg-primary" onclick="printFilteredTickets('qr')"><i class="fa fa-qrcode"></i> <?= isset($_print_qr) ? $_print_qr : 'QR' ?></button>
          <button type="button" class="btn bg-primary" onclick="printFilteredTickets('small')"><i class="fa fa-print"></i> <?= isset($_print_small) ? $_print_small : 'Petit' ?></button>
        </div>
      </div>

      <div class="portal-inline-note portal-help-box">
        <i class="fa fa-info-circle"></i>
        Filtrez uniquement vos tickets transférés par <b>recherche</b>, <b>profil</b> ou <b>commentaire</b>, puis imprimez-les en <b>Défaut</b>, <b>QR</b> ou <b>Petit</b>.
      </div>

      <div class="portal-ticket-header">
        <h4 style="margin:0;color:#27ae60;"><i class="fa fa-archive"></i> <?= isset($_stock_available) ? $_stock_available : 'Available stock' ?> (<span id="visibleTicketCount"><?= $unusedCount ?></span>/<?= $unusedCount ?>)</h4>
        <div class="portal-ticket-meta">
          <button type="button" class="btn" onclick="toggleAllTickets()"
                  style="padding:6px 12px;background:#eee;border:1px solid #ccc;border-radius:5px;font-size:13px;cursor:pointer;">
            <i class="fa fa-check-square-o"></i> Tout sélectionner
          </button>
        </div>
      </div>

    <div class="overflow box-bordered table-responsive portal-ticket-table-wrap portal-centered-table" style="max-height:45vh;margin-bottom:20px;">
      <table class="table table-bordered table-hover text-nowrap tickets-table portal-ticket-table" id="stockTable">
        <thead class="thead-light">
          <tr>
            <th style="width:36px;"><input type="checkbox" id="chkAll" onchange="toggleAllTickets(this.checked)"></th>
            <th>&#8470;</th>
            <th><?= $_user_name ?></th>
            <th><?= $_profile ?></th>
            <th>Mot de passe</th>
            <th><?= isset($_comment) ? $_comment : 'Commentaire' ?></th>
            <th style="text-align:center;">Imprimer</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($unusedTickets as $i => $u):
            $uName  = isset($u['name'])     ? $u['name']     : '';
            $uPass  = isset($u['password']) ? $u['password'] : '';
            $uProf  = isset($u['profile'])  ? $u['profile']  : '';
            $uComment = isset($u['comment']) ? $u['comment'] : '';
            $rowSearch = strtolower(trim($uName . ' ' . $uPass . ' ' . $uProf . ' ' . $uComment));
          ?>
          <tr class="stock-row" data-profile="<?= htmlspecialchars($uProf) ?>" data-comment="<?= htmlspecialchars($uComment) ?>" data-search="<?= htmlspecialchars($rowSearch) ?>">
            <td><input type="checkbox" class="ticket-chk"
                       data-name="<?= htmlspecialchars($uName) ?>"
                       data-pass="<?= htmlspecialchars($uPass) ?>"
                       data-prof="<?= htmlspecialchars($uProf) ?>"
                       data-comment="<?= htmlspecialchars($uComment) ?>"></td>
            <td><?= $i + 1 ?></td>
            <td><b><?= htmlspecialchars($uName) ?></b></td>
            <td><span style="background:#e8f5e9;color:#2e7d32;border-radius:10px;padding:1px 8px;font-size:12px;"><?= htmlspecialchars($uProf) ?></span></td>
            <td><code style="font-size:13px;letter-spacing:1px;"><?= htmlspecialchars($uPass) ?></code></td>
            <td><span class="portal-muted-light"><?= htmlspecialchars($uComment !== '' ? $uComment : '—') ?></span></td>
            <td style="text-align:center;">
              <button type="button"
                      onclick="printOne('<?= addslashes($uName) ?>', 'default')"
                      style="padding:3px 10px;background:#8e44ad;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px;">
                <i class="fa fa-print"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    </div>
    <?php endif; ?>

    <!-- Tickets utilisés (lecture seule) -->
    <?php if (!empty($usedTickets)): ?>
    <h4 style="margin:10px 0 8px;color:#e67e22;"><i class="fa fa-check-circle"></i> Tickets utilisés (<?= $usedCount ?>)</h4>
    <div class="overflow box-bordered table-responsive portal-ticket-table-wrap" style="max-height:40vh;">
      <table class="table table-bordered table-hover text-nowrap tickets-table portal-ticket-table">
        <thead class="thead-light">
          <tr>
            <th>&#8470;</th>
            <th><?= $_user_name ?></th>
            <th><?= $_profile ?></th>
            <th>Uptime</th>
            <th>MAC</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($usedTickets as $i => $u): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= htmlspecialchars(isset($u['name']) ? $u['name'] : '—') ?></b></td>
            <td><span style="background:#fff3e0;color:#e67e22;border-radius:10px;padding:1px 8px;font-size:12px;"><?= htmlspecialchars(isset($u['profile']) ? $u['profile'] : '—') ?></span></td>
            <td><small><?= htmlspecialchars(isset($u['uptime']) ? $u['uptime'] : '—') ?></small></td>
            <td><small class="portal-muted-light" style="color:#999;"><?= htmlspecialchars(isset($u['mac-address']) ? $u['mac-address'] : '—') ?></small></td>
            <td><small class="portal-muted-light" style="color:#999;"><?= htmlspecialchars(isset($u['address']) ? $u['address'] : '—') ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <div class="portal-help-box" style="margin-top:15px;padding:10px;background:#f0f0f0;border-radius:5px;font-size:13px;">
      <i class="fa fa-info-circle"></i> Les boutons <b>Défaut</b>, <b>QR</b> et <b>Petit</b> impriment d’abord les tickets visibles et cochés. Si rien n’est coché, ils impriment tous les tickets visibles après filtrage.
    </div>

  </div><!-- card-body -->
</div><!-- card -->
</div><!-- col -->
</div><!-- row -->

<script>
var SELLER_PRINT_URL = '<?= addslashes($sellerVoucherPrintUrl) ?>';

function submitVoucherPrint(usernames) {
    var mode = arguments.length > 1 ? arguments[1] : 'default';
    if (!usernames.length) {
        alert(<?= json_encode(isset($_no_ticket_selected) ? $_no_ticket_selected : 'No ticket selected.') ?>);
        return;
    }

    var actionUrl = SELLER_PRINT_URL;
    if (mode === 'qr') {
        actionUrl += '&qr=yes';
    } else if (mode === 'small') {
        actionUrl += '&small=yes';
    } else {
        actionUrl += '&qr=no';
    }

    var form = document.createElement('form');
    form.method = 'post';
    form.action = actionUrl;
    form.target = '_blank';
    form.style.display = 'none';

    var payload = document.createElement('input');
    payload.type = 'hidden';
    payload.name = 'users_payload';
    payload.value = JSON.stringify(usernames);
    form.appendChild(payload);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function printOne(name, mode) {
    submitVoucherPrint([name], mode || 'default');
}

function collectVisibleTicketNames() {
    var usernames = [];
    var visibleChecked = [];
    document.querySelectorAll('.ticket-chk').forEach(function(chk) {
        var row = chk.closest('tr');
        if (row && row.style.display !== 'none') {
            usernames.push(chk.dataset.name);
            if (chk.checked) {
                visibleChecked.push(chk.dataset.name);
            }
        }
    });
    return visibleChecked.length ? visibleChecked : usernames;
}

function printFilteredTickets(mode) {
    submitVoucherPrint(collectVisibleTicketNames(), mode || 'default');
}

function toggleAllTickets(state) {
    var chks = Array.from(document.querySelectorAll('.ticket-chk')).filter(function(chk) {
        var row = chk.closest('tr');
        return row && row.style.display !== 'none';
    });
    var master = document.getElementById('chkAll');
    if (typeof state === 'undefined') {
        // called from button (toggle)
        var anyChecked = Array.from(chks).some(function(c){ return c.checked; });
        state = !anyChecked;
        if (master) master.checked = state;
    }
    chks.forEach(function(c){ c.checked = state; });
}

function filterTicketRows() {
    var search = (document.getElementById('ticketSearch').value || '').toLowerCase().trim();
    var profile = document.getElementById('profileFilter').value || '';
    var comment = document.getElementById('commentFilter').value || '';
    var visibleCount = 0;
    document.querySelectorAll('.stock-row').forEach(function(row) {
        var matchesProfile = !profile || row.dataset.profile === profile;
        var matchesComment = !comment || row.dataset.comment === comment;
        var matchesSearch = !search || (row.dataset.search || '').indexOf(search) !== -1;
        if (matchesProfile && matchesComment && matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
            row.querySelector('.ticket-chk').checked = false;
        }
    });
    var visibleTicketCount = document.getElementById('visibleTicketCount');
    if (visibleTicketCount) {
        visibleTicketCount.textContent = visibleCount;
    }
    // Reset master checkbox
    var master = document.getElementById('chkAll');
    if (master) master.checked = false;
}

filterTicketRows();
</script>

<?php elseif ($action === 'dashboard' || ($action === '' && strlen($idbl) === 0 && strlen($idhr) === 0)): ?>
<!-- ══════════════════════════════════════════════════ TABLEAU DE BORD ═══ -->
<div class="row"><div class="col-12">

<?php if ($pendingRequestsCount > 0): ?>
<div class="notif-panel" style="margin-bottom:14px;">
  <div class="notif-panel-title">
    <i class="fa fa-bell"></i>
    <?= $pendingRequestsCount ?> <?= $pendingRequestsCount > 1 ? (isset($_tr_requests_pending_many) ? $_tr_requests_pending_many : 'pending requests') : (isset($_tr_requests_pending_one) ? $_tr_requests_pending_one : 'pending request') ?>
    <a href="./sellers.php?action=stock-board" style="float:right;font-size:13px;font-weight:normal;color:#c0392b;">
      <?= isset($_transfer_req_see_all) ? $_transfer_req_see_all : 'See all' ?> <i class="fa fa-arrow-right"></i>
    </a>
  </div>
  <?php foreach (array_slice($pendingRequests, 0, 3) as $req): ?>
  <div class="notif-item">
    <div class="notif-item-info">
      <b><?= htmlspecialchars($req['from_name']) ?></b>
      <?= isset($_transfer_req_asks) ? $_transfer_req_asks : 'requests' ?> <b><?= (int)$req['qty'] ?> × <?= htmlspecialchars($req['profile']) ?></b>
      <span class="notif-item-ts"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($req['ts']) ?></span>
    </div>
    <div class="notif-item-actions">
      <form method="post" action="./sellers.php?action=respond-request">
        <?= csrf_field() ?>
        <input type="hidden" name="req_id"     value="<?= htmlspecialchars($req['id']) ?>">
        <input type="hidden" name="req_action" value="accept">
        <button type="submit" class="btn-accept"><i class="fa fa-check"></i> <?= isset($_transfer_req_accept) ? $_transfer_req_accept : 'Accept' ?></button>
      </form>
      <form method="post" action="./sellers.php?action=respond-request">
        <?= csrf_field() ?>
        <input type="hidden" name="req_id"     value="<?= htmlspecialchars($req['id']) ?>">
        <input type="hidden" name="req_action" value="decline">
        <button type="submit" class="btn-decline"><i class="fa fa-times"></i> <?= isset($_transfer_req_decline) ? $_transfer_req_decline : 'Decline' ?></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

  <!-- Résumé routeur -->
  <div class="card" style="margin-bottom:14px;">
    <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <div style="flex:1;min-width:180px;">
        <div class="portal-dark-muted" style="font-size:13px;color:#aaa;margin-bottom:2px;"><i class="fa fa-wifi"></i> <?= htmlspecialchars($identity ?: $seller_session_name) ?></div>
        <div style="font-size:17px;font-weight:bold;"><?= htmlspecialchars($sellerName) ?></div>
        <span style="background:#27ae60;color:#fff;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:bold;"><i class="fa fa-user"></i> Vendeur</span>
      </div>
      <div style="text-align:right;min-width:160px;">
        <div class="portal-dark-muted" style="font-size:12px;color:#aaa;"><i class="fa fa-clock-o"></i> <?= date("d/m/Y H:i") ?></div>
      </div>
    </div>
  </div>

  <!-- ── Ventes ───────────────────────────────────────────────────────── -->
  <div class="card seller-dash-card" style="margin-bottom:14px;">
    <div class="card-header"><h3><i class="fa fa-shopping-cart"></i> Ventes</h3></div>
    <div class="card-body">
      <div class="row dashboard-hotspot-grid">

        <div class="col-3 col-box-6">
          <div class="box bg-blue bmh-75">
            <a href="./sellers.php?idhr=<?= htmlspecialchars($today_str) ?>">
              <h1><?= $todaySalesCount ?></h1>
              <div><i class="fa fa-sun-o"></i> Tickets aujourd'hui</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-green bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <h1><?= $monthSalesCount ?></h1>
              <div><i class="fa fa-calendar"></i> Bons du mois</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-yellow bmh-75">
            <a href="./sellers.php?action=tickets">
              <h1><?= array_sum($sellerStock) ?></h1>
              <div><i class="fa fa-archive"></i> Stock disponible</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-red bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <?php if ($sellerCommissionRate > 0): ?>
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $sellerCommissionAmount, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-percent"></i> Commission (<?= $sellerCommissionRate ?>%)</div>
              <?php else: ?>
              <h1>—</h1>
              <div><i class="fa fa-percent"></i> Commission</div>
              <?php endif; ?>
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ── Revenus ───────────────────────────────────────────────────────── -->
  <?php if ($totalRevenue > 0): ?>
  <div class="card seller-dash-card" style="margin-bottom:14px;">
    <div class="card-header"><h3><i class="fa fa-money"></i> Revenus</h3></div>
    <div class="card-body">
      <div class="row dashboard-hotspot-grid">

        <div class="col-3 col-box-6">
          <div class="box bg-blue bmh-75">
            <a href="./sellers.php?idhr=<?= htmlspecialchars($today_str) ?>">
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $todayRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-sun-o"></i> Aujourd'hui (<?= $todaySalesCount ?> vcr)</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-green bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $monthRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-calendar"></i> <?= date('M Y') ?> (<?= $monthSalesCount ?> vcr)</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-yellow bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <?php if ($sellerCommissionRate > 0): ?>
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $totalNetRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-bank"></i> Net caisse (−<?= $sellerCommissionRate ?>%)</div>
              <?php else: ?>
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $totalRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-bank"></i> Total encaissé</div>
              <?php endif; ?>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-red bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <?php if ($sellerCommissionRate > 0): ?>
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $sellerCommissionAmount, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-percent"></i> Ma commission</div>
              <?php else: ?>
              <h1>—</h1>
              <div><i class="fa fa-percent"></i> Commission</div>
              <?php endif; ?>
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Accès rapide ───────────────────────────────────────────────────── -->
  <div class="card seller-dash-card">
    <div class="card-header"><h3><i class="fa fa-bolt"></i> Accès rapide</h3></div>
    <div class="card-body">
      <div class="row dashboard-hotspot-grid">

        <div class="col-3 col-box-6">
          <div class="box bg-blue bmh-75">
            <a href="./sellers.php?idbl=<?= strtolower(date('M')).date('Y') ?>">
              <h1><i class="fa fa-ticket"></i></h1>
              <div>Ventes du mois</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-green bmh-75">
            <a href="./sellers.php?action=tickets">
              <h1><i class="fa fa-list-ul"></i></h1>
              <div>Mes Tickets (<?= array_sum($sellerStock) ?> en stock)</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-yellow bmh-75">
            <a href="./sellers.php?action=transfer">
              <h1><i class="fa fa-exchange"></i></h1>
              <div>Transfert stock</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-red bmh-75">
            <a href="./sellers.php?idhr=<?= htmlspecialchars($today_str) ?>">
              <h1><i class="fa fa-sun-o"></i></h1>
              <div>Ventes du jour</div>
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>

</div></div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════ PAGE VENTES ═══ -->
<!-- Carte principale -->
<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
  <h3>
    <i class="fa fa-ticket"></i>
    <?= $_seller_my_sales ?>
    <?php
      if (strlen($idhr) > 0) {
          $day   = explode("/", $idhr)[1];
          $month = explode("/", $idhr)[0];
          $year  = explode("/", $idhr)[2];
          $fm    = array_search($month, $idbls);
          echo " — " . $day . " " . $idblf[$fm] . " " . $year;
      } elseif (strlen($idbl) > 0) {
          $m1  = substr($idbl, 0, 3);
          $y1  = substr($idbl, 3, 4);
          $fm  = array_search($m1, $idbls);
          echo " — " . ($idblf[$fm] ?? ucfirst($m1)) . " " . $y1;
      }
    ?>
  </h3>
</div>
<div class="card-body">

  <!-- Filtre date -->
  <div class="row" style="margin-bottom:10px;">
    <div class="col-12">
      <div class="input-group mr-b-10">
        <div class="input-group-1 col-box-2">
          <select style="padding:5px;" class="group-item group-item-l" id="D">
            <?php
              $day = explode("/", $idhr)[1];
              if ($day != "") echo "<option value='$day'>$day</option>";
              echo "<option value=''>Jour</option>";
              for ($x = 1; $x <= 31; $x++) {
                  $x = str_pad($x, 2, "0", STR_PAD_LEFT);
                  echo "<option value='$x'>$x</option>";
              }
            ?>
          </select>
        </div>
        <div class="input-group-2 col-box-4">
          <select style="padding:5px;" class="group-item group-item-md" id="M">
            <?php
              $month  = explode("/", $idhr)[0];
              $month1 = substr($idbl, 0, 3);
              if ($month != "") {
                  $fm = array_search($month, $idbls);
                  echo "<option value='$month'>" . $idblf[$fm] . "</option>";
              } elseif ($month1 != "") {
                  $fm = array_search($month1, $idbls);
                  echo "<option value='$month1'>" . $idblf[$fm] . "</option>";
              } else {
                  echo "<option value='" . $idbls[date("n")] . "'>" . $idblf[date("n")] . "</option>";
              }
              for ($x = 1; $x <= 12; $x++) {
                  echo "<option value='" . $idbls[$x] . "'>" . $idblf[$x] . "</option>";
              }
            ?>
          </select>
        </div>
        <div class="input-group-2 col-box-3">
          <select style="padding:5px;" class="group-item group-item-md" id="Y">
            <?php
              $year  = explode("/", $idhr)[2];
              $year1 = substr($idbl, 3, 4);
              if ($year != "") echo "<option>$year</option>";
              elseif ($year1 != "") echo "<option>$year1</option>";
              echo "<option>" . date("Y") . "</option>";
              for ($Y = 2018; $Y <= date("Y"); $Y++) {
                  if ($Y != date("Y")) echo "<option>$Y</option>";
              }
            ?>
          </select>
        </div>
        <div class="input-group-2 col-box-3">
          <div style="padding:3.5px;" class="group-item group-item-r text-center pointer" onclick="filterR()">
            <i class="fa fa-search"></i> <?= isset($_search) ? $_search : 'Filter' ?>
          </div>
        </div>
      </div>
      <script>
        function filterR() {
            var D = document.getElementById('D').value;
            var M = document.getElementById('M').value;
            var Y = document.getElementById('Y').value;
            if (D !== "") {
                window.location = './sellers.php?idhr=' + M + '/' + D + '/' + Y;
            } else {
                window.location = './sellers.php?idbl=' + M + Y;
            }
        }
      </script>
    </div>
  </div>

  <!-- ── Résumé ────────────────────────────────────────────────────────── -->
  <?php $totalStockVendor = array_sum($sellerStock); ?>

  <div class="card seller-dash-card seller-sales-summary-row" style="margin-bottom:14px;">
    <div class="card-header"><h3><i class="fa fa-bar-chart"></i> Résumé — <?= htmlspecialchars($sellerName) ?></h3></div>
    <div class="card-body">
      <div class="row dashboard-hotspot-grid seller-sales-summary-grid">

        <div class="col-3 col-box-6 seller-bootstrap-col">
          <div class="box bg-blue bmh-75">
            <a href="#">
              <h1><?= $TotalReg ?><span class="box-stat-unit"> vcr</span></h1>
              <div><i class="fa fa-ticket"></i> <?= isset($_vouchers) ? $_vouchers : 'Bons vendus' ?></div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6 seller-bootstrap-col">
          <div class="box bg-green bmh-75">
            <a href="#">
              <h1><?= $totalRevenue > 0 ? mikhmon_revenue_money($sellerRevenueVisible, $totalRevenue, $currency, $cekindo) : '—' ?></h1>
              <div><i class="fa fa-money"></i> <?= isset($_seller_ca) ? $_seller_ca : 'Chiffre d\'affaires' ?></div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6 seller-bootstrap-col">
          <div class="box bg-yellow bmh-75">
            <a href="#">
              <?php if ($sellerCommissionRate > 0 && $totalRevenue > 0): ?>
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $sellerCommissionAmount, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-percent"></i> Commission <?= $sellerCommissionRate ?>%</div>
              <?php elseif ($sellerCommissionRate > 0): ?>
              <h1><?= isset($currency) ? $currency : '' ?> 0</h1>
              <div><i class="fa fa-percent"></i> Commission <?= $sellerCommissionRate ?>%</div>
              <?php else: ?>
              <h1>—</h1>
              <div><i class="fa fa-percent"></i> Commission</div>
              <?php endif; ?>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6 seller-bootstrap-col">
          <div class="box bg-red bmh-75">
            <a href="./sellers.php?action=tickets">
              <h1><?= $totalStockVendor ?><span class="box-stat-unit"> vcr</span></h1>
              <div class="seller-sales-stock-list"><i class="fa fa-archive"></i> Stock disponible
                <?php if (!empty($sellerStock)): foreach ($sellerStock as $p => $q): ?>
                · <?= htmlspecialchars($p) ?>:<?= $q ?>
                <?php endforeach; endif; ?>
              </div>
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ── Formule commission ─────────────────────────────────────────────── -->
  <?php if ($sellerCommissionRate > 0 && $totalRevenue > 0): ?>
  <div class="card seller-dash-card" style="margin-bottom:14px;">
    <div class="card-header"><h3><i class="fa fa-calculator"></i> Calcul commission</h3></div>
    <div class="card-body">
      <div class="row dashboard-hotspot-grid">

        <div class="col-3 col-box-6">
          <div class="box bg-blue bmh-75">
            <a href="#">
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $totalRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-money"></i> Ventes totales · <?= $TotalReg ?> vcr</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-yellow bmh-75">
            <a href="#">
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $sellerCommissionAmount, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-percent"></i> − Commission <?= $sellerCommissionRate ?>%</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-green bmh-75">
            <a href="#">
              <h1><?= mikhmon_revenue_money($sellerRevenueVisible, $totalNetRevenue, $currency, $cekindo) ?></h1>
              <div><i class="fa fa-bank"></i> = Net caisse · <?= $sellerCommissionRate ?>% déduit</div>
            </a>
          </div>
        </div>

        <div class="col-3 col-box-6">
          <div class="box bg-red bmh-75">
            <a href="#">
              <h1><?= $sellerCommissionRate ?><span class="box-stat-unit">%</span></h1>
              <div><i class="fa fa-scissors"></i> Taux commission</div>
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tableau des ventes avec commission par ligne -->
  <div class="overflow box-bordered table-responsive portal-sales-table-wrap" style="max-height:70vh">
    <table id="dataTable" class="table table-bordered table-hover text-nowrap portal-sales-table seller-sales-table">
      <thead class="thead-light">
        <tr>
          <th>&#8470;</th>
          <th><?= $_date ?></th>
          <th><?= $_time ?></th>
          <th><?= $_user_name ?></th>
          <th><?= $_profile ?></th>
          <?php if ($sellerCommissionRate > 0): ?>
          <th class="text-right" style="color:#f57f17;">Prix</th>
          <th class="text-right" style="color:#8e44ad;"><i class="fa fa-percent"></i> Commission <?= $sellerCommissionRate ?>%</th>
          <th class="text-right" style="color:#27ae60;">Net caisse</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php
          $rowNum = 1;
          $colCount = $sellerCommissionRate > 0 ? 8 : 5;
          foreach ($getData as $sale):
              $salePrice = mikhmon_parse_money_amount(isset($sale['price']) ? $sale['price'] : 0);
              $saleComm  = $salePrice * $sellerCommissionRate / 100;
              $saleNet   = $salePrice - $saleComm;
        ?>
        <tr>
          <td data-label="№"><span class="seller-sales-cell-value"><?= $rowNum++ ?></span></td>
          <td data-label="<?= htmlspecialchars($_date) ?>"><span class="seller-sales-cell-value"><?= htmlspecialchars($sale['date']) ?></span></td>
          <td data-label="<?= htmlspecialchars($_time) ?>"><span class="seller-sales-cell-value"><?= htmlspecialchars($sale['time']) ?></span></td>
          <td data-label="<?= htmlspecialchars($_user_name) ?>"><span class="seller-sales-cell-value"><?= htmlspecialchars($sale['user']) ?></span></td>
          <td data-label="<?= htmlspecialchars($_profile) ?>"><span class="seller-sales-cell-value"><?= htmlspecialchars($sale['profile']) ?></span></td>
          <?php if ($sellerCommissionRate > 0): ?>
          <td class="text-right" data-label="Prix" style="color:#f57f17;"><span class="seller-sales-cell-value"><?= mikhmon_revenue_money($sellerRevenueVisible, $salePrice, $currency, $cekindo) ?></span></td>
          <td class="text-right" data-label="Commission <?= (int)$sellerCommissionRate ?>%" style="color:#8e44ad;font-weight:bold;"><span class="seller-sales-cell-value">− <?= mikhmon_revenue_money($sellerRevenueVisible, $saleComm, $currency, $cekindo) ?></span></td>
          <td class="text-right" data-label="Net caisse" style="color:#27ae60;font-weight:bold;"><span class="seller-sales-cell-value"><?= mikhmon_revenue_money($sellerRevenueVisible, $saleNet, $currency, $cekindo) ?></span></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php
          if ($TotalReg === 0) {
              echo '<tr class="seller-sales-empty-row"><td colspan="' . $colCount . '" class="text-center" data-label=""><span class="seller-sales-cell-value"><i class="fa fa-info-circle"></i> ' . (isset($_seller_no_sales) ? $_seller_no_sales : 'No sales found') . '</span></td></tr>';
          }
        ?>
      </tbody>
      <?php if ($TotalReg > 0 && $sellerCommissionRate > 0): ?>
      <tfoot>
        <tr class="seller-sales-total-row">
          <td colspan="5" data-label="Total"><span class="seller-sales-cell-value"><i class="fa fa-sigma"></i> TOTAL (<?= $TotalReg ?> ticket<?= $TotalReg > 1 ? 's' : '' ?>)</span></td>
          <td class="text-right total-gross" data-label="Prix"><span class="seller-sales-cell-value"><?= mikhmon_revenue_money($sellerRevenueVisible, $totalRevenue, $currency, $cekindo) ?></span></td>
          <td class="text-right total-commission" data-label="Commission <?= (int)$sellerCommissionRate ?>%"><span class="seller-sales-cell-value">− <?= mikhmon_revenue_money($sellerRevenueVisible, $sellerCommissionAmount, $currency, $cekindo) ?></span></td>
          <td class="text-right total-net" data-label="Net caisse"><span class="seller-sales-cell-value"><?= mikhmon_revenue_money($sellerRevenueVisible, $totalNetRevenue, $currency, $cekindo) ?></span></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <!-- Aide -->
  <div class="portal-help-box" style="margin-top:15px;padding:10px;background:#f0f0f0;border-radius:5px;font-size:13px;color:#243447;line-height:1.6;">
    <i class="fa fa-info-circle"></i>
    <?= isset($_seller_step3) ? strip_tags($_seller_step3) : 'This table shows only your voucher tickets.' ?>
  </div>

</div>
</div>
</div>
</div>

<?php endif; // end action=transfer vs sales ?>

</div><!-- main-container -->
</div><!-- main -->

<?php endif; // end seller_logged_in ?>

<!-- Confirmation modal -->
<div id="confirmModal">
  <div class="confirm-box">
    <h3><i class="fa fa-exchange" style="color:#2980b9;"></i> <span id="confirmModalTitle"></span></h3>
    <p id="confirmModalBody"></p>
    <div class="confirm-actions">
      <button class="btn-confirm-cancel" id="confirmCancel">
        <i class="fa fa-times"></i> <?= isset($_cancel) ? $_cancel : 'Cancel' ?>
      </button>
      <button class="btn-confirm-ok" id="confirmOk">
        <i class="fa fa-check"></i> <?= isset($_confirm) ? $_confirm : 'Confirm' ?>
      </button>
    </div>
  </div>
</div>

<script src="js/mikhmon-ui.<?= $theme ?>.min.js"></script>
<script src="js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")) ?>"></script>
<?php if ($seller_logged_in): ?>
<script>
$(document).ready(function(){
    $("#filterTable").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#dataTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
    function openSidenav() {
        $("#sidenav").css("width","220px");
        if ($(window).width() <= 750) {
            $("#sidenav-overlay").addClass("active");
            $("#closeSidenav").css("display","block");
        }
        // Rétablir les .menu items cachés par le dark-theme JS
        $("#sidenav a.menu").css("display","");
    }
    function closeSidenav() {
        $("#sidenav").css("width","0");
        $("#sidenav-overlay").removeClass("active");
        $("#closeSidenav").css("display","none");
        // Annuler les inline styles laissés par le dark-theme JS
        $("#openNav").css("display","");
        $("#closeNav").css("display","");
    }
    $("#openNav").on("click", openSidenav);
    $("#closeNav").on("click", closeSidenav);
    $("#closeSidenav").on("click", closeSidenav);
    $("#sidenav-overlay").on("click", closeSidenav);
});

// ── Modal de confirmation de transfert ──────────────────────────────────────
function confirmTransfer(form) {
    var prof  = form.querySelector('[name="transfer_profile"]').value;
    var qty   = form.querySelector('[name="transfer_qty"]').value;
    var dstSel = form.querySelector('[name="target_seller"]');
    var dst   = dstSel.options[dstSel.selectedIndex].text;
    if (!prof || !qty || !dstSel.value) return true;
    return showConfirmModal(
        '<?= addslashes(isset($_transfer_submit) ? $_transfer_submit : "Transfer") ?>',
        qty + ' ticket(s) [' + prof + '] → <b>' + dst + '</b>'
    );
}
function showConfirmModal(title, body) {
    var m = document.getElementById('confirmModal');
    if (!m) return true;
    document.getElementById('confirmModalTitle').textContent = title;
    document.getElementById('confirmModalBody').innerHTML = body;
    m.style.display = 'flex';
    return false; // block submit until confirmed
}
document.addEventListener('DOMContentLoaded', function() {
    var ok  = document.getElementById('confirmOk');
    var no  = document.getElementById('confirmCancel');
    var m   = document.getElementById('confirmModal');
    var pending = null;
    if (!ok) return;
    // Patch forms to use modal
    document.querySelectorAll('form[onsubmit*="confirmTransfer"]').forEach(function(f) {
        f.removeAttribute('onsubmit');
        f.addEventListener('submit', function(e) {
            var prof  = f.querySelector('[name="transfer_profile"]');
            var qty   = f.querySelector('[name="transfer_qty"]');
            var dstSel = f.querySelector('[name="target_seller"]');
            if (!prof || !qty || !dstSel || !dstSel.value || !prof.value) return;
            e.preventDefault();
            var dst = dstSel.options[dstSel.selectedIndex].text;
            document.getElementById('confirmModalTitle').textContent =
                '<?= addslashes(isset($_transfer_submit) ? $_transfer_submit : "Transfer") ?>';
            document.getElementById('confirmModalBody').innerHTML =
                '<b>' + qty.value + '</b> ticket(s) [' + prof.value + '] → <b>' + dst + '</b>';
            m.style.display = 'flex';
            pending = f;
        });
    });
    ok.addEventListener('click', function() { m.style.display='none'; if(pending) pending.submit(); });
    no.addEventListener('click', function() { m.style.display='none'; pending=null; });
    m.addEventListener('click', function(e){ if(e.target===m){ m.style.display='none'; pending=null; }});
});
</script>
<?php endif; ?>
</div><!-- wrapper -->
</body>
</html>
