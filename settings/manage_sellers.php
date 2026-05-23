<?php
/*
 * Gestion des vendeurs - MIKHMON
 * Accessible uniquement par l'administrateur.
 */
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
    header("Location:./admin.php?id=login");
    exit;
}

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include('./include/sellers_config.php');
include('./include/managers_config.php');
include('./include/config.php');
include_once('./include/csrf.php');
include_once('./include/mikhmon_compat.php');
include_once('./include/transfer_log.php');
include_once('./include/seller_ticket_helper.php');
include_once('./include/accounting_notifications.php');

$session = isset($_GET['session']) ? $_GET['session'] : '';
include('./include/readcfg.php');
if (empty($mikhmon_router_session_valid)) {
    ob_end_clean();
    $missingSession = rawurlencode((string)$session);
    header("Location:./admin.php?id=sessions&missing-session=" . $missingSession);
    exit;
}

$sellers_file  = './include/sellers_config.php';
$managers_file = './include/managers_config.php';
$msg           = '';
$msg_mgr       = '';
$transfer_msg   = '';
$transfer_error = '';
$transfer_log_msg   = '';
$transfer_log_error = '';
$force_active_tab   = '';
$API_ms_connected  = false;

// ── Stock de tous les vendeurs (tickets non utilisés) ────────────────────────
$allSellerStock  = array(); // ['sellerKey']['profile'] = count
$allStockUsers   = array(); // users non utilisés assignés à un vendeur
$globalStock     = array(); // ['profile'] = count  (non assignés)
$globalStockIds  = array(); // ['profile'] = ['.id', ...]  (pour distribution)

if (!empty($iphost)) {
    $API_ms = new RouterosAPI();
    $API_ms->debug = false;
    if ($API_ms->connect($iphost, $userhost, decrypt($passwdhost))) {
        $API_ms_connected = true;
        $unusedAll = $API_ms->comm("/ip/hotspot/user/print", array("?uptime" => "0s"));
        if (is_array($unusedAll)) {
            foreach ($sellers_data as $sk => $sd) {
                $allSellerStock[$sk] = array();
            }
            foreach ($unusedAll as $u) {
                $cmt  = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                $prof = isset($u['profile']) ? $u['profile'] : '(unknown)';
                $assigned = false;
                foreach ($sellers_data as $sk => $sd) {
                    $sfx = '-' . strtolower($sk);
                    if ($cmt === strtolower($sk) || substr($cmt, -strlen($sfx)) === $sfx) {
                        if (!isset($allSellerStock[$sk][$prof])) $allSellerStock[$sk][$prof] = 0;
                        $allSellerStock[$sk][$prof]++;
                        $allStockUsers[] = $u;
                        $assigned = true;
                        break;
                    }
                }
                // Ticket non assigné → stock global
                if (!$assigned && isset($u['.id'])) {
                    if (!isset($globalStock[$prof]))    $globalStock[$prof]    = 0;
                    if (!isset($globalStockIds[$prof])) $globalStockIds[$prof] = array();
                    $globalStock[$prof]++;
                    $globalStockIds[$prof][] = $u['.id'];
                }
            }
        }
    }
}

// ── Transfert admin ──────────────────────────────────────────────────────────
if (isset($_POST['admin_transfer']) && !empty($sellers_data)) {
    csrf_guard();
    $src    = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['src_seller']));
    $dst    = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['dst_seller']));
    $tprof  = trim($_POST['transfer_profile']);
    $tqty   = max(1, (int)$_POST['transfer_qty']);

    if (!isset($sellers_data[$src]) || !isset($sellers_data[$dst]) || $src === $dst) {
        $transfer_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select valid source and destination vendors.';
    } elseif ($tprof === '') {
        $transfer_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile.';
    } elseif (!isset($allSellerStock[$src][$tprof]) || $allSellerStock[$src][$tprof] < $tqty) {
        $transfer_error = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock.';
    } else {
        if (!isset($API_ms)) { $API_ms = new RouterosAPI(); $API_ms->debug = false; $API_ms->connect($iphost, $userhost, decrypt($passwdhost)); }
        $done = 0;
        $srcKey = strtolower($src);
        $sfxKey = '-' . $srcKey;
        foreach ($allStockUsers as $u) {
            if ($done >= $tqty) break;
            if ((isset($u['profile']) && $u['profile'] === $tprof)) {
                $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                if ($cmt === $srcKey || substr($cmt, -strlen($sfxKey)) === $sfxKey) {
                    $API_ms->comm("/ip/hotspot/user/set", array(
                        ".id" => $u['.id'],
                        "comment" => mikhmon_comment_assign_seller(isset($u['comment']) ? $u['comment'] : '', $dst, $sellers_data)
                    ));
                    $done++;
                }
            }
        }
        $transfer_msg = $done . ' ' . (isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to') . ' <b>' . htmlspecialchars($sellers_data[$dst]['name']) . '</b>';
        if ($done > 0) {
            log_transfer(
                $src, $sellers_data[$src]['name'],
                $dst, $sellers_data[$dst]['name'],
                $tprof, $done,
                'admin', $_SESSION['mikhmon'] ?? 'admin'
            );
        }
        // Refresh stock counts
        if ($done > 0) {
            $allSellerStock[$src][$tprof] -= $done;
            if ($allSellerStock[$src][$tprof] <= 0) unset($allSellerStock[$src][$tprof]);
            if (!isset($allSellerStock[$dst][$tprof])) $allSellerStock[$dst][$tprof] = 0;
            $allSellerStock[$dst][$tprof] += $done;
        }
    }
}

// ── Distribution globale (stock non assigné → vendeurs) ─────────────────────
$bulk_msg = '';
if (isset($_POST['bulk_distribute']) && !empty($sellers_data)) {
    csrf_guard();
    $dist_profile = trim(isset($_POST['dist_profile']) ? $_POST['dist_profile'] : '');
    $vendor_qty   = (isset($_POST['vendor_qty']) && is_array($_POST['vendor_qty'])) ? $_POST['vendor_qty'] : array();

    if ($dist_profile !== '' && !empty($vendor_qty)) {
        if (!isset($API_ms)) {
            $API_ms = new RouterosAPI(); $API_ms->debug = false;
            $API_ms->connect($iphost, $userhost, decrypt($passwdhost));
        }
        // Recalculer les IDs non assignés pour ce profil (données fraîches)
        $freshUnused = $API_ms->comm("/ip/hotspot/user/print", array("?uptime" => "0s", "?profile" => $dist_profile));
        $freshUsers = array();
        if (is_array($freshUnused)) {
            foreach ($freshUnused as $u) {
                if (!isset($u['.id'])) continue;
                $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                $assigned = false;
                foreach ($sellers_data as $sk => $sd) {
                    $sfx = '-' . strtolower($sk);
                    if ($cmt === strtolower($sk) || substr($cmt, -strlen($sfx)) === $sfx) {
                        $assigned = true; break;
                    }
                }
                if (!$assigned) $freshUsers[] = $u;
            }
        }

        $pointer = 0;
        $ok_parts  = array();
        $err_parts = array();

        foreach ($vendor_qty as $vk => $qty) {
            $vk  = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$vk);
            $qty = max(0, (int)$qty);
            if ($qty <= 0 || !isset($sellers_data[$vk])) continue;

            $done = 0;
            while ($done < $qty && $pointer < count($freshUsers)) {
                $targetUser = $freshUsers[$pointer];
                $API_ms->comm("/ip/hotspot/user/set", array(
                    ".id" => $targetUser['.id'],
                    "comment" => mikhmon_comment_assign_seller(isset($targetUser['comment']) ? $targetUser['comment'] : '', $vk, $sellers_data)
                ));
                $pointer++; $done++;
            }
            if ($done > 0) {
                log_transfer('(global)', 'Stock global', $vk, $sellers_data[$vk]['name'], $dist_profile, $done, 'admin', isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : 'admin');
                if (!isset($allSellerStock[$vk][$dist_profile])) $allSellerStock[$vk][$dist_profile] = 0;
                $allSellerStock[$vk][$dist_profile] += $done;
                $ok_parts[] = '<b>' . $done . '</b> → ' . htmlspecialchars($sellers_data[$vk]['name']);
            }
            if ($done < $qty) {
                $err_parts[] = isset($_transfer_insufficient_for)
                    ? sprintf($_transfer_insufficient_for, htmlspecialchars($sellers_data[$vk]['name']), $qty - $done)
                    : 'Insufficient stock for <b>' . htmlspecialchars($sellers_data[$vk]['name']) . '</b> (missing ' . ($qty - $done) . ')';
            }
        }
        // Mettre à jour le stock global en mémoire
        if (!empty($ok_parts) && isset($globalStock[$dist_profile])) {
            $globalStock[$dist_profile] -= $pointer;
            if ($globalStock[$dist_profile] <= 0) unset($globalStock[$dist_profile]);
            // Retirer les IDs utilisés de globalStockIds
            if (isset($globalStockIds[$dist_profile])) {
                $globalStockIds[$dist_profile] = array_slice($globalStockIds[$dist_profile], $pointer);
            }
        }
        if (!empty($ok_parts)) {
            $bulk_msg .= '<div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-bottom:8px;"><i class="fa fa-check-circle"></i> Distribution [' . htmlspecialchars($dist_profile) . '] : ' . implode(', ', $ok_parts) . '</div>';
        }
        if (!empty($err_parts)) {
            $bulk_msg .= '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-bottom:8px;"><i class="fa fa-ban"></i> ' . implode('<br>', $err_parts) . '</div>';
        }
    }
}

// ── Journal des transferts : suppression admin ─────────────────────────────
if (isset($_POST['delete_transfer_log']) || isset($_POST['clear_transfer_logs'])) {
    csrf_guard();
    $force_active_tab = 'transfers';

    if (isset($_POST['clear_transfer_logs'])) {
        if (clear_transfer_logs()) {
            $transfer_log_msg = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> '
                . (isset($_transfer_log_clear_done) ? $_transfer_log_clear_done : 'Transfer log cleared.') . '</div>';
        } else {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        }
    } elseif (isset($_POST['delete_transfer_log'])) {
        $logRow = isset($_POST['log_row']) ? (int)$_POST['log_row'] : -1;
        if ($logRow < 0) {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        } elseif (delete_transfer_log_entry($logRow)) {
            $transfer_log_msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> '
                . (isset($_transfer_log_delete_done) ? $_transfer_log_delete_done : 'Transfer log entry deleted.') . '</div>';
        } else {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        }
    }
}

// ── Ajouter un vendeur ───────────────────────────────────────────────────────
if (isset($_POST['add_seller']) || isset($_POST['change_pass']) || isset($_POST['update_seller_account']) || isset($_POST['add_manager']) || isset($_POST['change_manager_pass']) || isset($_POST['update_manager_account']) || isset($_POST['delete_seller']) || isset($_POST['delete_manager'])) {
    csrf_guard();
}
if (isset($_POST['admin_send_accounting_notice'])) {
    csrf_guard();
}

function mikhmon_default_account($accounts, $usernamePrefix, $displayPrefix) {
    $next = 1;
    if (is_array($accounts)) {
        foreach (array_keys($accounts) as $accountKey) {
            if (preg_match('/^' . preg_quote($usernamePrefix, '/') . '([0-9]+)$/i', $accountKey, $matches)) {
                $next = max($next, ((int)$matches[1]) + 1);
            }
        }
    }

    $suffix = str_pad((string)$next, 2, '0', STR_PAD_LEFT);
    $username = strtolower($usernamePrefix . $suffix);

    return array(
        'username' => $username,
        'password' => $username . '@123',
        'name' => $displayPrefix . ' ' . $suffix,
    );
}

function mikhmon_account_key($value) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)$value));
}

function mikhmon_account_label($value) {
    $key = mikhmon_account_key($value);
    if ($key === '') return '';
    return ucfirst(strtolower($key));
}

if (isset($_POST['add_seller'])) {
    $new_user    = mikhmon_account_key($_POST['new_user']);
    $new_pass    = trim($_POST['new_pass']);
    $new_name    = mikhmon_account_label($new_user);
    $new_session = trim($_POST['new_session']);

    if ($new_user == '' || $new_pass == '' || $new_name == '') {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif (isset($sellers_data[$new_user])) {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_seller_exists . '</div>';
    } else {
        $encrypted_pass = encrypt($new_pass);
        file_put_contents($sellers_file, mikhmon_php_assignment_line('sellers_data', $new_user, array(
            'password' => $encrypted_pass,
            'name' => $new_name,
            'session' => $new_session,
            'commission' => 10,
        )), FILE_APPEND | LOCK_EX);
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_seller . ' <b>' . $new_user . '</b> OK.<br><small>' . $_seller_id . ': <b>' . htmlspecialchars($new_user) . '</b> | ' . $_password . ': <b>' . htmlspecialchars($new_pass) . '</b></small></div>';
        // Recharger la config
        include($sellers_file);
    }
}

// ── Supprimer un vendeur ─────────────────────────────────────────────────────
if (isset($_POST['delete_seller'])) {
    $del = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['delete_seller']);
    if ($del != '') {
        mikhmon_delete_assignment_line_in_file($sellers_file, 'sellers_data', $del);
        $msg = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> ' . $_seller . ' <b>' . htmlspecialchars($del) . '</b>.</div>';
        unset($sellers_data[$del], $allSellerStock[$del]);
    }
}

// ── Modifier un compte vendeur ───────────────────────────────────────────────
if (isset($_POST['update_seller_account'])) {
    $old_user = mikhmon_account_key(isset($_POST['edit_seller_user']) ? $_POST['edit_seller_user'] : '');
    $new_user = mikhmon_account_key(isset($_POST['new_seller_user']) ? $_POST['new_seller_user'] : '');
    $new_name = trim(isset($_POST['new_seller_name']) ? $_POST['new_seller_name'] : '');
    $new_pass = trim(isset($_POST['new_seller_pass']) ? $_POST['new_seller_pass'] : '');

    if ($old_user === '' || !isset($sellers_data[$old_user])) {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif ($new_user === '' || $new_name === '') {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif ($new_user !== $old_user && isset($sellers_data[$new_user])) {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_seller_exists . '</div>';
    } else {
        $currentSeller = $sellers_data[$old_user];
        $sellerRecord = array(
            'password' => $new_pass !== '' ? encrypt($new_pass) : $currentSeller['password'],
            'name' => $new_name,
            'session' => isset($currentSeller['session']) ? $currentSeller['session'] : $session,
            'commission' => isset($currentSeller['commission']) ? (int)$currentSeller['commission'] : 0,
        );

        if (mikhmon_replace_assignment_line_in_file($sellers_file, 'sellers_data', $new_user, $sellerRecord, $old_user)) {
            unset($sellers_data[$old_user]);
            $sellers_data[$new_user] = $sellerRecord;
            if ($new_user !== $old_user && isset($allSellerStock[$old_user])) {
                $allSellerStock[$new_user] = $allSellerStock[$old_user];
                unset($allSellerStock[$old_user]);
            }
            $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_seller . ' <b>' . htmlspecialchars($new_user) . '</b> OK.</div>';
        } else {
            $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
        }
    }
}

// ── Modifier le mot de passe d'un vendeur ────────────────────────────────────
if (isset($_POST['change_pass'])) {
    $cp_user = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['cp_user']));
    $cp_pass = trim($_POST['cp_pass']);
    if ($cp_user != '' && $cp_pass != '' && isset($sellers_data[$cp_user])) {
        $encrypted_new = encrypt($cp_pass);
        $curr_comm = isset($sellers_data[$cp_user]['commission']) ? (int)$sellers_data[$cp_user]['commission'] : 0;
        $fc = file($sellers_file);
        $f  = fopen($sellers_file, 'w');
        foreach ($fc as $line) {
            if (strpos($line, '$sellers_data[\'' . $cp_user . '\']') !== false) {
                $line = mikhmon_php_assignment_line('sellers_data', $cp_user, array(
                    'password' => $encrypted_new,
                    'name' => $sellers_data[$cp_user]['name'],
                    'session' => $sellers_data[$cp_user]['session'],
                    'commission' => $curr_comm,
                ));
            }
            fputs($f, $line);
        }
        fclose($f);
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_password . ' ' . $_seller . ' <b>' . htmlspecialchars($cp_user) . '</b> OK.</div>';
        $sellers_data[$cp_user]['password'] = $encrypted_new;
    }
}

// ── Modifier la commission d'un vendeur ──────────────────────────────────────
if (isset($_POST['set_commission'])) {
    csrf_guard();
    $sc_user = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['sc_user']));
    $sc_rate = max(0, min(100, (int)$_POST['sc_rate']));
    if ($sc_user != '' && isset($sellers_data[$sc_user])) {
        mikhmon_replace_assignment_line_in_file($sellers_file, 'sellers_data', $sc_user, array(
            'password' => $sellers_data[$sc_user]['password'],
            'name' => $sellers_data[$sc_user]['name'],
            'session' => $sellers_data[$sc_user]['session'],
            'commission' => $sc_rate,
        ));
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> Commission <b>' . htmlspecialchars($sc_user) . '</b> → <b>' . $sc_rate . '%</b></div>';
        $sellers_data[$sc_user]['commission'] = $sc_rate;
    }
}

// ── Ajouter un gérant ────────────────────────────────────────────────────────
if (isset($_POST['add_manager'])) {
    $nmu = mikhmon_account_key($_POST['nm_user']);
    $nmp = trim($_POST['nm_pass']);
    $nmn = mikhmon_account_label($nmu);
    $nms = trim($_POST['nm_session']);
    if ($nmu == '' || $nmp == '' || $nmn == '') {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif (isset($managers_data[$nmu])) {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . (isset($_manager_exists) ? $_manager_exists : 'Already exists.') . '</div>';
    } else {
        $ep = encrypt($nmp);
        file_put_contents($managers_file, mikhmon_php_assignment_line('managers_data', $nmu, array(
            'password' => $ep,
            'name' => $nmn,
            'session' => $nms,
        )), FILE_APPEND | LOCK_EX);
        $msg_mgr = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . (isset($_manager) ? $_manager : 'Manager') . ' <b>' . $nmu . '</b> OK.<br><small>' . $_seller_id . ': <b>' . htmlspecialchars($nmu) . '</b> | ' . $_password . ': <b>' . htmlspecialchars($nmp) . '</b></small></div>';
        include($managers_file);
    }
}
// ── Supprimer un gérant ───────────────────────────────────────────────────────
if (isset($_POST['delete_manager'])) {
    $force_active_tab = 'managers';
    $dm = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['delete_manager']);
    if ($dm != '') {
        mikhmon_delete_assignment_line_in_file($managers_file, 'managers_data', $dm);
        $msg_mgr = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> ' . (isset($_manager) ? $_manager : 'Manager') . ' <b>' . htmlspecialchars($dm) . '</b>.</div>';
        unset($managers_data[$dm]);
    }
}

// ── Modifier un compte gérant ───────────────────────────────────────────────
if (isset($_POST['update_manager_account'])) {
    $force_active_tab = 'managers';
    $old_manager = mikhmon_account_key(isset($_POST['edit_manager_user']) ? $_POST['edit_manager_user'] : '');
    $new_manager = mikhmon_account_key(isset($_POST['new_manager_user']) ? $_POST['new_manager_user'] : '');
    $new_name = trim(isset($_POST['new_manager_name']) ? $_POST['new_manager_name'] : '');
    $new_pass = trim(isset($_POST['new_manager_pass']) ? $_POST['new_manager_pass'] : '');

    if ($old_manager === '' || !isset($managers_data[$old_manager]) || $new_manager === '' || $new_name === '') {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif ($new_manager !== $old_manager && isset($managers_data[$new_manager])) {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . (isset($_manager_exists) ? $_manager_exists : 'Already exists.') . '</div>';
    } else {
        $currentManager = $managers_data[$old_manager];
        $managerRecord = array(
            'password' => $new_pass !== '' ? encrypt($new_pass) : $currentManager['password'],
            'name' => $new_name,
            'session' => isset($currentManager['session']) ? $currentManager['session'] : $session,
        );

        if (mikhmon_replace_assignment_line_in_file($managers_file, 'managers_data', $new_manager, $managerRecord, $old_manager)) {
            unset($managers_data[$old_manager]);
            $managers_data[$new_manager] = $managerRecord;
            $msg_mgr = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . (isset($_manager) ? $_manager : 'Manager') . ' <b>' . htmlspecialchars($new_manager) . '</b> OK.</div>';
        } else {
            $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
        }
    }
}

// ── Modifier le mot de passe d'un gérant ─────────────────────────────────────
if (isset($_POST['change_manager_pass'])) {
    $cmu = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['cmp_user']));
    $cmp = trim($_POST['cmp_pass']);
    if ($cmu != '' && $cmp != '' && isset($managers_data[$cmu])) {
        $en  = encrypt($cmp);
        $fc  = file($managers_file);
        $f   = fopen($managers_file, 'w');
        foreach ($fc as $ln) {
            if (strpos($ln, '$managers_data[\'' . $cmu . '\']') !== false) {
                $ln = mikhmon_php_assignment_line('managers_data', $cmu, array(
                    'password' => $en,
                    'name' => $managers_data[$cmu]['name'],
                    'session' => $managers_data[$cmu]['session'],
                ));
            }
            fputs($f, $ln);
        }
        fclose($f);
        $msg_mgr = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_password . ' <b>' . htmlspecialchars($cmu) . '</b> OK.</div>';
        $managers_data[$cmu]['password'] = $en;
    }
}

// ── Lister les sessions disponibles ─────────────────────────────────────────
$available_sessions = array();
foreach ((array)$data as $sesname => $row) {
    if ($sesname != '' && $sesname != 'mikhmon' && is_array($row)) {
        $available_sessions[] = $sesname;
    }
}
$available_sessions = array_unique($available_sessions);
$defaultSellerAccount = mikhmon_default_account($sellers_data, 'vendeur', 'Vendeur');
$defaultManagerAccount = mikhmon_default_account($managers_data, 'gerant', 'Gerant');
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sellers';
if ($force_active_tab !== '') {
    $active_tab = $force_active_tab;
}
if (!in_array($active_tab, array('sellers', 'managers', 'accounting', 'transfers'), true)) {
    $active_tab = 'sellers';
}
$adminDashboardUrl = $session !== '' ? './?session=' . urlencode($session) : './admin.php?id=sessions';

// ── Comptabilité admin par arrêt de période ────────────────────────────────
$adminAccountingMonthKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', isset($_GET['acct_month']) ? $_GET['acct_month'] : ''));
if (!preg_match('/^[a-z]{3}\d{4}$/', $adminAccountingMonthKey)) {
    $adminAccountingMonthKey = strtolower(date("M")) . date("Y");
}
$adminAccountingBounds = mikhmon_accounting_month_bounds($adminAccountingMonthKey);
$adminAccountingFrom = mikhmon_accounting_iso_date(isset($_GET['acct_from']) ? $_GET['acct_from'] : '', $adminAccountingBounds['from']);
$adminAccountingTo = mikhmon_accounting_iso_date(isset($_GET['acct_to']) ? $_GET['acct_to'] : '', $adminAccountingBounds['to']);
if ($adminAccountingFrom > $adminAccountingTo) {
    $adminAccountingTmp = $adminAccountingFrom;
    $adminAccountingFrom = $adminAccountingTo;
    $adminAccountingTo = $adminAccountingTmp;
}

$adminAccountingSellersData = array();
foreach ($sellers_data as $sk => $sd) {
    $sellerSession = trim(isset($sd['session']) ? $sd['session'] : '');
    if ($sellerSession === '' || $sellerSession === $session) {
        $adminAccountingSellersData[$sk] = $sd;
    }
}
$adminAccountingSeller = preg_replace('/[^a-zA-Z0-9_]/', '', isset($_GET['acct_seller']) ? $_GET['acct_seller'] : '');
if ($adminAccountingSeller !== '' && !isset($adminAccountingSellersData[$adminAccountingSeller])) {
    $adminAccountingSeller = '';
}
$adminAccountingSettlementTime = mikhmon_accounting_settlement_time(isset($_GET['acct_settled_at']) ? $_GET['acct_settled_at'] : (isset($_POST['acct_settled_at']) ? $_POST['acct_settled_at'] : ''), date('H:i:s'));
$adminAccountingNextSettlementTime = mikhmon_accounting_settlement_time(isset($_GET['acct_next_settled_at']) ? $_GET['acct_next_settled_at'] : (isset($_POST['acct_next_settled_at']) ? $_POST['acct_next_settled_at'] : ''), $adminAccountingSettlementTime);

$adminAccountingSales = array();
if ($active_tab === 'accounting' && !empty($iphost)) {
    if (!isset($API_ms) || !$API_ms_connected) {
        $API_ms = new RouterosAPI();
        $API_ms->debug = false;
        $API_ms_connected = $API_ms->connect($iphost, $userhost, decrypt($passwdhost));
    }
    if ($API_ms_connected) {
        $adminAccountingSales = mikhmon_fetch_sales_by_month($API_ms, $adminAccountingMonthKey);
    }
}
$adminAccountingSummary = mikhmon_accounting_period_summary(
    $adminAccountingSales,
    $adminAccountingSellersData,
    $adminAccountingFrom,
    $adminAccountingTo,
    $adminAccountingSeller
);
$adminAccountingNextFrom = '';
$adminAccountingNextTo = $adminAccountingBounds['to'];
if ($adminAccountingTo !== '') {
    $adminAccountingNextDate = new DateTime($adminAccountingTo);
    $adminAccountingNextDate->modify('+1 day');
    $adminAccountingNextIso = $adminAccountingNextDate->format('Y-m-d');
    if ($adminAccountingNextIso <= $adminAccountingBounds['to']) {
        $adminAccountingNextFrom = $adminAccountingNextIso;
    }
}
$adminAccountingBaseUrl = './admin.php?id=sellers&session=' . urlencode($session) . '&tab=accounting&acct_month=' . urlencode($adminAccountingMonthKey);
$adminAccountingNoticeMsg = '';
$adminAccountingNoticeError = '';
$adminAccountingNoticeTargets = mikhmon_accounting_notification_targets($adminAccountingSummary, $adminAccountingSellersData, $adminAccountingSeller);
if (isset($_POST['admin_send_accounting_notice'])) {
    $sentCount = mikhmon_accounting_publish_notifications(
        'admin',
        isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : 'Admin',
        $session,
        $adminAccountingSellersData,
        $adminAccountingNoticeTargets,
        $adminAccountingFrom,
        $adminAccountingTo,
        $adminAccountingSettlementTime,
        $adminAccountingNextFrom,
        $adminAccountingNextTo,
        $adminAccountingNextSettlementTime
    );
    if ($sentCount > 0) {
        $adminAccountingNoticeMsg = $sentCount . ' notification(s) envoyée(s) aux vendeurs concernés.';
    } else {
        $adminAccountingNoticeError = 'Aucun vendeur concerné par cette période.';
    }
}
?>
<style>
/* ── Onglets Vendeurs / Gérants / Comptabilité / Transferts ── */
.ms-tab-bar { display:flex; gap:10px; border-bottom:2px solid #ddd; margin-bottom:18px; overflow-x:auto; overflow-y:hidden; padding-bottom:8px; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.ms-tab-btn { flex:0 0 auto; min-width:132px; white-space:nowrap; padding:10px 12px; border:none; background:none; font-weight:bold; font-size:13px; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color .15s,border-color .15s; outline:none; }
.ms-tab-btn i { display:block; font-size:16px; margin-bottom:3px; }
.ms-tab-sellers.ms-active  { color:#27ae60; border-bottom-color:#27ae60; }
.ms-tab-managers.ms-active { color:#8e44ad; border-bottom-color:#8e44ad; }
.ms-tab-accounting.ms-active { color:#5b2c8d; border-bottom-color:#5b2c8d; }
.ms-tab-transfers.ms-active{ color:#e67e22; border-bottom-color:#e67e22; }
.ms-tab-btn:not(.ms-active) { color:#6b7280; }
.ms-tab-section { display:none; }
.ms-tab-section.ms-active  { display:block; }
@media(max-width:750px){ .ms-tab-bar{margin:0 -4px 18px;padding:0 4px 8px;} .ms-tab-btn{min-width:116px;font-size:12px;padding:9px 10px;} }
@media(max-width:480px){ .ms-tab-btn{font-size:11px;min-width:102px;padding:8px 10px;} }
.admin-transfer-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}
.admin-transfer-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.transfer-label {
    font-weight: bold;
    font-size: 13px;
    color: #555;
}
.admin-accounting-cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
.admin-accounting-card { flex:1; min-width:150px; border-radius:8px; padding:14px 16px; }
.admin-accounting-card-label { font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:.5px; }
.admin-accounting-card-value { font-size:22px; font-weight:bold; margin-top:2px; }
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
.accounting-mobile-settlement { display:none; margin-top:6px; }
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
.table-responsive { overflow-x: auto; }
@media (max-width: 600px) {
    .admin-transfer-grid { grid-template-columns: 1fr; }
    .admin-accounting-card { min-width:100%; }
    .accounting-responsive-table { min-width:0 !important; border:0 !important; }
    .accounting-responsive-table thead { display:none; }
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
    .accounting-responsive-table .accounting-seller-cell:before { display:none; content:''; }
    .accounting-responsive-table .accounting-time-cell { display:none; }
    .accounting-mobile-settlement { display:inline-flex; }
    .accounting-responsive-table tfoot tr { background:#5b2c8d; }
    .accounting-responsive-table tfoot td {
      background:#5b2c8d !important;
      color:#fff !important;
    }
    .accounting-responsive-table tfoot td:before { color:rgba(255,255,255,.82); }
}
</style>

<div class="row portal-admin-shell">
<div class="col-12">
<div class="card">
<div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
  <h3 style="margin:0;"><i class="fa fa-users"></i> <?= $_manage_sellers ?></h3>
  <a href="<?= $adminDashboardUrl ?>" class="btn bg-primary">
    <i class="fa fa-dashboard"></i> <?= $_dashboard ?>
  </a>
</div>
<div class="card-body">

<?= $msg ?>

<!-- ── Instructions ── -->
<div class="portal-help-box" style="background:#fff3cd;color:#243447;padding:14px 16px;margin-bottom:15px;border-left:5px solid #ffc107;border-radius:4px;line-height:1.7;">
  <b style="color:#243447;font-size:14px;"><i class="fa fa-info-circle" style="color:#e6a800;"></i>&nbsp; <?= $_how_it_works ?></b>
  <ol style="margin:8px 0 0 20px;padding:0;color:#243447;font-size:13px;line-height:1.8;">
    <li><?= $_seller_step1 ?></li>
    <li><?= $_seller_step2 ?></li>
    <li><?= $_seller_step3 ?></li>
  </ol>
</div>

<!-- ── Barre d'onglets ── -->
<div class="ms-tab-bar">
  <button id="mstab-sellers" onclick="msTab('sellers')" type="button"
          class="ms-tab-btn ms-tab-sellers<?= $active_tab==='sellers' ? ' ms-active' : '' ?>">
    <i class="fa fa-users"></i>
    <?= isset($_sellers) ? $_sellers : 'Vendors' ?>
  </button>
  <button id="mstab-managers" onclick="msTab('managers')" type="button"
          class="ms-tab-btn ms-tab-managers<?= $active_tab==='managers' ? ' ms-active' : '' ?>">
    <i class="fa fa-briefcase"></i>
    <?= isset($_managers) ? $_managers : 'Managers' ?>
  </button>
  <button id="mstab-accounting" onclick="msTab('accounting')" type="button"
          class="ms-tab-btn ms-tab-accounting<?= $active_tab==='accounting' ? ' ms-active' : '' ?>">
    <i class="fa fa-calculator"></i>
    <?= isset($_manager_accounting) ? $_manager_accounting : 'Comptabilité' ?>
  </button>
  <button id="mstab-transfers" onclick="msTab('transfers')" type="button"
          class="ms-tab-btn ms-tab-transfers<?= $active_tab==='transfers' ? ' ms-active' : '' ?>">
    <i class="fa fa-exchange"></i>
    <?= isset($_transfer_logs) ? $_transfer_logs : 'Transfers' ?>
  </button>
</div>

<!-- ── Section Vendeurs ── -->
<div id="ms-section-sellers" class="ms-tab-section<?= $active_tab==='sellers' ? ' ms-active' : '' ?>">

<!-- ── Liste des vendeurs ── -->
<div class="card box-bordered" style="margin-bottom:15px;">
  <div class="card-header"><h4><i class="fa fa-list"></i> <?= $_registered_sellers ?></h4></div>
  <div class="card-body">
    <?php if (empty($sellers_data)): ?>
      <p class="text-center"><i class="fa fa-info-circle"></i> <?= $_no_seller_registered ?></p>
    <?php else: ?>
    <div class="table-responsive portal-table-wrap">
    <table class="table table-bordered table-hover portal-table-min-lg">
      <thead class="thead-light">
        <tr>
          <th style="color:#e74c3c;"><b><?= $_seller_id ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_display_name ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_session_router ?></b></th>
          <th class="text-center" style="color:#e74c3c;"><b><i class="fa fa-percent"></i> Commission</b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_link ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_action ?></b></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sellers_data as $su => $sd): ?>
        <?php $su_rate = isset($sd['commission']) ? (int)$sd['commission'] : 0; ?>
        <tr>
          <td><code><?= htmlspecialchars($su) ?></code></td>
          <td><?= htmlspecialchars($sd['name']) ?></td>
          <td><span class="badge"><?= htmlspecialchars($sd['session']) ?></span></td>
          <td class="text-center">
            <span style="color:#8e44ad;font-weight:bold;"><?= $su_rate ?>%</span>
            <a href="#commission_<?= htmlspecialchars($su) ?>" class="btn btn-sm" style="background:#f3e8fd;color:#8e44ad;border:1px solid #ce93d8;margin-left:4px;padding:2px 7px;font-size:11px;" title="Modifier la commission">
              <i class="fa fa-edit"></i>
            </a>
          </td>
          <td>
            <a href="../sellers.php" target="_blank" style="font-size:12px;">
              <i class="fa fa-external-link"></i> sellers.php
            </a>
          </td>
          <td>
            <a href="#edit_seller_<?= htmlspecialchars($su) ?>" class="btn bg-primary btn-sm" title="<?= isset($_edit) ? $_edit : 'Edit' ?>">
              <i class="fa fa-edit"></i>
            </a>
            <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>" onsubmit="return confirm('<?= isset($_delete) ? addslashes($_delete) : 'Delete' ?> <?= htmlspecialchars($su) ?> ?')" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_seller" value="<?= htmlspecialchars($su) ?>">
              <button type="submit" class="btn bg-danger btn-sm" title="<?= isset($_delete) ? $_delete : 'Delete' ?>">
                <i class="fa fa-trash"></i>
              </button>
            </form>
            <a href="#chgpass_<?= htmlspecialchars($su) ?>" class="btn bg-warning btn-sm" title="<?= $_password ?>">
              <i class="fa fa-key"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Formulaires édition compte vendeur -->
    <?php foreach ($sellers_data as $su => $sd): ?>
    <div class="modal-window" id="edit_seller_<?= htmlspecialchars($su) ?>" aria-hidden="true">
      <div>
        <header><h1><i class="fa fa-edit"></i> <?= isset($_edit) ? $_edit : 'Edit' ?> — <?= htmlspecialchars($su) ?></h1></header>
        <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
        <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td><?= $_seller_id ?> <small>(a-z, 0-9, _)</small></td>
              <td>
                <input type="hidden" name="edit_seller_user" value="<?= htmlspecialchars($su) ?>">
                <input class="form-control" type="text" name="new_seller_user" value="<?= htmlspecialchars($su) ?>" required>
              </td>
            </tr>
            <tr>
              <td><?= $_seller_display_name ?></td>
              <td><input class="form-control" type="text" name="new_seller_name" value="<?= htmlspecialchars(isset($sd['name']) ? $sd['name'] : '') ?>" required></td>
            </tr>
            <tr>
              <td><?= $_password ?></td>
              <td>
                <input class="form-control" id="seller-edit-pass-<?= htmlspecialchars($su) ?>" type="password" name="new_seller_pass" placeholder="<?= isset($_keep_password) ? $_keep_password : 'Laisser vide pour conserver le mot de passe actuel' ?>">
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                  <input type="checkbox" onclick="msTogglePassword('seller-edit-pass-<?= htmlspecialchars($su) ?>', this)">
                  <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                </label>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <button type="submit" name="update_seller_account" class="btn bg-primary">
                  <i class="fa fa-save"></i> <?= $_save ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Formulaires changement de mot de passe -->
    <?php foreach ($sellers_data as $su => $sd): ?>
    <div class="modal-window" id="chgpass_<?= htmlspecialchars($su) ?>" aria-hidden="true">
      <div>
        <header><h1><i class="fa fa-key"></i> <?= $_password ?> — <?= htmlspecialchars($su) ?></h1></header>
        <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
        <form autocomplete="off" method="post" action="">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td><?= $_new_password ?? $_password ?></td>
              <td>
                <input class="form-control" id="seller-pass-<?= htmlspecialchars($su) ?>" type="password" name="cp_pass" required>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                  <input type="checkbox" onclick="msTogglePassword('seller-pass-<?= htmlspecialchars($su) ?>', this)">
                  <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                </label>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <input type="hidden" name="cp_user" value="<?= htmlspecialchars($su) ?>">
                <button type="submit" name="change_pass" class="btn bg-primary">
                  <i class="fa fa-save"></i> <?= $_save ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Formulaires commission -->
    <?php foreach ($sellers_data as $su => $sd): ?>
    <?php $su_rate = isset($sd['commission']) ? (int)$sd['commission'] : 0; ?>
    <div class="modal-window" id="commission_<?= htmlspecialchars($su) ?>" aria-hidden="true">
      <div>
        <header><h1><i class="fa fa-percent"></i> Commission — <?= htmlspecialchars($su) ?></h1></header>
        <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
        <form autocomplete="off" method="post" action="">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td>Taux de commission (%)</td>
              <td>
                <input class="form-control" type="number" name="sc_rate" min="0" max="100" value="<?= $su_rate ?>" required style="max-width:120px;">
                <small style="color:#888;display:block;margin-top:4px;">0 = pas de commission · max 100%</small>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <input type="hidden" name="sc_user" value="<?= htmlspecialchars($su) ?>">
                <button type="submit" name="set_commission" class="btn bg-primary">
                  <i class="fa fa-save"></i> <?= $_save ?>
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

<!-- ── Distribution globale ── -->
<?php if (!empty($globalStock) && !empty($sellers_data)): ?>
<div class="card box-bordered" style="margin-bottom:15px;border-left:4px solid #e67e22;">
  <div class="card-header" style="background:linear-gradient(90deg,#fff3e0,#fff);">
    <h4 style="color:#e67e22;"><i class="fa fa-random"></i>
      <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?> —
      <small style="font-weight:normal;font-size:13px;"><?= isset($_global_stock_unassigned) ? $_global_stock_unassigned : 'Global unassigned stock' ?></small>
    </h4>
  </div>
  <div class="card-body">
    <?= $bulk_msg ?>

    <!-- Sélection du profil -->
    <p style="font-size:13px;color:#666;margin-bottom:10px;">
      <i class="fa fa-info-circle" style="color:#e67e22;"></i>
      Choisissez un profil puis saisissez la quantité à attribuer à chaque vendeur.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;" id="distProfBtns">
      <?php foreach ($globalStock as $prof => $total): ?>
      <button onclick="distSelectProfile(<?= htmlspecialchars(json_encode($prof)) ?>, <?= (int)$total ?>)"
              id="distprof-<?= htmlspecialchars(preg_replace('/[^a-z0-9_]/i','-',$prof)) ?>"
              type="button" class="btn btn-sm dist-prof-btn"
              style="background:#f8f9fa;border:2px solid #e67e22;font-weight:bold;border-radius:20px;padding:5px 14px;">
        <i class="fa fa-tag"></i> <?= htmlspecialchars($prof) ?>
        <span style="background:#e67e22;color:#fff;border-radius:10px;padding:1px 7px;margin-left:5px;font-size:12px;"><?= $total ?></span>
      </button>
      <?php endforeach; ?>
    </div>

    <form id="bulkDistForm" method="post" action="?id=sellers&session=<?= htmlspecialchars($session) ?>" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_distribute" value="1">
      <input type="hidden" name="dist_profile" id="distProfileInput" value="">

      <!-- Infos profil sélectionné -->
      <div style="padding:10px 14px;background:#fff3e0;border-radius:6px;border-left:3px solid #e67e22;margin-bottom:14px;font-size:14px;">
        <i class="fa fa-tag" style="color:#e67e22;"></i>
        Profil : <strong id="distCurrentProf"></strong> &nbsp;|&nbsp;
        <?= isset($_stock_available) ? $_stock_available : 'Available stock' ?> : <strong id="distAvail" style="color:#e67e22;"></strong> tickets &nbsp;|&nbsp;
        Saisi : <strong id="distSaisie" style="color:#333;">0</strong>
        / <span id="distSaisieMax" style="color:#e67e22;font-weight:bold;">0</span>
      </div>

      <!-- Tableau vendeurs -->
      <div class="table-responsive" style="max-width:560px;">
      <table class="table table-bordered portal-table-min-sm">
        <thead class="thead-light">
          <tr>
            <th><i class="fa fa-user"></i> Vendeur</th>
            <th style="width:170px;"><i class="fa fa-sort-numeric-asc"></i> Quantité</th>
            <th style="width:90px;" class="text-center">Alloué</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sellers_data as $sk => $sd): ?>
          <?php $safeKey = preg_replace('/[^a-z0-9_]/i','-', $sk); ?>
          <tr>
            <td>
              <b><?= htmlspecialchars($sd['name']) ?></b><br>
              <small><code style="color:#888;"><?= htmlspecialchars($sk) ?></code></small>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:6px;">
                <input type="number" name="vendor_qty[<?= htmlspecialchars($sk) ?>]"
                       id="distqty-<?= $safeKey ?>"
                       class="form-control dist-qty-input" data-key="<?= $safeKey ?>"
                       min="0" value="0" style="width:90px;"
                       oninput="distUpdateTotal()">
                <div style="display:flex;flex-direction:column;gap:2px;">
                  <button type="button" onclick="distMax('<?= $safeKey ?>')"
                          style="font-size:10px;padding:1px 6px;background:#e67e22;color:#fff;border:none;border-radius:3px;cursor:pointer;" title="Max">MAX</button>
                  <button type="button" onclick="distReset('<?= $safeKey ?>')"
                          style="font-size:10px;padding:1px 6px;background:#eee;color:#555;border:none;border-radius:3px;cursor:pointer;" title="Reset">0</button>
                </div>
              </div>
            </td>
            <td class="text-center" id="distalloc-<?= $safeKey ?>" style="color:#aaa;font-weight:bold;">—</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:bold;background:#f9f9f9;">
            <td>Total</td>
            <td>
              <span id="distGrandTotal" style="color:#e67e22;font-size:16px;">0</span>
              / <span id="distGrandMax" style="color:#e67e22;">0</span>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      </div>

      <button type="submit" id="distSubmitBtn" class="btn" disabled
              style="background:#e67e22;color:#fff;font-weight:bold;padding:10px 24px;font-size:15px;">
        <i class="fa fa-random"></i> Distribuer
      </button>
      <button type="button" onclick="distReset('__all__')"
              style="margin-left:8px;background:#eee;color:#555;border:none;border-radius:5px;padding:10px 16px;cursor:pointer;">
        <i class="fa fa-undo"></i> Réinitialiser
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Transfert de stock admin ── -->
<div class="card box-bordered" style="margin-bottom:15px;">
  <div class="card-header"><h4><i class="fa fa-exchange"></i> <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?></h4></div>
  <div class="card-body">

    <?php if ($transfer_msg): ?>
      <div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-bottom:12px;">
        <i class="fa fa-check-circle"></i> <?= $transfer_msg ?>
      </div>
    <?php endif; ?>
    <?php if ($transfer_error): ?>
      <div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-bottom:12px;">
        <i class="fa fa-ban"></i> <?= htmlspecialchars($transfer_error) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($sellers_data) || count($sellers_data) < 2): ?>
      <p class="text-center" style="color:#888;">
        <i class="fa fa-info-circle"></i>
        <?= isset($_no_seller_registered) ? $_no_seller_registered : 'At least two vendors required.' ?>
      </p>
    <?php else: ?>

    <!-- Stock par vendeur -->
    <div style="overflow-x:auto;margin-bottom:18px;">
    <table class="table table-bordered portal-table-min-sm" style="max-width:600px;font-size:13px;">
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
        foreach ($allSellerStock as $sk => $profiles):
            if (empty($profiles)) continue;
            $hasAny = true;
            $first = true;
            $rowspan = count($profiles);
            foreach ($profiles as $prof => $qty):
      ?>
        <tr>
          <?php if ($first): ?>
          <td rowspan="<?= $rowspan ?>" style="vertical-align:middle;font-weight:bold;">
            <?= htmlspecialchars($sellers_data[$sk]['name']) ?>
            <br><small class="portal-muted-light" style="color:#999;font-weight:normal;"><code><?= htmlspecialchars($sk) ?></code></small>
          </td>
          <?php $first = false; endif; ?>
          <td><?= htmlspecialchars($prof) ?></td>
          <td class="text-center"><b><?= $qty ?></b></td>
        </tr>
      <?php endforeach; endforeach; ?>
      <?php if (!$hasAny): ?>
        <tr><td colspan="3" class="text-center" style="color:#888;">
          <i class="fa fa-info-circle"></i> <?= isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets available.' ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Formulaire transfert admin -->
    <?php if ($hasAny): ?>
    <form method="post" action="?id=sellers&session=<?= htmlspecialchars($session) ?>" style="max-width:560px;" id="adminTransferForm">
      <?= csrf_field() ?>
      <input type="hidden" name="admin_transfer" value="1">
      <p style="color:#666;font-size:13px;margin-bottom:12px;">
        <i class="fa fa-info-circle"></i>
        <?= isset($_transfer_info) ? $_transfer_info : 'Select a profile, a quantity and the receiving vendor.' ?>
      </p>

      <div class="admin-transfer-grid">
        <!-- Vendeur source -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-user"></i> <?= isset($_transfer_from) ? $_transfer_from : 'From' ?></label>
          <select name="src_seller" class="form-control" id="srcSeller" onchange="updateAdminProfiles()" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($sellers_data as $sk => $sd): ?>
              <?php if (!empty($allSellerStock[$sk])): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Profil -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></label>
          <select name="transfer_profile" class="form-control" id="adminTransferProf" required>
            <option value=""><?= isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile' ?></option>
          </select>
        </div>

        <!-- Quantité -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Quantity' ?></label>
          <input type="number" name="transfer_qty" class="form-control" min="1" value="1" required>
        </div>

        <!-- Vendeur cible -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-arrow-right"></i> <?= isset($_transfer_to) ? $_transfer_to : 'Transfer to' ?></label>
          <select name="dst_seller" class="form-control" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($sellers_data as $sk => $sd): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" class="btn bg-primary" style="margin-top:6px;">
        <i class="fa fa-exchange"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?>
      </button>
    </form>

    <script>
    var adminStock = <?= json_encode($allSellerStock) ?>;
    function updateAdminProfiles() {
        var src  = document.getElementById('srcSeller').value;
        var sel  = document.getElementById('adminTransferProf');
        sel.innerHTML = '<option value=""><?= addslashes(isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile') ?></option>';
        if (src && adminStock[src]) {
            for (var prof in adminStock[src]) {
                sel.innerHTML += '<option value="' + prof + '">' + prof + ' (' + adminStock[src][prof] + ')</option>';
            }
        }
    }
    </script>
    <?php endif; // hasAny ?>

    <?php endif; // sellers count ?>
  </div>
</div>

<!-- ── Ajouter un vendeur ── -->
<div class="card box-bordered">
  <div class="card-header"><h4><i class="fa fa-user-plus"></i> <?= $_add_seller ?></h4></div>
  <div class="card-body">
    <div class="bg-light" style="padding:10px 14px;border-left:4px solid #27ae60;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_default_credentials) ? $_default_credentials : 'Default credentials' ?></b><br>
      <?= $_seller_id ?>: <code><?= htmlspecialchars($defaultSellerAccount['username']) ?></code> |
      <?= $_password ?>: <code><?= htmlspecialchars($defaultSellerAccount['password']) ?></code><br>
      <small><?= isset($_default_credentials_note) ? $_default_credentials_note : 'Admin can change these values before creation and update the password later.' ?></small>
    </div>
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <table class="table">
        <tr>
          <td class="align-middle"><?= $_seller_id ?> <small>(a-z, 0-9, _)</small></td>
          <td>
            <input class="form-control" type="text" name="new_user" id="seller-new-user"
                   pattern="[a-zA-Z0-9_]+" title="a-z, 0-9, _"
                   value="<?= htmlspecialchars($defaultSellerAccount['username']) ?>" required>
          </td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_password ?></td>
          <td>
            <input class="form-control" id="seller-new-pass" type="password" name="new_pass" value="<?= htmlspecialchars($defaultSellerAccount['password']) ?>" required>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" onclick="msTogglePassword('seller-new-pass', this)">
              <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
            </label>
          </td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_seller_display_name ?></td>
          <td><input class="form-control" type="text" name="new_name" id="seller-new-name" value="<?= htmlspecialchars(mikhmon_account_label($defaultSellerAccount['username'])) ?>" readonly></td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_seller_session_router ?></td>
          <td>
            <select class="form-control" name="new_session" required>
              <?php foreach ($available_sessions as $sn): ?>
                <option value="<?= htmlspecialchars($sn) ?>"><?= htmlspecialchars($sn) ?></option>
              <?php endforeach; ?>
              <?php if (empty($available_sessions)): ?>
                <option value=""><?= $_no_session_available ?></option>
              <?php endif; ?>
            </select>
          </td>
        </tr>
        <tr>
          <td colspan="2">
            <button type="submit" name="add_seller" class="btn bg-primary">
              <i class="fa fa-save"></i> <?= $_add_seller ?>
            </button>
          </td>
        </tr>
      </table>
    </form>
  </div>
</div>

</div><!-- /ms-section-sellers -->

<!-- ── Section Gérants ── -->
<div id="ms-section-managers" class="ms-tab-section<?= $active_tab==='managers' ? ' ms-active' : '' ?>">

<!-- ── Gestion des Gérants (admin uniquement) ── -->
<div class="card box-bordered" style="border-left:4px solid #8e44ad;">
  <div class="card-header" style="background:linear-gradient(90deg,#f3e8fd,#fff);">
    <h4 style="color:#8e44ad;"><i class="fa fa-briefcase"></i> <?= isset($_manage_managers) ? $_manage_managers : 'Manage Managers' ?></h4>
  </div>
  <div class="card-body">

    <?= $msg_mgr ?>

    <div style="background:#f8f1ff;color:#4a235a;padding:12px 14px;margin-bottom:15px;border-left:4px solid #8e44ad;border-radius:4px;">
      <b><i class="fa fa-info-circle"></i> Portail gérant</b><br>
      Le gérant supervise les vendeurs, voit les ventes, les commissions et le stock, puis gère les transferts de lots.
      Son accès est distinct du vendeur et son portail s’ouvre sur <code>manager.php</code>.
    </div>

    <!-- Liste des gérants -->
    <div class="card box-bordered" style="margin-bottom:15px;">
      <div class="card-header"><h5><i class="fa fa-list"></i> <?= isset($_registered_managers) ? $_registered_managers : 'Registered Managers' ?></h5></div>
      <div class="card-body">
        <?php if (empty($managers_data)): ?>
          <p class="text-center" style="color:#888;"><i class="fa fa-info-circle"></i> <?= isset($_no_manager_registered) ? $_no_manager_registered : 'No manager registered.' ?></p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered table-hover portal-table-min-lg">
          <thead class="thead-light">
            <tr>
              <th><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?></th>
              <th><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></th>
              <th><?= isset($_seller_session_router) ? $_seller_session_router : 'Session (router)' ?></th>
              <th><?= isset($_manager_portal) ? $_manager_portal : 'Manager Portal' ?></th>
              <th style="color:#e74c3c;"><b><?= isset($_action) ? $_action : 'Action' ?></b></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($managers_data as $mu => $md): ?>
            <tr>
              <td><code><?= htmlspecialchars($mu) ?></code></td>
              <td><?= htmlspecialchars($md['name']) ?></td>
              <td><span class="badge"><?= htmlspecialchars($md['session']) ?></span></td>
              <td>
                <a href="../manager.php?action=dashboard" target="_blank" style="font-size:12px;">
                  <i class="fa fa-external-link"></i> manager.php
                </a>
              </td>
              <td>
                <a href="#edit_manager_<?= htmlspecialchars($mu) ?>" class="btn bg-primary btn-sm" title="<?= isset($_edit) ? $_edit : 'Edit' ?>">
                  <i class="fa fa-edit"></i>
                </a>
                <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers" onsubmit="return confirm('<?= isset($_delete) ? addslashes($_delete) : 'Delete' ?> <?= htmlspecialchars($mu) ?> ?')" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="delete_manager" value="<?= htmlspecialchars($mu) ?>">
                  <button type="submit" class="btn bg-danger btn-sm" title="<?= isset($_delete) ? $_delete : 'Delete' ?>">
                    <i class="fa fa-trash"></i>
                  </button>
                </form>
                <a href="#chgpass_mgr_<?= htmlspecialchars($mu) ?>" class="btn bg-warning btn-sm" title="<?= $_password ?>">
                  <i class="fa fa-key"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <!-- Modals édition compte gérant -->
        <?php foreach ($managers_data as $mu => $md): ?>
        <div class="modal-window" id="edit_manager_<?= htmlspecialchars($mu) ?>" aria-hidden="true">
          <div>
            <header><h1><i class="fa fa-edit"></i> <?= isset($_edit) ? $_edit : 'Edit' ?> — <?= htmlspecialchars($mu) ?></h1></header>
            <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
            <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers">
              <?= csrf_field() ?>
              <table class="table">
                <tr>
                  <td><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?> <small>(a-z, 0-9, _)</small></td>
                  <td>
                    <input type="hidden" name="edit_manager_user" value="<?= htmlspecialchars($mu) ?>">
                    <input class="form-control" type="text" name="new_manager_user" value="<?= htmlspecialchars($mu) ?>" required>
                  </td>
                </tr>
                <tr>
                  <td><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></td>
                  <td><input class="form-control" type="text" name="new_manager_name" value="<?= htmlspecialchars(isset($md['name']) ? $md['name'] : '') ?>" required></td>
                </tr>
                <tr>
                  <td><?= $_password ?></td>
                  <td>
                    <input class="form-control" id="manager-edit-pass-<?= htmlspecialchars($mu) ?>" type="password" name="new_manager_pass" placeholder="<?= isset($_keep_password) ? $_keep_password : 'Laisser vide pour conserver le mot de passe actuel' ?>">
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                      <input type="checkbox" onclick="msTogglePassword('manager-edit-pass-<?= htmlspecialchars($mu) ?>', this)">
                      <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                    </label>
                  </td>
                </tr>
                <tr>
                  <td colspan="2">
                    <button type="submit" name="update_manager_account" class="btn bg-primary">
                      <i class="fa fa-save"></i> <?= $_save ?>
                    </button>
                  </td>
                </tr>
              </table>
            </form>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Modals changement mot de passe gérant -->
        <?php foreach ($managers_data as $mu => $md): ?>
        <div class="modal-window" id="chgpass_mgr_<?= htmlspecialchars($mu) ?>" aria-hidden="true">
          <div>
            <header><h1><i class="fa fa-key"></i> <?= $_password ?> — <?= htmlspecialchars($mu) ?></h1></header>
            <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
            <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers">
              <?= csrf_field() ?>
              <table class="table">
                <tr>
                  <td><?= $_password ?></td>
                  <td>
                    <input class="form-control" id="manager-pass-<?= htmlspecialchars($mu) ?>" type="password" name="cmp_pass" required>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                      <input type="checkbox" onclick="msTogglePassword('manager-pass-<?= htmlspecialchars($mu) ?>', this)">
                      <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                    </label>
                  </td>
                </tr>
                <tr>
                  <td colspan="2">
                    <input type="hidden" name="cmp_user" value="<?= htmlspecialchars($mu) ?>">
                    <button type="submit" name="change_manager_pass" class="btn bg-primary">
                      <i class="fa fa-save"></i> <?= $_save ?>
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

    <!-- Ajouter un gérant -->
    <div class="card box-bordered">
      <div class="card-header"><h5><i class="fa fa-user-plus"></i> <?= isset($_add_manager) ? $_add_manager : 'Add Manager' ?></h5></div>
      <div class="card-body">
        <div class="bg-light" style="padding:10px 14px;border-left:4px solid #8e44ad;border-radius:5px;margin-bottom:12px;">
          <b><?= isset($_default_credentials) ? $_default_credentials : 'Default credentials' ?></b><br>
          <?= isset($_seller_id) ? $_seller_id : 'Identifier' ?>: <code><?= htmlspecialchars($defaultManagerAccount['username']) ?></code> |
          <?= $_password ?>: <code><?= htmlspecialchars($defaultManagerAccount['password']) ?></code><br>
          <small><?= isset($_default_credentials_note) ? $_default_credentials_note : 'Admin can change these values before creation and update the password later.' ?></small>
        </div>
        <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td class="align-middle"><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?> <small>(a-z, 0-9, _)</small></td>
              <td><input class="form-control" type="text" name="nm_user" id="manager-new-user" pattern="[a-zA-Z0-9_]+" value="<?= htmlspecialchars($defaultManagerAccount['username']) ?>" required></td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?></td>
              <td>
                <input class="form-control" id="manager-new-pass" type="password" name="nm_pass" value="<?= htmlspecialchars($defaultManagerAccount['password']) ?>" required>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                  <input type="checkbox" onclick="msTogglePassword('manager-new-pass', this)">
                  <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                </label>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></td>
              <td><input class="form-control" type="text" name="nm_name" id="manager-new-name" value="<?= htmlspecialchars(mikhmon_account_label($defaultManagerAccount['username'])) ?>" readonly></td>
            </tr>
            <tr>
              <td class="align-middle"><?= isset($_seller_session_router) ? $_seller_session_router : 'Session' ?></td>
              <td>
                <select class="form-control" name="nm_session" required>
                  <?php foreach ($available_sessions as $sn): ?>
                    <option value="<?= htmlspecialchars($sn) ?>"><?= htmlspecialchars($sn) ?></option>
                  <?php endforeach; ?>
                  <?php if (empty($available_sessions)): ?>
                    <option value=""><?= isset($_no_session_available) ? $_no_session_available : 'No session' ?></option>
                  <?php endif; ?>
                </select>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <button type="submit" name="add_manager" class="btn" style="background:#8e44ad;color:#fff;">
                  <i class="fa fa-save"></i> <?= isset($_add_manager) ? $_add_manager : 'Add Manager' ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>

  </div><!-- card-body gérants -->
</div><!-- card gérants -->

</div><!-- /ms-section-managers -->

<!-- ── Section Comptabilité ── -->
<div id="ms-section-accounting" class="ms-tab-section<?= $active_tab==='accounting' ? ' ms-active' : '' ?>">

<?php
  $adminAcctTotal = $adminAccountingSummary['total'];
  $adminAcctSellerLabel = $adminAccountingSeller !== '' && isset($adminAccountingSellersData[$adminAccountingSeller])
    ? $adminAccountingSellersData[$adminAccountingSeller]['name']
    : 'Tous les vendeurs';
  $adminAccountingYear = substr($adminAccountingMonthKey, 3, 4);
?>

<div class="card box-bordered" style="margin-bottom:15px;">
  <div class="card-header">
    <h4 style="margin:0;"><i class="fa fa-calculator"></i> Comptabilité par vendeur</h4>
  </div>
  <div class="card-body">
    <div class="portal-note-card" style="margin-bottom:16px;text-align:left;">
      <b><i class="fa fa-stop-circle"></i> Arrêt de période</b><br>
      Sélectionnez une période, arrêtez les comptes avec chaque vendeur, puis repartez du lendemain pour ne pas mélanger les montants déjà réglés.
    </div>

    <div style="margin-bottom:16px;">
      <div class="mgr-month-filter">
        <?php foreach (mikhmon_month_map() as $monthNumber => $monthSlug):
          $monthTag = $monthSlug . $adminAccountingYear;
          $monthActive = ($adminAccountingMonthKey === $monthTag) ? 'bg-primary' : '';
        ?>
          <a href="./admin.php?id=sellers&session=<?= urlencode($session) ?>&tab=accounting&acct_month=<?= urlencode($monthTag) ?>"
             class="btn btn-sm <?= $monthActive ?>" style="padding:4px 10px;">
            <?= ucfirst($monthSlug) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="get" action="./admin.php" class="portal-card-section" style="margin-bottom:18px;">
      <input type="hidden" name="id" value="sellers">
      <input type="hidden" name="session" value="<?= htmlspecialchars($session) ?>">
      <input type="hidden" name="tab" value="accounting">
      <input type="hidden" name="acct_month" value="<?= htmlspecialchars($adminAccountingMonthKey) ?>">
      <div class="portal-filter-grid">
        <div class="portal-filter-item">
          <label class="transfer-label"><i class="fa fa-calendar-o"></i> Début</label>
          <input type="date" name="acct_from" class="form-control" value="<?= htmlspecialchars($adminAccountingFrom) ?>">
        </div>
        <div class="portal-filter-item">
          <label class="transfer-label"><i class="fa fa-calendar-check-o"></i> Arrêt</label>
          <input type="date" name="acct_to" class="form-control" value="<?= htmlspecialchars($adminAccountingTo) ?>">
        </div>
        <div class="portal-filter-item">
          <label class="transfer-label"><i class="fa fa-user"></i> Vendeur</label>
          <select name="acct_seller" class="form-control">
            <option value="">Tous les vendeurs</option>
            <?php foreach ($adminAccountingSellersData as $sk => $sd): ?>
              <option value="<?= htmlspecialchars($sk) ?>" <?= $adminAccountingSeller === $sk ? 'selected' : '' ?>>
                <?= htmlspecialchars($sd['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="portal-filter-item">
          <label class="transfer-label"><i class="fa fa-clock-o"></i> Heure du compte</label>
          <input type="time" name="acct_settled_at" class="form-control" step="60" value="<?= htmlspecialchars(substr($adminAccountingSettlementTime, 0, 5)) ?>">
        </div>
        <div class="portal-filter-item">
          <label class="transfer-label"><i class="fa fa-clock-o"></i> Heure du prochain compte</label>
          <input type="time" name="acct_next_settled_at" class="form-control" step="60" value="<?= htmlspecialchars(substr($adminAccountingNextSettlementTime, 0, 5)) ?>">
        </div>
      </div>
      <button type="submit" class="btn bg-primary" style="margin-top:8px;">
        <i class="fa fa-filter"></i> Afficher les comptes
      </button>
      <a class="btn" style="margin-top:8px;background:#eee;color:#333;" href="<?= $adminAccountingBaseUrl ?>">
        <i class="fa fa-refresh"></i> Mois complet
      </a>
    </form>

    <?php if (!$API_ms_connected): ?>
      <div class="bg-warning" style="padding:10px 14px;border-radius:5px;margin-bottom:14px;">
        <i class="fa fa-warning"></i> Impossible de lire les ventes du routeur pour cette session. Vérifiez la connexion MikroTik.
      </div>
    <?php endif; ?>

    <div class="admin-accounting-cards">
      <div class="admin-accounting-card" style="background:#eaf4fb;border-left:4px solid #2980b9;">
        <div class="admin-accounting-card-label" style="color:#2980b9;"><i class="fa fa-calendar"></i> Période arrêtée</div>
        <div class="admin-accounting-card-value" style="font-size:18px;color:#2980b9;"><?= htmlspecialchars($adminAccountingFrom) ?> &rarr; <?= htmlspecialchars($adminAccountingTo) ?></div>
        <div style="font-size:12px;color:#1a6fa0;"><?= htmlspecialchars($adminAcctSellerLabel) ?></div>
      </div>
      <div class="admin-accounting-card" style="background:#f3e8fd;border-left:4px solid #8e44ad;">
        <div class="admin-accounting-card-label" style="color:#8e44ad;"><i class="fa fa-ticket"></i> Tickets</div>
        <div class="admin-accounting-card-value" style="color:#8e44ad;"><?= (int)$adminAcctTotal['count'] ?></div>
      </div>
      <div class="admin-accounting-card" style="background:#e8f8f5;border-left:4px solid #27ae60;">
        <div class="admin-accounting-card-label" style="color:#27ae60;"><i class="fa fa-money"></i> Total encaissé</div>
        <div class="admin-accounting-card-value" style="color:#27ae60;"><?= mikhmon_format_money_amount($adminAcctTotal['revenue'], $currency, $cekindo) ?></div>
      </div>
      <div class="admin-accounting-card" style="background:#fff8e1;border-left:4px solid #e67e22;">
        <div class="admin-accounting-card-label" style="color:#e67e22;"><i class="fa fa-percent"></i> Commissions</div>
        <div class="admin-accounting-card-value" style="color:#e67e22;"><?= mikhmon_format_money_amount($adminAcctTotal['commission'], $currency, $cekindo) ?></div>
      </div>
      <div class="admin-accounting-card" style="background:#fdeef7;border-left:4px solid #c0398f;">
        <div class="admin-accounting-card-label" style="color:#c0398f;"><i class="fa fa-bank"></i> Net à remettre</div>
        <div class="admin-accounting-card-value" style="color:#c0398f;"><?= mikhmon_format_money_amount($adminAcctTotal['net'], $currency, $cekindo) ?></div>
      </div>
      <div class="admin-accounting-card" style="background:#eef2f7;border-left:4px solid #34495e;">
        <div class="admin-accounting-card-label" style="color:#34495e;"><i class="fa fa-clock-o"></i> Heure du compte</div>
        <div class="admin-accounting-card-value" style="color:#34495e;"><?= htmlspecialchars($adminAccountingSettlementTime) ?></div>
      </div>
    </div>

    <?php if ($adminAccountingNextFrom !== ''): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
        <a class="btn" style="background:#5b2c8d;color:#fff;" href="<?= $adminAccountingBaseUrl ?>&acct_from=<?= urlencode($adminAccountingNextFrom) ?>&acct_to=<?= urlencode($adminAccountingNextFrom) ?>&acct_settled_at=<?= urlencode($adminAccountingNextSettlementTime) ?>&acct_next_settled_at=<?= urlencode($adminAccountingNextSettlementTime) ?><?= $adminAccountingSeller !== '' ? '&acct_seller=' . urlencode($adminAccountingSeller) : '' ?>">
          <i class="fa fa-step-forward"></i> Jour suivant : <?= htmlspecialchars($adminAccountingNextFrom) ?>
        </a>
        <a class="btn" style="background:#34495e;color:#fff;" href="<?= $adminAccountingBaseUrl ?>&acct_from=<?= urlencode($adminAccountingNextFrom) ?>&acct_to=<?= urlencode($adminAccountingNextTo) ?>&acct_settled_at=<?= urlencode($adminAccountingNextSettlementTime) ?>&acct_next_settled_at=<?= urlencode($adminAccountingNextSettlementTime) ?><?= $adminAccountingSeller !== '' ? '&acct_seller=' . urlencode($adminAccountingSeller) : '' ?>">
          <i class="fa fa-calendar-plus-o"></i> Reste du mois
        </a>
      </div>
    <?php endif; ?>

    <?php
      $adminAccountingNoticeSampleName = 'vendeur';
      if (!empty($adminAccountingNoticeTargets) && isset($adminAccountingSellersData[$adminAccountingNoticeTargets[0]]['name'])) {
        $adminAccountingNoticeSampleName = $adminAccountingSellersData[$adminAccountingNoticeTargets[0]]['name'];
      } elseif ($adminAccountingSeller !== '' && isset($adminAccountingSellersData[$adminAccountingSeller]['name'])) {
        $adminAccountingNoticeSampleName = $adminAccountingSellersData[$adminAccountingSeller]['name'];
      }
      $adminAccountingNoticePreview = mikhmon_accounting_notification_text($adminAccountingNoticeSampleName, $adminAccountingFrom, $adminAccountingTo, $adminAccountingSettlementTime, $adminAccountingNextFrom, $adminAccountingNextTo, $adminAccountingNextSettlementTime);
    ?>
    <div class="accounting-notice-box">
      <b><i class="fa fa-bell"></i> Notification aux vendeurs</b>
      <div style="font-size:12px;color:#64748b;margin-top:4px;">
        Cibles : <?= count($adminAccountingNoticeTargets) ?> vendeur(s) concerné(s) par cette période.
      </div>
      <?php if ($adminAccountingNoticeMsg !== ''): ?>
        <div class="bg-success" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-check"></i> <?= htmlspecialchars($adminAccountingNoticeMsg) ?></div>
      <?php endif; ?>
      <?php if ($adminAccountingNoticeError !== ''): ?>
        <div class="bg-warning" style="padding:8px 10px;border-radius:5px;margin-top:10px;"><i class="fa fa-warning"></i> <?= htmlspecialchars($adminAccountingNoticeError) ?></div>
      <?php endif; ?>
      <div class="accounting-notice-preview"><?= htmlspecialchars($adminAccountingNoticePreview) ?></div>
      <form method="post" action="<?= $adminAccountingBaseUrl ?>&acct_from=<?= urlencode($adminAccountingFrom) ?>&acct_to=<?= urlencode($adminAccountingTo) ?>&acct_settled_at=<?= urlencode($adminAccountingSettlementTime) ?>&acct_next_settled_at=<?= urlencode($adminAccountingNextSettlementTime) ?><?= $adminAccountingSeller !== '' ? '&acct_seller=' . urlencode($adminAccountingSeller) : '' ?>" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="acct_settled_at" value="<?= htmlspecialchars($adminAccountingSettlementTime) ?>">
        <input type="hidden" name="acct_next_settled_at" value="<?= htmlspecialchars($adminAccountingNextSettlementTime) ?>">
        <button type="submit" name="admin_send_accounting_notice" class="btn" style="background:#34495e;color:#fff;">
          <i class="fa fa-paper-plane"></i> Notifier les vendeurs
        </button>
      </form>
    </div>

    <?php
      /* ── Historique des notifications comptables ── */
      $acctNotifHistory = mikhmon_accounting_notifications_load();
      // Filter for current session
      $acctNotifHistory = array_values(array_filter($acctNotifHistory, function($n) use ($session) {
          return ($n['session'] ?? '') === $session;
      }));
      // Most recent first
      usort($acctNotifHistory, function($a, $b) {
          return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
      });
    ?>
    <?php if (!empty($acctNotifHistory)): ?>
    <div class="card box-bordered" id="acct-notif-history-card" style="margin-bottom:18px;border-left:4px solid #8e44ad;">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:#faf5ff;">
        <h4 style="margin:0;color:#6c3483;"><i class="fa fa-history"></i> Historique des comptes notifiés <small style="color:#999;">(<?= count($acctNotifHistory) ?>)</small></h4>
        <button class="btn btn-sm bg-danger" id="acct-notif-clear-btn"
                onclick="clearAllNotifications(this, <?= json_encode($session) ?>)"
                title="Supprimer tout l'historique pour cette session">
          <i class="fa fa-trash"></i> Tout effacer
        </button>
      </div>
      <div class="card-body" style="padding:0;">
        <div class="table-responsive">
        <table class="table table-bordered" style="margin:0;font-size:13px;">
          <thead class="thead-light">
            <tr>
              <th>Vendeur</th>
              <th>Période</th>
              <th>Message</th>
              <th class="text-center">Date envoi</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody id="acct-notif-tbody">
          <?php foreach ($acctNotifHistory as $notif): ?>
            <tr id="acct-notif-row-<?= htmlspecialchars($notif['id'] ?? '') ?>">
              <td><b><?= htmlspecialchars($notif['seller_name'] ?? $notif['seller'] ?? '—') ?></b><br><small style="color:#888;"><code><?= htmlspecialchars($notif['seller'] ?? '') ?></code></small></td>
              <td style="white-space:nowrap;">
                <span style="display:inline-block;padding:2px 7px;border-radius:10px;background:#ede9fe;color:#6c3483;font-size:12px;">
                  <?= htmlspecialchars($notif['from'] ?? '') ?> → <?= htmlspecialchars($notif['to'] ?? '') ?>
                </span>
              </td>
              <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($notif['message'] ?? '') ?>">
                <?= htmlspecialchars(mb_substr($notif['message'] ?? '', 0, 80)) ?>…
              </td>
              <td class="text-center" style="white-space:nowrap;color:#888;font-size:12px;"><?= htmlspecialchars(substr($notif['created_at'] ?? '', 0, 16)) ?></td>
              <td class="text-center">
                <button class="btn btn-sm bg-danger"
                        onclick="deleteNotification(<?= json_encode($notif['id'] ?? '') ?>, this)"
                        title="Supprimer cette notification de l'historique">
                  <i class="fa fa-times"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($adminAccountingSummary['days'])): ?>
      <p class="text-center portal-empty-note" style="padding:20px;margin:0;">
        <i class="fa fa-info-circle"></i> Aucune période valide.
      </p>
    <?php else: ?>
      <?php foreach ($adminAccountingSummary['days'] as $dayKey => $day): ?>
      <div class="card box-bordered" style="margin-bottom:14px;border-left:4px solid <?= $day['total']['count'] > 0 ? '#27ae60' : '#cbd5e1' ?>;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:#fafafa;">
          <h4 style="margin:0;color:#243447;">
            <i class="fa fa-calendar"></i> <?= htmlspecialchars($day['iso']) ?>
            <small style="color:#888;">(<?= htmlspecialchars($dayKey) ?>)</small>
          </h4>
          <div style="font-size:13px;color:#555;">
            <b><?= (int)$day['total']['count'] ?></b> vcr &middot;
            <b><?= mikhmon_format_money_amount($day['total']['revenue'], $currency, $cekindo) ?></b> &middot;
            Commission <?= mikhmon_format_money_amount($day['total']['commission'], $currency, $cekindo) ?> &middot;
            Net <?= mikhmon_format_money_amount($day['total']['net'], $currency, $cekindo) ?> &middot;
            <span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($adminAccountingSettlementTime) ?></span>
          </div>
          <?php if ((int)$day['total']['count'] > 0): ?>
          <button class="btn btn-sm bg-danger acct-del-day-btn"
                  onclick="deleteDaySales(<?= json_encode($day['iso']) ?>, <?= json_encode($adminAccountingMonthKey ?? '') ?>, this)"
                  title="Supprimer toutes les ventes de ce jour sur MikroTik"
                  style="white-space:nowrap;">
            <i class="fa fa-trash"></i> Supprimer les ventes
          </button>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (empty($day['sellers'])): ?>
            <p class="text-center portal-empty-note" style="margin:0;padding:8px;">
              <i class="fa fa-info-circle"></i> Aucun compte à régler pour cette journée.
              <span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> Heure du compte : <?= htmlspecialchars($adminAccountingSettlementTime) ?></span>
            </p>
          <?php else: ?>
          <div class="table-responsive portal-table-wrap">
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
                  <small style="color:#888;"><code><?= htmlspecialchars($sellerRow['key']) ?></code> &middot; <?= (int)$sellerRow['commission_rate'] ?>%</small>
                  <span class="accounting-settlement-chip accounting-mobile-settlement"><i class="fa fa-clock-o"></i> Heure du compte : <?= htmlspecialchars($adminAccountingSettlementTime) ?></span>
                </td>
                <td class="text-center accounting-time-cell" data-label="Heure du compte"><span class="accounting-settlement-chip"><i class="fa fa-clock-o"></i> <?= htmlspecialchars($adminAccountingSettlementTime) ?></span></td>
                <td data-label="Profils vendus">
                  <?php foreach ($sellerRow['profiles'] as $profileName => $profileTotal): ?>
                    <span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:12px;background:#eef2f7;color:#243447;font-size:12px;">
                      <?= htmlspecialchars($profileName) ?>: <?= (int)$profileTotal['count'] ?>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td class="text-center" data-label="Tickets"><?= (int)$sellerRow['count'] ?></td>
                <td class="text-center" data-label="Total encaissé"><?= mikhmon_format_money_amount($sellerRow['revenue'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Commission" style="color:#e67e22;font-weight:bold;"><?= mikhmon_format_money_amount($sellerRow['commission'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Net à remettre" style="color:#c0398f;font-weight:bold;"><?= mikhmon_format_money_amount($sellerRow['net'], $currency, $cekindo) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="acct-total-row">
                <td colspan="3" data-label="Arrêt"><i class="fa fa-stop-circle"></i> Arrêt du jour &middot; <?= htmlspecialchars($adminAccountingSettlementTime) ?></td>
                <td class="text-center" data-label="Tickets"><?= (int)$day['total']['count'] ?></td>
                <td class="text-center" data-label="Total encaissé"><?= mikhmon_format_money_amount($day['total']['revenue'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Commission"><?= mikhmon_format_money_amount($day['total']['commission'], $currency, $cekindo) ?></td>
                <td class="text-center" data-label="Net à remettre"><?= mikhmon_format_money_amount($day['total']['net'], $currency, $cekindo) ?></td>
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

</div><!-- /ms-section-accounting -->

<!-- ── Section Transferts ── -->
<div id="ms-section-transfers" class="ms-tab-section<?= $active_tab==='transfers' ? ' ms-active' : '' ?>">

<!-- ── Journal des transferts récents (admin) ── -->
<?php $recentLogs = get_transfer_logs(10); ?>
<div class="card box-bordered" style="margin-top:15px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h4 style="margin:0;"><i class="fa fa-history"></i> <?= isset($_transfer_logs) ? $_transfer_logs : 'Recent Transfers' ?> <small>(<?= count($recentLogs) ?>)</small></h4>
    <?php if (!empty($recentLogs)): ?>
      <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=transfers" onsubmit="return confirm('<?= isset($_transfer_log_clear_confirm) ? addslashes($_transfer_log_clear_confirm) : 'Vider tout le journal des transferts ?' ?>');" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" name="clear_transfer_logs" class="btn bg-danger btn-sm">
          <i class="fa fa-trash"></i> <?= isset($_transfer_log_clear) ? $_transfer_log_clear : 'Vider le journal' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?= $transfer_log_msg ?>
    <?php if ($transfer_log_error): ?>
      <div class="bg-danger" style="padding:8px;border-radius:5px;margin-bottom:10px;"><i class="fa fa-ban"></i> <?= htmlspecialchars($transfer_log_error) ?></div>
    <?php endif; ?>
    <?php if (empty($recentLogs)): ?>
      <p class="text-center portal-empty-note" style="padding:20px;margin:0;">
        <i class="fa fa-info-circle"></i> <?= isset($_transfer_log_empty) ? $_transfer_log_empty : 'No transfers recorded yet.' ?>
      </p>
    <?php else: ?>
    <div class="table-responsive portal-table-wrap">
    <table class="table table-bordered portal-table-min-md" style="font-size:12px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_date) ? $_date : 'Date' ?></th>
          <th><?= isset($_transfer_from_col) ? $_transfer_from_col : 'From' ?></th>
          <th>→</th>
          <th><?= isset($_transfer_to) ? $_transfer_to : 'To' ?></th>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center"><?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></th>
          <th><?= isset($_transfer_by) ? $_transfer_by : 'By' ?></th>
          <th class="text-center"><?= isset($_action) ? $_action : 'Action' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td style="white-space:nowrap;font-size:11px;"><?= htmlspecialchars($log['ts']) ?></td>
          <td><b><?= htmlspecialchars($log['from']) ?></b></td>
          <td class="portal-empty-note" style="color:#aaa;">→</td>
          <td><b><?= htmlspecialchars($log['to']) ?></b></td>
          <td><?= htmlspecialchars($log['profile']) ?></td>
          <td class="text-center"><b><?= (int)$log['qty'] ?></b></td>
          <td>
            <span style="font-size:10px;padding:1px 6px;border-radius:8px;font-weight:bold;color:#fff;background:<?= $log['by_role']==='admin' ? '#007bff' : ($log['by_role']==='manager' ? '#8e44ad' : '#27ae60') ?>;">
              <?= htmlspecialchars($log['by_role']) ?>
            </span>
          </td>
          <td class="text-center">
            <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=transfers" onsubmit="return confirm('<?= isset($_transfer_log_delete_confirm) ? addslashes($_transfer_log_delete_confirm) : 'Supprimer cette entrée du journal ?' ?>');" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="log_row" value="<?= isset($log['_row']) ? (int)$log['_row'] : -1 ?>">
              <button type="submit" name="delete_transfer_log" class="btn bg-danger btn-sm" title="<?= isset($_transfer_log_delete_one) ? $_transfer_log_delete_one : 'Supprimer cette entrée' ?>">
                <i class="fa fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

</div><!-- /ms-section-transfers -->

<!-- Confirmation modal admin transfer -->
<div id="adminConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;justify-content:center;align-items:center;padding:16px;">
  <div style="background:#fff;border-radius:10px;padding:28px 24px;max-width:380px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;">
    <h3 style="margin:0 0 8px;font-size:17px;"><i class="fa fa-exchange" style="color:#007bff;"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?></h3>
    <p id="adminConfirmBody" style="color:#555;margin-bottom:20px;font-size:15px;line-height:1.5;"></p>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button id="adminConfirmCancel" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#eee;color:#555;">
        <i class="fa fa-times"></i> <?= isset($_cancel) ? $_cancel : 'Cancel' ?>
      </button>
      <button id="adminConfirmOk" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#007bff;color:#fff;">
        <i class="fa fa-check"></i> <?= isset($_confirm) ? $_confirm : 'Confirm' ?>
      </button>
    </div>
  </div>
</div>
<script>
(function(){
  var form  = document.getElementById('adminTransferForm');
  var modal = document.getElementById('adminConfirmModal');
  if (!form || !modal) return;
  var pending = null;
  form.addEventListener('submit', function(e){
    var src   = document.getElementById('srcSeller');
    var prof  = document.getElementById('adminTransferProf');
    var qty   = form.querySelector('[name="transfer_qty"]');
    var dst   = form.querySelector('[name="dst_seller"]');
    if (!src?.value || !prof?.value || !dst?.value) return;
    e.preventDefault();
    var srcName = src.options[src.selectedIndex].text;
    var dstName = dst.options[dst.selectedIndex].text;
    document.getElementById('adminConfirmBody').innerHTML =
      '<b>'+qty.value+'</b> ticket(s) ['+prof.value+']<br>'+srcName+' → <b>'+dstName+'</b>';
    modal.style.display = 'flex';
    pending = form;
  });
  document.getElementById('adminConfirmOk').addEventListener('click', function(){
    modal.style.display='none'; if(pending) pending.submit();
  });
  document.getElementById('adminConfirmCancel').addEventListener('click', function(){
    modal.style.display='none'; pending=null;
  });
  modal.addEventListener('click', function(e){ if(e.target===modal){modal.style.display='none';pending=null;}});
})();

/* ── Distribution globale ── */
var distGlobalStock = <?= json_encode($globalStock) ?>;
var distCurrentMax  = 0;

function distSelectProfile(prof, total) {
  document.getElementById('distProfileInput').value = prof;
  document.getElementById('distCurrentProf').textContent = prof;
  document.getElementById('distAvail').textContent = total;
  document.getElementById('distSaisieMax').textContent = total;
  document.getElementById('distGrandMax').textContent = total;
  distCurrentMax = total;

  // Afficher le formulaire
  document.getElementById('bulkDistForm').style.display = '';

  // Reset inputs
  document.querySelectorAll('.dist-qty-input').forEach(function(i){ i.value = 0; });
  document.querySelectorAll('[id^="distalloc-"]').forEach(function(el){ el.textContent = '—'; el.style.color='#aaa'; });
  document.getElementById('distGrandTotal').textContent = '0';
  document.getElementById('distSaisie').textContent = '0';
  document.getElementById('distSubmitBtn').disabled = true;

  // Surbrillance du bouton profil actif
  document.querySelectorAll('.dist-prof-btn').forEach(function(b){
    b.style.background = '#f8f9fa'; b.style.color = '#333';
  });
  var safeProf = prof.replace(/[^a-z0-9_]/gi, '-');
  var activeBtn = document.getElementById('distprof-' + safeProf);
  if (activeBtn) { activeBtn.style.background = '#e67e22'; activeBtn.style.color = '#fff'; }
}

function distUpdateTotal() {
  var total = 0;
  document.querySelectorAll('.dist-qty-input').forEach(function(inp) {
    var v = parseInt(inp.value || 0, 10);
    if (isNaN(v) || v < 0) { inp.value = 0; v = 0; }
    total += v;
    var key = inp.getAttribute('data-key');
    var allocEl = document.getElementById('distalloc-' + key);
    if (allocEl) {
      allocEl.textContent = v > 0 ? v : '—';
      allocEl.style.color = v > 0 ? '#27ae60' : '#aaa';
    }
  });
  var gtEl = document.getElementById('distGrandTotal');
  gtEl.textContent = total;
  gtEl.style.color = total > distCurrentMax ? '#dc3545' : '#e67e22';
  document.getElementById('distSaisie').textContent = total;
  document.getElementById('distSaisie').style.color = total > distCurrentMax ? '#dc3545' : '#333';
  document.getElementById('distSubmitBtn').disabled = (total <= 0 || total > distCurrentMax);
}

function distMax(key) {
  // Remplir le max restant disponible dans ce champ
  var usedByOthers = 0;
  document.querySelectorAll('.dist-qty-input').forEach(function(inp) {
    if (inp.getAttribute('data-key') !== key) usedByOthers += parseInt(inp.value || 0, 10);
  });
  var remaining = Math.max(0, distCurrentMax - usedByOthers);
  var el = document.getElementById('distqty-' + key);
  if (el) { el.value = remaining; distUpdateTotal(); }
}

function distReset(key) {
  if (key === '__all__') {
    document.querySelectorAll('.dist-qty-input').forEach(function(i){ i.value = 0; });
    distUpdateTotal();
  } else {
    var el = document.getElementById('distqty-' + key);
    if (el) { el.value = 0; distUpdateTotal(); }
  }
}

/* ── Tab switcher ── */
function msTogglePassword(fieldId, checkbox) {
  var input = document.getElementById(fieldId);
  if (!input) return;
  input.type = checkbox && checkbox.checked ? 'text' : 'password';
}

function msAccountLabel(value) {
  value = (value || '').replace(/[^a-zA-Z0-9_]/g, '');
  if (!value) return '';
  return value.charAt(0).toUpperCase() + value.slice(1).toLowerCase();
}

function msBindAccountAutoName(userId, nameId) {
  var user = document.getElementById(userId);
  var name = document.getElementById(nameId);
  if (!user || !name) return;

  function sync() {
    var label = msAccountLabel(user.value);
    name.value = label;
  }

  user.addEventListener('input', sync);
  user.addEventListener('blur', sync);
  sync();
}

msBindAccountAutoName('seller-new-user', 'seller-new-name');
msBindAccountAutoName('manager-new-user', 'manager-new-name');

function msTab(tab) {
  var url = new URL(window.location.href);
  url.searchParams.set('tab', tab);
  if (tab === 'accounting' && url.toString() !== window.location.href) {
    window.location.href = url.toString();
    return;
  }

  var sections = ['sellers','managers','accounting','transfers'];
  sections.forEach(function(t){
    var sec = document.getElementById('ms-section-'+t);
    var btn = document.getElementById('mstab-'+t);
    if (!sec || !btn) return;
    if (t === tab) {
      sec.classList.add('ms-active');
      btn.classList.add('ms-active');
    } else {
      sec.classList.remove('ms-active');
      btn.classList.remove('ms-active');
    }
  });
  // Update URL without reload
  history.replaceState(null, '', url.toString());
}

/* ══════════════════════════════════════════════
   Comptabilité — actions AJAX
   ══════════════════════════════════════════════ */

function _acctPost(data, btn, onOk) {
  var orig = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>'; }
  var fd = new FormData();
  Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
  fetch('./process/accounting_action.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res.ok) {
        onOk(res);
      } else {
        alert('Erreur : ' + (res.error || 'inconnue'));
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
      }
    })
    .catch(function(e){
      alert('Erreur réseau : ' + e);
      if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    });
}

/* Supprimer une notification de l'historique */
function deleteNotification(notifId, btn) {
  if (!confirm('Supprimer cette notification de l\'historique ?')) return;
  _acctPost({ action: 'delete_notification', notif_id: notifId }, btn, function(res) {
    var row = document.getElementById('acct-notif-row-' + notifId);
    if (row) row.remove();
    // Si le tableau est vide, masquer la carte
    var tbody = document.getElementById('acct-notif-tbody');
    if (tbody && tbody.querySelectorAll('tr').length === 0) {
      var card = document.getElementById('acct-notif-history-card');
      if (card) card.style.display = 'none';
    }
  });
}

/* Effacer tout l'historique de notifications pour la session */
function clearAllNotifications(btn, sessionKey) {
  if (!confirm('Effacer tout l\'historique des comptes notifiés pour cette session ?')) return;
  _acctPost({ action: 'clear_notifications', session: sessionKey }, btn, function(res) {
    var card = document.getElementById('acct-notif-history-card');
    if (card) card.style.display = 'none';
  });
}

/* Supprimer les ventes d'un jour depuis MikroTik */
function deleteDaySales(isoDate, monthKey, btn) {
  if (!confirm('Supprimer TOUTES les ventes du ' + isoDate + ' sur MikroTik ?\n\nCette action est irréversible.')) return;
  _acctPost({ action: 'delete_day_sales', iso_date: isoDate, month_key: monthKey }, btn, function(res) {
    var msg = 'Supprimé : ' + res.deleted + ' script(s)';
    if (res.errors && res.errors.length > 0) msg += '\nErreurs : ' + res.errors.join(', ');
    alert(msg);
    // Griser la carte du jour pour indiquer qu'elle est vide
    if (btn) {
      var card = btn.closest('.card');
      if (card) {
        card.style.opacity = '0.45';
        card.style.borderLeftColor = '#cbd5e1';
      }
      btn.disabled = true;
      btn.innerHTML = '<i class="fa fa-check"></i> Supprimé';
    }
  });
}
</script>

</div>
</div>
</div>
</div>
