<?php
/*
 * settings/fraud.php — Anti-fraude v3 — UI premium / zéro emoji / FA icons
 * Fix : instanciation RouterosAPI si absente + push-webhook fallback
 */
if (empty($_SESSION['mikhmon'])) {
    header('Location: ./admin.php?id=login');
    exit;
}
require_once __DIR__ . '/../include/anti_fraud.php';
require_once __DIR__ . '/../include/oui_lookup.php';
require_once __DIR__ . '/../include/device_monitor.php';
require_once __DIR__ . '/../include/ap_monitor.php';

/* ── Clé API + URL webhook ──────────────────────────────────────────────── */
$fraudApiKey = anti_fraud_get_api_key();
$mikhmonBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF']), '/\\');
if ($mikhmonBasePath === '/' || $mikhmonBasePath === '.') $mikhmonBasePath = '';
$webhookInfo = device_monitor_build_webhook_url($mikhmonBasePath, $iphost ?? '', $dnsname ?? '');
$webhookUrl  = $webhookInfo['url'];
$webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
$webhookIsLoopback = in_array($webhookHost, array('localhost', '127.0.0.1', '::1'), true);
$webhookRecommendedUrl = $webhookInfo['recommended_url'] ?? $webhookUrl;
$webhookRecommendedHost = $webhookInfo['recommended_host'] ?? $webhookHost;
$webhookBrowserUrl = $webhookInfo['browser_recommended_url'] ?? '';
if ($webhookBrowserUrl !== '' && !empty($session)) {
    $webhookBrowserUrl = preg_replace('/(&session=).*$/', '', $webhookBrowserUrl) . '&session=' . rawurlencode($session);
}

/* ── Connexion MikroTik (fix : créer $API si absent) ────────────────────── */
$apiOk     = false;
$apiError  = '';
$apMonitor = array('items' => array(), 'stats' => array('total' => 0, 'conflicts' => 0, 'static_ok' => 0), 'error' => '');
if (!isset($API)) {
    $API = new RouterosAPI();
    $API->debug = false;
}
if (!empty($iphost) && !empty($userhost) && !empty($passwdhost)) {
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
            try { anti_fraud_scan($API); } catch (Exception $e) {}
            try { $apMonitor = ap_monitor_scan($API); } catch (Exception $e) {}
            $apiOk = true;
        } else {
            $apiError = 'Identifiants refusés par MikroTik';
        }
    } catch (Exception $e) {
        $apiError = $e->getMessage();
    }
} else {
    $apiError = 'Paramètres de session incomplets';
}

/* ── Scripts déployés ? (si API connectée) ──────────────────────────────── */
$scriptDeployed       = false;
$schedulerDeployed    = false;
$rogueDhcpDeployed    = false;
$rogueDhcpAlertInterface = 'bridge';
$rogueDhcpValidMac = '';
$antiFrScriptDeployed = false;
$antiFrSchedDeployed  = false;
if ($apiOk) {
    try {
        $sc = $API->comm('/system/script/print', array('?name' => 'MIKHMON-DeviceMonitor'));
        $scriptDeployed = is_array($sc) && !empty($sc);
        $sch = $API->comm('/system/scheduler/print', array('?name' => 'MIKHMON-DeviceMonitor-Task'));
        $schedulerDeployed = is_array($sch) && !empty($sch);
        $rg = $API->comm('/ip/dhcp-server/alert/print', array('?comment' => 'MIKHMON-RogueDHCP'));
        $rogueDhcpDeployed = is_array($rg) && !empty($rg);
        if (!empty($rg[0])) {
            $rogueDhcpAlertInterface = trim((string)($rg[0]['interface'] ?? $rogueDhcpAlertInterface));
            $rogueDhcpValidMac = trim((string)($rg[0]['valid-server'] ?? ''));
        }
        $afs = $API->comm('/system/script/print', array('?name' => 'MIKHMON-AntiFraud'));
        $antiFrScriptDeployed = is_array($afs) && !empty($afs);
        $afsc = $API->comm('/system/scheduler/print', array('?name' => 'MIKHMON-AntiFraud-Task'));
        $antiFrSchedDeployed = is_array($afsc) && !empty($afsc);
    } catch (Exception $e) {}

    try {
        $bridges = $API->comm('/interface/bridge/print');
        $bridgePick = function_exists('ap_rogue_pick_bridge_valid_mac')
            ? ap_rogue_pick_bridge_valid_mac($bridges, $rogueDhcpAlertInterface)
            : array('interface' => '', 'mac' => '');
        if (!empty($bridgePick['interface']) && !empty($bridgePick['mac'])) {
            $rogueDhcpAlertInterface = $bridgePick['interface'];
            $rogueDhcpValidMac = $bridgePick['mac'];
        }
    } catch (Exception $e) {}
}

/* ── Données ─────────────────────────────────────────────────────────────── */
$incidents     = anti_fraud_load();
$devices       = device_monitor_load();
$openCount     = anti_fraud_count_unack();
$totalCount    = count($incidents);
$ackCount      = count(array_filter($incidents, fn($i) => ($i['status'] ?? '') === 'acknowledged'));
$resolvedCount = count(array_filter($incidents, fn($i) => ($i['status'] ?? '') === 'resolved'));
$deviceCount   = count($devices);
$deviceRiskCount = count(array_filter($devices, function ($d) {
    return in_array($d['type'] ?? 'unknown', array('tv', 'pc', 'macos', 'computer', 'console'), true);
}));
$deviceBlockedCount = count(array_filter($devices, fn($d) => !empty($d['blocked'])));
$deviceLastSeen = '';
foreach ($devices as $d) {
    if (!empty($d['last_seen']) && $d['last_seen'] > $deviceLastSeen) $deviceLastSeen = $d['last_seen'];
}
$apItems = $apMonitor['items'] ?? array();
$apStats = $apMonitor['stats'] ?? array('total' => 0, 'conflicts' => 0, 'static_ok' => 0);
$rogueDhcpItems = function_exists('ap_rogue_load') ? ap_rogue_load() : array();
$rogueDhcpCount = count($rogueDhcpItems);
$rogueDhcpScript = function_exists('ap_rogue_build_script')
    ? ap_rogue_build_script($webhookUrl, $fraudApiKey, $session ?? '', $rogueDhcpAlertInterface)
    : '';
$rogueDhcpFields = function_exists('ap_rogue_build_alert_fields')
    ? ap_rogue_build_alert_fields($webhookUrl, $fraudApiKey, $session ?? '', $rogueDhcpAlertInterface, $rogueDhcpValidMac)
    : array(
        'interface' => $rogueDhcpAlertInterface,
        'valid_server' => $rogueDhcpValidMac ?: 'MAC_DU_BRIDGE_MIKROTIK',
        'alert_timeout' => '1h',
        'on_alert' => $rogueDhcpScript,
        'comment' => 'MIKHMON-RogueDHCP',
    );

/* ── Helpers ─────────────────────────────────────────────────────────────── */
$routerName    = 'MIKHMON-AntiFraud';
$sessionEsc    = htmlspecialchars($session ?? 'MON-ROUTEUR');
$apiKeyEsc     = htmlspecialchars($fraudApiKey);
$webhookEsc    = htmlspecialchars($webhookUrl);
$webhookRecommendedEsc = htmlspecialchars($webhookRecommendedUrl);
$webhookRecommendedHostEsc = htmlspecialchars($webhookRecommendedHost);
$webhookBrowserEsc = htmlspecialchars($webhookBrowserUrl);

/* Icône FA selon le type d'appareil */
function fraud_fa_icon(string $label, string $vendor): string {
    $v = strtolower($label . ' ' . $vendor);
    if (preg_match('/macbook|laptop|notebook/i', $v)) return 'fa-laptop';
    if (preg_match('/ipad|tablet/i', $v))              return 'fa-tablet';
    if (preg_match('/desktop|imac|pc/i', $v))          return 'fa-desktop';
    if (preg_match('/router|mikrotik|modem/i', $v))    return 'fa-server';
    return 'fa-mobile';
}

/* Couleur badge marque */
function fraud_vendor_color(string $vendor): array {
    $map = [
        'Apple'    => ['#1d1d1f','#f5f5f7'],
        'Samsung'  => ['#1428a0','#eaedff'],
        'Huawei'   => ['#cf0a2c','#ffeef1'],
        'Honor'    => ['#b91c1c','#fff0f0'],
        'Xiaomi'   => ['#ff6900','#fff3e8'],
        'Realme'   => ['#fbbf24','#fff8e1'],
        'Oppo'     => ['#1a1a2e','#ededff'],
        'Vivo'     => ['#2563eb','#eff6ff'],
        'Tecno'    => ['#047857','#ecfdf5'],
        'Infinix'  => ['#7c3aed','#f5f3ff'],
        'Itel'     => ['#0369a1','#e0f2fe'],
        'Nokia'    => ['#005aff','#e8f0ff'],
        'Motorola' => ['#5b21b6','#f3f0ff'],
        'Lenovo'   => ['#1d4ed8','#eff6ff'],
        'Google Pixel'=> ['#4285f4','#e8f0fe'],
        'Sony'     => ['#0f172a','#f1f5f9'],
        'LG'       => ['#c2410c','#fff7ed'],
    ];
    foreach ($map as $k => $v) {
        if (stripos($vendor, $k) !== false) return $v;
    }
    return ['#475569','#f1f5f9'];
}
?>
<style>
/* ════════════════════════════════════════════════════════════════
   ANTI-FRAUDE — UI PREMIUM v3
   SafeLink Africa / MIKHMON
════════════════════════════════════════════════════════════════ */
:root {
  --fr-primary: #008BC9;
  --fr-primary-2: #14A3B8;
  --fr-primary-dark: #075985;
  --fr-ink: #162033;
  --fr-muted: #64748B;
  --fr-panel: #FFFFFF;
  --fr-soft: #F6FAFD;
  --fr-border: #D8E4EE;
  --fr-red: #C2410C;
  --fr-red-lt: #FFF3ED;
  --fr-red-bd: #FDBA8C;
  --fr-grn: #15803D;
  --fr-grn-lt: #F0FDF4;
  --fr-grn-bd: #BBF7D0;
  --fr-amb: #B45309;
  --fr-amb-lt: #FFFBEB;
  --fr-amb-bd: #FDE68A;
  --fr-blue: #075985;
  --fr-blue2: #008BC9;
  --fr-slate: #475569;
  --fr-bg: #F3F7FA;
  --fr-card: #FFFFFF;
  --fr-orange: #E46E2E;
}

.fr-wrap,
.fr-wrap * {
  box-sizing: border-box;
}

.fr-wrap {
  max-width: 1140px;
  margin: 0 auto;
  width: 100%;
  padding: 0 0 48px;
  overflow-x: hidden;
}

/* ── Header ─────────────────────────────────────────────────────── */
.fr-header {
  background: linear-gradient(135deg, #075985 0%, #008BC9 58%, #14A3B8 100%);
  border-radius: 14px;
  padding: 24px 28px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 20px;
  box-shadow: 0 8px 24px rgba(0,139,201,.22);
  position: relative;
  overflow: hidden;
}
.fr-header::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 82% 42%, rgba(255,255,255,.15) 0%, transparent 58%);
  pointer-events: none;
}
.fr-header-icon {
  flex: 0 0 54px; height: 54px;
  border-radius: 12px;
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.28);
  display: flex; align-items: center; justify-content: center;
}
.fr-header-icon i { font-size: 24px; color: #ffffff; }
.fr-header-title h2 {
  margin: 0; color: #fff;
  font-size: 20px; font-weight: 800; letter-spacing: .3px;
}
.fr-header-title p {
  margin: 3px 0 0; color: rgba(255,255,255,.78);
  font-size: 12.5px; letter-spacing: .2px;
}
.fr-header-actions {
  margin-left: auto;
  display: flex; align-items: center; gap: 10px;
}
.fr-new-chip {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 14px; border-radius: 20px;
  background: rgba(255,243,237,.18);
  border: 1px solid rgba(253,186,140,.5);
  color: #fff7ed; font-size: 12px; font-weight: 700;
}
.fr-new-chip .chip-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #ef4444;
  box-shadow: 0 0 0 3px rgba(239,68,68,.3);
  animation: fr-pulse 1.5s infinite;
}
.fr-new-chip.zero {
  background: rgba(22,163,74,.12);
  border-color: rgba(22,163,74,.3);
  color: #86efac;
}
.fr-new-chip.zero .chip-dot {
  background: #22c55e;
  box-shadow: 0 0 0 3px rgba(34,197,94,.2);
  animation: none;
}
@keyframes fr-pulse {
  0%,100% { box-shadow: 0 0 0 3px rgba(239,68,68,.3); }
  50%      { box-shadow: 0 0 0 6px rgba(239,68,68,.05); }
}
.fr-rescan-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px; border-radius: 8px;
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24);
  color: #e2e8f0; font-size: 12px; font-weight: 600;
  text-decoration: none; transition: all .2s;
}
.fr-rescan-btn:hover {
  background: rgba(255,255,255,.18); color: #fff;
  transform: translateY(-1px);
}

/* ── Connection status bar ──────────────────────────────────────── */
.fr-conn-bar {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 10px; margin-bottom: 18px;
  font-size: 12.5px; font-weight: 600;
  border: 1px solid;
}
.fr-conn-bar.ok {
  background: #f0fdf4; border-color: #bbf7d0; color: #15803d;
}
.fr-conn-bar.push {
  background: #ecfeff; border-color: #a5f3fc; color: #0e7490;
}
.fr-conn-bar.err {
  background: #fef9c3; border-color: #fde047; color: #854d0e;
}
.fr-conn-bar .conn-dot {
  width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto;
}
.fr-conn-bar.ok   .conn-dot { background: #22c55e; box-shadow: 0 0 0 2px #dcfce7; }
.fr-conn-bar.push .conn-dot { background: #3b82f6; box-shadow: 0 0 0 2px #dbeafe; animation: fr-pulse-blue 2s infinite; }
.fr-conn-bar.err  .conn-dot { background: #f59e0b; box-shadow: 0 0 0 2px #fef9c3; }
@keyframes fr-pulse-blue { 0%,100%{box-shadow:0 0 0 2px #dbeafe} 50%{box-shadow:0 0 0 5px #bfdbfe} }
.fr-conn-bar .conn-msg  { flex: 1; }
.fr-conn-bar .conn-ip   { opacity: .7; font-weight: 400; font-size: 11.5px; }
.fr-conn-refresh { font-size: 11px; color: #94a3b8; margin-left: auto;
  display: flex; align-items: center; gap: 5px; }

/* ── Stats strip ─────────────────────────────────────────────────── */
.fr-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px; margin-bottom: 20px;
}
.fr-stat {
  background: #fff;
  border-radius: 12px;
  padding: 16px 18px;
  box-shadow: 0 1px 4px rgba(15,23,42,.07);
  border: 1px solid var(--fr-border);
  display: flex; align-items: center; gap: 14px;
  transition: transform .2s, box-shadow .2s;
}
.fr-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(15,23,42,.1); }
.fr-stat-icon {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex: 0 0 auto;
}
.fr-stat-icon i { font-size: 18px; }
.fr-stat.danger  .fr-stat-icon { background: #fef2f2; color: #dc2626; }
.fr-stat.warning .fr-stat-icon { background: #fffbeb; color: #d97706; }
.fr-stat.success .fr-stat-icon { background: #f0fdf4; color: #16a34a; }
.fr-stat.info    .fr-stat-icon { background: #ecfeff; color: var(--fr-primary-dark); }
.fr-stat-val { font-size: 26px; font-weight: 900; color: #0f172a; line-height: 1; }
.fr-stat-lbl { font-size: 11px; color: #64748b; margin-top: 2px; letter-spacing: .3px; text-transform: uppercase; }

/* ── Setup panel ─────────────────────────────────────────────────── */
.fr-setup {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgba(15,23,42,.07);
  border: 1px solid var(--fr-border);
  margin-bottom: 22px; overflow: hidden;
}
.fr-setup-head {
  display: flex; align-items: center; gap: 14px;
  padding: 16px 22px; cursor: pointer;
  background: linear-gradient(90deg, #075985 0%, #008BC9 100%);
  user-select: none;
  transition: opacity .2s;
}
.fr-setup-head:hover { opacity: .92; }
.fr-setup-head-icon {
  width: 36px; height: 36px; border-radius: 8px;
  background: rgba(255,255,255,.12);
  display: flex; align-items: center; justify-content: center;
  color: #93c5fd; font-size: 16px;
}
.fr-setup-head h4 { margin: 0; color: #fff; font-size: 14px; font-weight: 700; }
.fr-setup-head small { color: rgba(255,255,255,.6); font-size: 11px; }
.fr-setup-head .fr-chevron {
  margin-left: auto; color: rgba(255,255,255,.5); font-size: 13px;
  transition: transform .3s;
}
.fr-setup-head.open .fr-chevron { transform: rotate(180deg); }
.fr-setup-body { display: none; padding: 22px; }
.fr-setup-body.open { display: block; }

.fr-step {
  display: flex; gap: 16px; margin-bottom: 16px;
  padding: 14px 16px; border-radius: 10px;
  background: #f8fafc; border: 1px solid #e2e8f0;
}
.fr-step-num {
  flex: 0 0 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800; color: #fff;
}
.fr-step:nth-child(1) .fr-step-num,
.fr-step:nth-child(2) .fr-step-num { background: var(--fr-primary); }
.fr-step:nth-child(3) .fr-step-num { background: #c2410c; }
.fr-step:nth-child(4) .fr-step-num { background: #16a34a; }
.fr-step:nth-child(5) .fr-step-num { background: #d97706; }
.fr-step h5 { margin: 0 0 5px; font-size: 12.5px; color: #1e293b; font-weight: 700; }
.fr-step p  { margin: 0; font-size: 11.5px; color: #64748b; line-height: 1.6; }

.fr-key-box {
  display: flex; align-items: center; gap: 8px;
  background: #102436; color: #7dd3fc;
  padding: 9px 14px; border-radius: 8px;
  font-family: 'Courier New', monospace; font-size: 12.5px;
  margin: 8px 0; word-break: break-all;
  min-width: 0;
}
.fr-key-box code {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}
.fr-copy-btn {
  flex: 0 0 auto; background: #183247; color: #dbeafe;
  border: none; border-radius: 6px; padding: 5px 10px;
  font-size: 11px; cursor: pointer; transition: background .15s;
  display: inline-flex; align-items: center; gap: 4px;
}
.fr-copy-btn:hover   { background: #075985; color: #fff; }
.fr-copy-btn.copied  { background: #166534; color: #bbf7d0; }

.fr-routeros {
  background: #102436; border-radius: 10px;
  padding: 16px; font-family: 'Courier New', monospace;
  font-size: 11.5px; line-height: 1.75; overflow-x: auto;
  white-space: pre; max-height: 400px; overflow-y: auto;
  position: relative; border: 1px solid #1f3b53;
  max-width: 100%;
}
.fr-routeros .rk { color: #7dd3fc; font-weight: 700; }
.fr-routeros .rv { color: #fbbf24; }
.fr-routeros .rs { color: #86efac; }
.fr-routeros .rc { color: #475569; font-style: italic; }
.fr-script-copy {
  position: sticky; top: 6px; float: right; margin-bottom: -30px;
  background: var(--fr-primary); color: #fff; border: none; border-radius: 6px;
  padding: 5px 12px; font-size: 11px; cursor: pointer; z-index: 2;
  display: inline-flex; align-items: center; gap: 5px;
}
.fr-script-copy:hover { background: var(--fr-primary-dark); }

.fr-sched-box {
  background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;
  padding: 12px 16px; font-size: 12px; color: #1e40af; margin-top: 8px;
}
.fr-sched-box code {
  background: #dbeafe; padding: 2px 6px; border-radius: 4px;
  font-size: 11px; font-weight: 600;
}

/* ── Empty state ─────────────────────────────────────────────────── */
.fr-empty {
  text-align: center; padding: 60px 32px;
}
.fr-empty .empty-icon-wrap {
  width: 72px; height: 72px; border-radius: 50%;
  background: #f0fdf4; border: 2px solid #bbf7d0;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.fr-empty .empty-icon-wrap i { font-size: 30px; color: #16a34a; }
.fr-empty h3 { margin: 0 0 6px; font-size: 17px; color: #0f172a; font-weight: 700; }
.fr-empty p  { margin: 0; font-size: 13px; color: #64748b; }

/* ── Incident card ───────────────────────────────────────────────── */
.fr-incident-list { display: flex; flex-direction: column; gap: 14px; }
.fr-incident {
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 2px 8px rgba(15,23,42,.08);
  border: 1px solid var(--fr-border);
  overflow: hidden;
  transition: box-shadow .2s;
}
.fr-incident:hover { box-shadow: 0 4px 16px rgba(15,23,42,.13); }
.fr-incident.status-new {
  border-left: 4px solid var(--fr-red);
  animation: fr-incident-in .35s ease;
}
.fr-incident.status-acknowledged { border-left: 4px solid var(--fr-amb); }
.fr-incident.status-resolved     { border-left: 4px solid var(--fr-grn); opacity: .8; }
@keyframes fr-incident-in {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: none; }
}

/* Incident header */
.fr-inc-head {
  display: flex; align-items: center; gap: 12px;
  padding: 16px 18px 12px; flex-wrap: wrap;
  border-bottom: 1px solid #f1f5f9;
}
.fr-inc-avatar {
  width: 40px; height: 40px; border-radius: 10px; flex: 0 0 auto;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
}
.status-new          .fr-inc-avatar { background: #fef2f2; color: var(--fr-red); }
.status-acknowledged .fr-inc-avatar { background: #fffbeb; color: var(--fr-amb); }
.status-resolved     .fr-inc-avatar { background: #f0fdf4; color: var(--fr-grn); }
.fr-inc-code { font-family: monospace; font-weight: 800; font-size: 16px; color: #1e3a5f; }
.fr-status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px; border-radius: 20px;
  font-size: 10.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
}
.fr-status-badge.new          { background: #fef2f2; color: var(--fr-red);  border: 1px solid #fecaca; }
.fr-status-badge.acknowledged { background: #fffbeb; color: var(--fr-amb);  border: 1px solid #fde68a; }
.fr-status-badge.resolved     { background: #f0fdf4; color: var(--fr-grn);  border: 1px solid #bbf7d0; }
.fr-status-badge.new::before  { content:''; width:6px;height:6px;border-radius:50%;background:var(--fr-red);animation:fr-pulse 1.5s infinite; }
.fr-inc-meta { font-size: 11.5px; color: #64748b; display: flex; align-items: center; gap: 6px; }
.fr-inc-meta i { font-size: 10.5px; opacity: .6; }
.fr-push-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 700;
  background: #ecfeff; color: var(--fr-primary-dark); border: 1px solid #a5f3fc;
}
.fr-push-badge.live { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.fr-inc-time { margin-left: auto; font-size: 11px; color: #94a3b8;
  display: flex; align-items: center; gap: 4px; }

/* Incident body */
.fr-inc-body { padding: 16px 18px; }

/* Section labels */
.fr-section {
  display: flex; align-items: center; gap: 8px;
  margin: 0 0 10px; padding: 6px 10px; border-radius: 6px;
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px;
}
.fr-section.owner { background: #f0fdf4; color: #166534; border-left: 3px solid #22c55e; }
.fr-section.fraud { background: #fef2f2; color: #991b1b; border-left: 3px solid #ef4444; }

/* Device cards grid */
.fr-devices {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
  gap: 10px;
  margin-bottom: 16px;
}

.fr-device {
  min-width: 0; max-width: none;
  border-radius: 12px; padding: 12px 14px;
  border: 1.5px solid;
  display: flex; flex-direction: column; gap: 4px;
  transition: transform .2s;
}
.fr-device:hover { transform: translateY(-2px); }
.fr-device.owner { background: #f0fdf4; border-color: #86efac; }
.fr-device.fraud { background: #fff5f5; border-color: #fca5a5; }
.fr-device-header {
  display: flex; align-items: center; gap: 9px; margin-bottom: 6px;
  min-width: 0;
}
.fr-device-icon-wrap {
  width: 34px; height: 34px; border-radius: 8px; flex: 0 0 auto;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.owner .fr-device-icon-wrap { background: #dcfce7; color: #16a34a; }
.fraud .fr-device-icon-wrap { background: #fee2e2; color: #dc2626; }
.fr-device-brand-name { font-size: 13px; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
.owner .fr-device-brand-name { color: #166534; }
.fraud .fr-device-brand-name { color: #991b1b; }
.fr-device-hostname { font-size: 10.5px; color: #64748b; font-style: italic; }

.fr-vendor-chip {
  display: inline-block; padding: 2px 8px; border-radius: 12px;
  font-size: 10px; font-weight: 700; margin-bottom: 4px;
  white-space: normal;
  overflow-wrap: anywhere;
}
.fr-device-row {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; color: #475569; font-family: monospace;
  min-width: 0;
}
.fr-device-row i { font-size: 10px; opacity: .55; flex: 0 0 12px; text-align: center; }
.fr-device-row span { font-family: sans-serif; min-width: 0; overflow-wrap: anywhere; }
.fr-attempt-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: var(--fr-red); color: #fff;
  padding: 2px 8px; border-radius: 10px;
  font-size: 10.5px; font-weight: 800;
  margin-top: 4px; align-self: flex-start;
}

/* Timeline */
.fr-timeline {
  margin-top: 14px;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.fr-tl-title {
  font-size: 10px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .7px; color: #94a3b8; margin-bottom: 8px;
  display: flex; align-items: center; gap: 6px;
}
.fr-tl-item {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 11px; color: #475569; padding: 3px 0;
  border-left: 2px solid #e2e8f0; margin-left: 6px; padding-left: 12px;
  position: relative;
}
.fr-tl-item::before {
  content: ''; position: absolute; left: -5px; top: 6px;
  width: 8px; height: 8px; border-radius: 50%;
  background: #cbd5e1; border: 2px solid #f8fafc;
}
.fr-tl-item.ev-detected::before         { background: var(--fr-red); }
.fr-tl-item.ev-new_attempt::before      { background: #f97316; }
.fr-tl-item.ev-acknowledged::before     { background: var(--fr-amb); }
.fr-tl-item.ev-resolved::before         { background: var(--fr-grn); }
.fr-tl-item.ev-device_blocked::before   { background: var(--fr-primary-dark); }
.fr-tl-item.ev-device_unblocked::before { background: #0891b2; }
.fr-tl-time { color: #94a3b8; white-space: nowrap; font-size: 10.5px; }
.fr-tl-evt  { flex: 1; color: #334155; font-weight: 600; }
.fr-tl-by   { color: #94a3b8; font-style: italic; font-weight: 400; }

/* ── Block / Unblock badge & button ─────────────────────────────── */
.fr-blocked-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; border-radius: 12px;
  font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase;
  background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
  margin-top: 5px; align-self: flex-start;
}
.fr-blocked-badge.is-blocked {
  background: #fee2e2; color: #991b1b; border-color: #fca5a5;
}
.fr-blocked-badge.not-blocked {
  background: #f0fdf4; color: #166534; border-color: #86efac;
}
.fr-block-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 11px; border-radius: 7px; font-size: 11px;
  font-weight: 700; border: 1px solid; cursor: pointer;
  transition: all .15s; margin-top: 6px; align-self: flex-start;
  white-space: nowrap;
}
.fr-block-btn.do-block {
  background: #fef2f2; color: #991b1b; border-color: #fca5a5;
}
.fr-block-btn.do-block:hover {
  background: #dc2626; color: #fff; border-color: #dc2626;
  transform: translateY(-1px);
}
.fr-block-btn.do-unblock {
  background: #f0fdf4; color: #166534; border-color: #86efac;
}
.fr-block-btn.do-unblock:hover {
  background: #16a34a; color: #fff; border-color: #16a34a;
  transform: translateY(-1px);
}
.fr-block-btn[disabled] {
  opacity: .5; cursor: wait;
}
.fr-device .fr-block-area {
  margin-top: 4px; display: flex; flex-direction: column; gap: 2px;
  border-top: 1px dashed #fca5a5; padding-top: 8px;
}

/* Actions */
.fr-inc-actions {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 12px 18px; border-top: 1px solid #f1f5f9;
  background: #fafafa;
}
.fr-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: 8px; font-size: 12px;
  font-weight: 600; border: 1px solid; cursor: pointer;
  transition: all .15s; text-decoration: none;
}
.fr-btn:hover { transform: translateY(-1px); }
.fr-btn.ack  { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.fr-btn.res  { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.fr-btn.lock { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.fr-btn.ack:hover  { background: #fef9c3; }
.fr-btn.res:hover  { background: #dcfce7; }
.fr-btn.lock:hover { background: #fee2e2; }

/* Auto-refresh counter */
.fr-refresh-bar {
  display: flex; align-items: center; gap: 8px;
  font-size: 11.5px; color: #94a3b8;
  margin-bottom: 14px;
}
.fr-refresh-dot {
  width: 7px; height: 7px; border-radius: 50%; background: #22c55e;
  animation: fr-pulse 2.5s infinite;
}

/* ── Tabs / device monitor ─────────────────────────────────────── */
.fr-tabs {
  display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 16px;
  border-bottom: 1px solid var(--fr-border);
  max-width: 100%;
}
.fr-tab {
  display: inline-flex; align-items: center; gap: 8px;
  border: 1px solid transparent; border-bottom: none;
  background: transparent; color: #64748b;
  padding: 10px 14px; border-radius: 10px 10px 0 0;
  font-size: 12px; font-weight: 800; cursor: pointer;
}
.fr-tab.active {
  background: #fff; color: var(--fr-primary-dark); border-color: var(--fr-border);
  box-shadow: 0 -1px 4px rgba(15,23,42,.04);
}
.fr-tab-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px;
  background: #e2e8f0; color: #334155; font-size: 11px; font-weight: 900;
}
.fr-tab.active .fr-tab-count { background: #ecfeff; color: var(--fr-primary-dark); }
.fr-tab-panel { display: none; }
.fr-tab-panel.active { display: block; }

.fr-auto-inject {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: 14px 16px; margin-bottom: 16px;
}
.fr-auto-inject h5 { margin: 0 0 4px; color: #1e293b; font-size: 13px; }
.fr-auto-inject p { margin: 0; color: #64748b; font-size: 11.5px; line-height: 1.45; }
.fr-script-state {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 10px; border-radius: 18px; font-size: 11px; font-weight: 800;
}
.fr-script-state.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.fr-script-state.off { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
.fr-auto-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.fr-auto-btn {
  display: inline-flex; align-items: center; gap: 6px;
  border: 1px solid; border-radius: 8px; padding: 8px 12px;
  font-size: 12px; font-weight: 800; cursor: pointer;
}
.fr-auto-btn.primary { background: var(--fr-primary); border-color: var(--fr-primary); color: #fff; }
.fr-auto-btn.ghost { background: #fff; border-color: #cbd5e1; color: #334155; }
.fr-auto-btn.danger { background: #fff; border-color: #fecaca; color: #991b1b; }
.fr-auto-btn[disabled] { opacity: .55; cursor: wait; }

.fr-device-monitor-head {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  background: linear-gradient(135deg, #ffffff 0%, #f6fafc 100%); border: 1px solid var(--fr-border); border-radius: 12px;
  padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 1px 4px rgba(15,23,42,.06);
}
.fr-device-monitor-head h3 { margin: 0; font-size: 15px; color: #0f172a; }
.fr-device-monitor-head p { margin: 4px 0 0; font-size: 12px; color: #64748b; }
.fr-device-summary {
  display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;
}
.fr-mini-badge {
  display: inline-flex; align-items: center; gap: 5px;
  border-radius: 14px; padding: 5px 9px; font-size: 11px; font-weight: 800;
  background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
}
.fr-mini-badge.warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.fr-mini-badge.danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.fr-monitor-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(min(255px, 100%), 1fr));
  gap: 12px;
}
.fr-monitor-card {
  background: #fff; border: 1px solid var(--fr-border); border-radius: 12px;
  padding: 14px; box-shadow: 0 1px 4px rgba(15,23,42,.06);
}
.fr-monitor-card.risk { border-left: 4px solid #d97706; }
.fr-monitor-card.blocked { border-left: 4px solid #dc2626; }
.fr-monitor-top { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.fr-monitor-icon {
  width: 38px; height: 38px; border-radius: 10px; display: flex;
  align-items: center; justify-content: center; flex: 0 0 auto;
  background: #ecfeff; color: var(--fr-primary-dark); font-size: 17px;
}
.fr-monitor-title { color: #0f172a; font-size: 13px; font-weight: 900; line-height: 1.25; }
.fr-monitor-sub { color: #64748b; font-size: 11px; margin-top: 2px; }
.fr-monitor-row {
  display: flex; gap: 7px; align-items: center; color: #475569;
  font-size: 11.5px; padding: 3px 0; word-break: break-word;
}
.fr-monitor-row i { width: 13px; color: #94a3b8; text-align: center; }
.fr-monitor-actions {
  display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px;
  border-top: 1px dashed #e2e8f0; padding-top: 10px;
}
.fr-monitor-btn {
  display: inline-flex; align-items: center; gap: 5px;
  border: 1px solid; border-radius: 7px; padding: 6px 9px;
  font-size: 11px; font-weight: 800; cursor: pointer;
}
.fr-monitor-btn.disconnect { background: #ecfeff; color: var(--fr-primary-dark); border-color: #a5f3fc; }
.fr-monitor-btn.block { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.fr-monitor-btn.unblock { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.fr-monitor-btn[disabled] { opacity: .55; cursor: wait; }

.fr-ap-hero {
  background: linear-gradient(135deg, #ffffff 0%, #f6fafc 58%, #ecfeff 100%);
  border: 1px solid #bae6fd; border-radius: 14px; padding: 18px 20px;
  margin-bottom: 14px; display: flex; justify-content: space-between; gap: 18px;
  box-shadow: 0 1px 4px rgba(15,23,42,.06);
}
.fr-ap-hero h3 { margin: 0; color: #0f172a; font-size: 16px; }
.fr-ap-hero p { margin: 6px 0 0; color: #64748b; font-size: 12.5px; line-height: 1.55; }
.fr-ap-stats { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; align-content: flex-start; }
.fr-ap-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
  gap: 12px;
}
.fr-ap-card {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: 14px; box-shadow: 0 1px 5px rgba(15,23,42,.07);
  border-left: 4px solid #22c55e;
}
.fr-ap-card.conflict { border-left-color: #dc2626; }
.fr-ap-top { display: flex; gap: 11px; align-items: flex-start; margin-bottom: 12px; }
.fr-ap-icon {
  width: 40px; height: 40px; border-radius: 10px; display: flex;
  align-items: center; justify-content: center; background: #ecfeff; color: var(--fr-primary-dark);
  flex: 0 0 auto; font-size: 18px;
}
.fr-ap-title { font-size: 14px; color: #0f172a; font-weight: 900; line-height: 1.2; }
.fr-ap-model { font-size: 11.5px; color: #64748b; margin-top: 3px; }
.fr-ap-ip {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  background: #102436; color: #a5f3fc; border-radius: 10px; padding: 10px 12px;
  font-family: "Courier New", monospace; font-size: 15px; font-weight: 900;
  margin-bottom: 10px;
}
.fr-ap-risk {
  display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px;
  border-radius: 14px; font-size: 10.5px; font-weight: 900; white-space: nowrap;
}
.fr-ap-risk.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.fr-ap-risk.conflict { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.fr-ap-row {
  display: flex; align-items: center; gap: 7px; color: #475569;
  font-size: 11.5px; padding: 3px 0; word-break: break-word;
}
.fr-ap-row i { width: 13px; color: #94a3b8; text-align: center; }
.fr-ap-evidence {
  margin-top: 9px; padding-top: 9px; border-top: 1px dashed #e2e8f0;
  color: #64748b; font-size: 11px; line-height: 1.45;
}
.fr-rogue-panel {
  background: #fff; border: 1px solid #fed7aa; border-radius: 12px;
  padding: 14px; margin-bottom: 14px; box-shadow: 0 1px 5px rgba(15,23,42,.06);
}
.fr-rogue-head {
  display: flex; justify-content: space-between; gap: 14px; align-items: flex-start;
  border-bottom: 1px dashed #e2e8f0; padding-bottom: 11px; margin-bottom: 11px;
}
.fr-rogue-head h4 { margin: 0; font-size: 14px; color: #0f172a; }
.fr-rogue-head p { margin: 4px 0 0; color: #64748b; font-size: 11.5px; line-height: 1.45; }
.fr-script-notice {
  background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b;
  border-radius: 8px; padding: 9px 12px; margin-bottom: 8px;
  font-size: 11.5px; color: #92400e; line-height: 1.6;
}
.fr-script-notice i { color: #d97706; margin-right: 5px; }
.fr-script-notice code { background: #fef3c7; border-radius: 3px; padding: 1px 4px; font-size: 11px; }
.fr-rogue-fields {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
  gap: 8px; margin: 10px 0;
}
.fr-rogue-field {
  border: 1px solid #dbeafe; border-radius: 9px; background: #f8fbff;
  padding: 9px 10px; min-width: 0;
}
.fr-rogue-field label {
  display: block; color: #64748b; font-size: 10.5px; font-weight: 900;
  text-transform: uppercase; margin-bottom: 5px;
}
.fr-rogue-field code {
  display: block; color: #0f172a; background: transparent; padding: 0;
  font-family: "Courier New", monospace; font-size: 12px; white-space: normal;
  overflow-wrap: anywhere;
}
.fr-rogue-script-title {
  color: #0f172a; font-size: 12px; font-weight: 900; margin: 10px 0 6px;
}
.fr-rogue-script {
  background: #102436; color: #bfdbfe; border-radius: 10px;
  padding: 12px; max-height: 230px; overflow: auto; white-space: pre-wrap;
  font-family: "Courier New", monospace; font-size: 10.5px; line-height: 1.55;
}
.fr-rogue-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 10px; margin-top: 12px; }
.fr-rogue-item { border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 10px; padding: 11px; background: #fffafa; }
.fr-rogue-title { font-weight: 900; color: #991b1b; font-size: 13px; margin-bottom: 7px; }
.fr-rogue-row { display: flex; gap: 7px; align-items: center; color: #475569; font-size: 11.5px; padding: 2px 0; word-break: break-word; }
.fr-rogue-row i { width: 13px; text-align: center; color: #94a3b8; }

@media (max-width: 1024px) {
  .fr-wrap { padding-left: 8px; padding-right: 8px; }
  .fr-header {
    align-items: flex-start;
    flex-wrap: wrap;
    padding: 20px;
  }
  .fr-header-actions {
    width: 100%;
    margin-left: 0;
    justify-content: flex-start;
    flex-wrap: wrap;
  }
  .fr-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .fr-conn-bar {
    align-items: flex-start;
    flex-wrap: wrap;
  }
  .fr-conn-refresh {
    width: 100%;
    margin-left: 16px;
  }
  .fr-ap-hero,
  .fr-rogue-head,
  .fr-device-monitor-head,
  .fr-auto-inject {
    flex-direction: column;
    align-items: stretch;
  }
  .fr-ap-stats,
  .fr-device-summary,
  .fr-auto-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 768px) {
  .content.content-margin { margin-left: 0; margin-right: 0; }
  .fr-wrap {
    padding-left: 6px;
    padding-right: 6px;
  }
  .fr-header {
    padding: 16px;
    gap: 12px;
    border-radius: 12px;
  }
  .fr-header-icon {
    width: 42px;
    height: 42px;
    flex-basis: 42px;
  }
  .fr-header-title {
    flex: 1 1 calc(100% - 58px);
    min-width: 0;
  }
  .fr-header-title h2 {
    font-size: 16px;
    line-height: 1.25;
    letter-spacing: 0;
  }
  .fr-header-title p {
    font-size: 11.5px;
    line-height: 1.45;
  }
  .fr-rescan-btn,
  .fr-new-chip {
    min-height: 40px;
  }
  .fr-conn-bar,
  .fr-stat,
  .fr-setup,
  .fr-incident,
  .fr-device-monitor-head,
  .fr-ap-hero,
  .fr-rogue-panel,
  .fr-monitor-card,
  .fr-ap-card {
    border-radius: 10px;
  }
  .fr-stat {
    padding: 13px;
    gap: 10px;
  }
  .fr-stat-icon {
    width: 36px;
    height: 36px;
  }
  .fr-stat-val { font-size: 22px; }
  .fr-setup-head {
    padding: 14px;
    align-items: flex-start;
  }
  .fr-setup-body { padding: 14px; }
  .fr-step {
    padding: 12px;
    gap: 12px;
  }
  .fr-step > div:not(.fr-step-num) {
    min-width: 0;
  }
  .fr-key-box {
    align-items: flex-start;
    flex-wrap: wrap;
    padding: 10px;
    font-size: 11px;
  }
  .fr-key-box > i {
    margin-top: 2px;
  }
  .fr-key-box .fr-copy-btn {
    margin-left: auto;
  }
  .fr-sched-box {
    padding: 10px;
    overflow-wrap: anywhere;
  }
  .fr-sched-box code {
    display: inline-block;
    max-width: 100%;
    overflow-wrap: anywhere;
  }
  .fr-routeros,
  .fr-rogue-script {
    font-size: 10px;
    line-height: 1.55;
    max-height: 52vh;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .fr-script-copy {
    position: static;
    float: none;
    margin: 0 0 8px;
    min-height: 38px;
  }
  .fr-tabs {
    flex-wrap: nowrap;
    overflow-x: auto;
    padding-bottom: 1px;
    -webkit-overflow-scrolling: touch;
  }
  .fr-tab {
    flex: 0 0 auto;
    min-height: 42px;
    padding: 9px 12px;
    white-space: nowrap;
  }
  .fr-inc-head {
    padding: 14px;
    gap: 9px;
  }
  .fr-inc-head > div:nth-child(2) {
    min-width: 0;
    flex: 1 1 calc(100% - 54px);
  }
  .fr-inc-time {
    margin-left: 0;
    width: 100%;
  }
  .fr-inc-body { padding: 14px; }
  .fr-section {
    align-items: flex-start;
    line-height: 1.35;
  }
  .fr-devices {
    grid-template-columns: 1fr;
  }
  .fr-tl-item {
    flex-direction: column;
    gap: 3px;
  }
  .fr-tl-time {
    white-space: normal;
  }
  .fr-inc-actions,
  .fr-monitor-actions {
    justify-content: stretch;
    flex-direction: column;
  }
  .fr-btn,
  .fr-monitor-btn,
  .fr-auto-btn,
  .fr-block-btn {
    width: 100%;
    min-height: 40px;
    justify-content: center;
  }
  .fr-copy-btn {
    min-height: 36px;
  }
  .fr-ap-ip {
    align-items: flex-start;
    flex-direction: column;
    font-size: 13px;
  }
  .fr-ap-risk {
    white-space: normal;
  }
  .fr-monitor-top,
  .fr-ap-top {
    min-width: 0;
  }
  .fr-monitor-top > div:last-child,
  .fr-ap-top > div:last-child {
    min-width: 0;
  }
}

@media (max-width: 480px) {
  .fr-wrap {
    padding-left: 4px;
    padding-right: 4px;
  }
  .fr-stats {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .fr-header {
    padding: 14px;
  }
  .fr-header-actions {
    display: grid;
    grid-template-columns: 1fr;
  }
  .fr-new-chip,
  .fr-rescan-btn {
    justify-content: center;
    width: 100%;
  }
  .fr-setup-head-icon,
  .fr-header-icon {
    display: none;
  }
  .fr-setup-head h4 {
    font-size: 13px;
    line-height: 1.35;
  }
  .fr-setup-head small {
    display: block;
    line-height: 1.35;
  }
  .fr-step {
    flex-direction: column;
  }
  .fr-step-num {
    width: 28px;
    flex-basis: 28px;
  }
  .fr-key-box .fr-copy-btn {
    width: 100%;
    justify-content: center;
  }
  .fr-empty {
    padding: 34px 14px;
  }
  .fr-incident-list {
    gap: 10px;
  }
  .fr-inc-code {
    font-size: 14px;
    overflow-wrap: anywhere;
  }
  .fr-status-badge,
  .fr-push-badge,
  .fr-mini-badge {
    max-width: 100%;
    white-space: normal;
  }
  .fr-device,
  .fr-monitor-card,
  .fr-ap-card,
  .fr-rogue-item {
    padding: 12px;
  }
  .fr-device-row,
  .fr-monitor-row,
  .fr-ap-row,
  .fr-rogue-row {
    align-items: flex-start;
  }
  .fr-component-card { padding: 12px; }
  .fr-comp-header { flex-wrap: wrap; gap: 10px; }
  .fr-comp-icon { display: none; }
  .fr-adv-toggle { font-size: 12px; }
}

/* ── Component cards (Configuration automatique) ────────────────────── */
.fr-explain-block {
  display: flex; align-items: flex-start; gap: 12px;
  background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px;
  padding: 13px 16px; margin-bottom: 16px; font-size: 12px; color: #0c4a6e;
}
.fr-explain-block i { font-size: 16px; color: #0284c7; flex-shrink: 0; margin-top: 1px; }
.fr-explain-block strong { display: block; font-weight: 700; margin-bottom: 3px; font-size: 12.5px; }

.fr-component-card {
  background: #fff; border: 1px solid var(--fr-border); border-radius: 12px;
  padding: 16px 18px; margin-bottom: 12px;
  box-shadow: 0 1px 4px rgba(15,23,42,.05);
}
.fr-comp-header {
  display: flex; align-items: center; gap: 14px; margin-bottom: 12px;
}
.fr-comp-icon {
  flex: 0 0 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid transparent; font-size: 18px;
}
.fr-comp-info { flex: 1; min-width: 0; }
.fr-comp-name {
  font-size: 13px; font-weight: 800; color: #1e293b;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin-bottom: 4px;
}
.fr-comp-interval {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 12px; font-size: 10.5px; font-weight: 700;
  background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd;
  font-family: inherit;
}
.fr-comp-desc {
  font-size: 11.5px; color: #64748b; line-height: 1.55;
}

.fr-adv-toggle {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; margin-top: 8px; border-radius: 8px;
  background: #f8fafc; border: 1px dashed #cbd5e1; color: #475569;
  font-size: 12.5px; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.fr-adv-toggle:hover { background: #f1f5f9; color: #1e293b; }
.fr-adv-toggle i:first-child { color: #008BC9; }
</style>

<div class="content content-margin">
<div class="fr-wrap">

<!-- ── HEADER ────────────────────────────────────────────────────── -->
<div class="fr-header">
  <div class="fr-header-icon">
    <i class="fa fa-shield"></i>
  </div>
  <div class="fr-header-title">
    <h2><i class="fa fa-shield" style="color:#ffffff;margin-right:6px;font-size:16px;"></i>Anti-fraude et Audit Réseau</h2>
    <p>Surveille les tickets partagés, les appareils, les routeurs AP et les alertes DHCP rogue via MikroTik</p>
  </div>
  <div class="fr-header-actions">
    <span class="fr-new-chip <?= $openCount === 0 ? 'zero' : '' ?>">
      <span class="chip-dot"></span>
      <i class="fa fa-<?= $openCount > 0 ? 'bell' : 'check' ?>"></i>
      <?= $openCount ?> nouveau<?= $openCount > 1 ? 'x' : '' ?>
    </span>
    <a href="./admin.php?id=fraud&session=<?= urlencode($session ?? '') ?>" class="fr-rescan-btn">
      <i class="fa fa-refresh"></i> Re-scan
    </a>
  </div>
</div>

<!-- ── CONNECTION STATUS BAR ─────────────────────────────────────── -->
<?php
$lastPush = null;
if (!empty($incidents)) {
    foreach ($incidents as $inc) {
        if (!empty($inc['push_source']) && !empty($inc['last_seen'])) {
            $lastPush = $inc['last_seen']; break;
        }
    }
}
$hasPushData = $lastPush !== null;
?>
<?php if ($apiOk): ?>
<div class="fr-conn-bar ok">
  <span class="conn-dot"></span>
  <i class="fa fa-plug"></i>
  <span class="conn-msg">
    <strong>MikroTik connecté</strong> — Scan API direct actif
    <span class="conn-ip">&nbsp;· <?= htmlspecialchars($iphost ?? '') ?></span>
  </span>
  <span class="fr-conn-refresh">
    <i class="fa fa-refresh fa-spin" style="font-size:10px;"></i>
    Actualisation dans <strong id="refreshCountdown">30</strong>s
  </span>
</div>
<?php elseif ($hasPushData): ?>
<div class="fr-conn-bar push">
  <span class="conn-dot"></span>
  <i class="fa fa-rss"></i>
  <span class="conn-msg">
    <strong>Mode Push actif</strong> — Données reçues depuis MikroTik
    <span class="conn-ip">&nbsp;· Dernier push : <?= htmlspecialchars($lastPush ?? '') ?></span>
  </span>
  <span class="fr-conn-refresh">
    <i class="fa fa-refresh fa-spin" style="font-size:10px;"></i>
    Actualisation dans <strong id="refreshCountdown">30</strong>s
  </span>
</div>
<?php else: ?>
<div class="fr-conn-bar err">
  <span class="conn-dot"></span>
  <i class="fa fa-exclamation-triangle"></i>
  <span class="conn-msg">
    <strong>MikroTik non connecté</strong>
    <?php if ($apiError): ?><span class="conn-ip">&nbsp;· <?= htmlspecialchars($apiError) ?></span><?php endif; ?>
    &nbsp;&mdash; Configurez le script push ci-dessous pour activer la surveillance
  </span>
  <span class="fr-conn-refresh">
    <i class="fa fa-refresh fa-spin" style="font-size:10px;"></i>
    <strong id="refreshCountdown">30</strong>s
  </span>
</div>
<?php endif; ?>

<!-- ── STATS STRIP ────────────────────────────────────────────────── -->
<div class="fr-stats">
  <div class="fr-stat danger">
    <div class="fr-stat-icon"><i class="fa fa-exclamation-circle"></i></div>
    <div>
      <div class="fr-stat-val"><?= $openCount ?></div>
      <div class="fr-stat-lbl">Nouveaux</div>
    </div>
  </div>
  <div class="fr-stat warning">
    <div class="fr-stat-icon"><i class="fa fa-eye"></i></div>
    <div>
      <div class="fr-stat-val"><?= $ackCount ?></div>
      <div class="fr-stat-lbl">Reconnus</div>
    </div>
  </div>
  <div class="fr-stat success">
    <div class="fr-stat-icon"><i class="fa fa-check-circle"></i></div>
    <div>
      <div class="fr-stat-val"><?= $resolvedCount ?></div>
      <div class="fr-stat-lbl">Résolus</div>
    </div>
  </div>
  <div class="fr-stat info">
    <div class="fr-stat-icon"><i class="fa fa-list-alt"></i></div>
    <div>
      <div class="fr-stat-val"><?= $totalCount ?></div>
      <div class="fr-stat-lbl">Total</div>
    </div>
  </div>
</div>

<!-- ── SETUP PANEL ────────────────────────────────────────────────── -->
<div class="fr-setup" id="frSetup">
  <div class="fr-setup-head" id="frSetupHead" onclick="toggleSetup()">
    <div class="fr-setup-head-icon"><i class="fa fa-magic"></i></div>
    <div>
      <h4><i class="fa fa-cog" style="margin-right:5px;"></i>Configuration automatique &mdash; Scripts MikroTik</h4>
      <small>Deploiement en 1 clic &mdash; installe les 3 composants de surveillance directement sur le routeur</small>
    </div>
    <i class="fa fa-chevron-down fr-chevron"></i>
  </div>

  <div class="fr-setup-body" id="frSetupBody">

    <!-- Explication -->
    <div class="fr-explain-block">
      <i class="fa fa-info-circle"></i>
      <div>
        <strong>Comment ca marche</strong>
        MIKHMON deploie 3 composants directement sur MikroTik via API RouterOS &mdash; aucune saisie manuelle.
        Chaque composant s'execute en tache de fond et envoie ses donnees au webhook
        <code><?= $webhookEsc ?></code>.
        <div class="fr-sched-box" style="background:#f8fafc;border-color:#cbd5e1;color:#334155;margin-top:10px;">
          <i class="fa fa-plug"></i>
          <b>Adresse recommandee pour la liaison MikroTik :</b>
          <code><?= $webhookRecommendedEsc ?></code>
          <?php if ($webhookRecommendedHostEsc !== ''): ?>
            <br><span style="color:#64748b;">Hote detecte : <code><?= $webhookRecommendedHostEsc ?></code>. Les scripts injectes utiliseront cette adresse, pas <code>localhost</code>.</span>
          <?php endif; ?>
          <?php if ($webhookBrowserEsc !== ''): ?>
            <br><span style="color:#075985;">Pour tester la liaison depuis la meme adresse, ouvrez cette page via :
            <code><?= $webhookBrowserEsc ?></code></span>
          <?php endif; ?>
        </div>
        <?php if ($webhookIsLoopback): ?>
          <br><span style="color:#b45309;"><i class="fa fa-exclamation-triangle"></i>
          URL locale detectee (<code><?= htmlspecialchars($webhookHost) ?></code>) &mdash;
          depuis MikroTik, localhost designe le routeur, pas ce serveur. Ouvrez MIKHMON
          depuis l'IP accessible par MikroTik avant d'installer.</span>
        <?php endif; ?>
        <div class="fr-sched-box" style="background:#fff7ed;border-color:#fed7aa;color:#9a3412;margin-top:10px;">
          <i class="fa fa-docker"></i>
          <b>Mode conteneur MikroTik :</b>
          ne montez pas <code>/src/src/process</code>. Ce dossier doit venir de l'image Docker pour garder
          <code>fraud_webhook.php</code> et <code>inject_script.php</code> a jour. Utilisez plutot un volume dedie
          pour <code>/src/src/logs</code> ou une variable <code>MIKHMON_FRAUD_API_KEY</code>.
        </div>
      </div>
    </div>

    <!-- Composant 1 : Anti-Fraud -->
    <div class="fr-component-card">
      <div class="fr-comp-header">
        <div class="fr-comp-icon" style="background:#fff3ed;border-color:#fed7aa;">
          <i class="fa fa-user-secret" style="color:#c2410c;"></i>
        </div>
        <div class="fr-comp-info">
          <div class="fr-comp-name">
            MIKHMON-AntiFraud
            <code class="fr-comp-interval"><i class="fa fa-clock-o"></i> 5 min</code>
          </div>
          <div class="fr-comp-desc">
            Detecte les codes hotspot (tickets) utilises depuis plusieurs appareils differents.
            Collecte les sessions actives, cookies et logs "invalid MAC" &mdash; signale les abus en temps reel.
          </div>
        </div>
        <span class="fr-script-state <?= ($antiFrScriptDeployed && $antiFrSchedDeployed) ? 'ok' : 'off' ?>" id="antiFrScriptState">
          <i class="fa fa-<?= ($antiFrScriptDeployed && $antiFrSchedDeployed) ? 'check-circle' : 'exclamation-circle' ?>"></i>
          <?= ($antiFrScriptDeployed && $antiFrSchedDeployed) ? 'Installe' : ($antiFrScriptDeployed ? 'Script OK, scheduler absent' : 'Non installe') ?>
        </span>
      </div>
      <div class="fr-auto-actions">
        <button class="fr-auto-btn primary" onclick="antiFraudAction('antifr_inject', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-upload"></i> Installer / MAJ
        </button>
        <button class="fr-auto-btn ghost" onclick="antiFraudAction('antifr_status', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-search"></i> Verifier
        </button>
        <button class="fr-auto-btn danger" onclick="antiFraudAction('antifr_remove', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-trash"></i> Retirer
        </button>
      </div>
    </div>

    <!-- Composant 2 : Device Monitor -->
    <div class="fr-component-card">
      <div class="fr-comp-header">
        <div class="fr-comp-icon" style="background:#f0f9ff;border-color:#bae6fd;">
          <i class="fa fa-tv" style="color:#0369a1;"></i>
        </div>
        <div class="fr-comp-info">
          <div class="fr-comp-name">
            MIKHMON-DeviceMonitor
            <code class="fr-comp-interval"><i class="fa fa-clock-o"></i> 10 min</code>
          </div>
          <div class="fr-comp-desc">
            Identifie les appareils TV, PC, consoles et tablettes connectes au hotspot.
            Analyse le prefixe OUI des adresses MAC pour determiner le fabricant et le type d'appareil.
          </div>
        </div>
        <span class="fr-script-state <?= ($scriptDeployed && $schedulerDeployed) ? 'ok' : 'off' ?>" id="deviceScriptState">
          <i class="fa fa-<?= ($scriptDeployed && $schedulerDeployed) ? 'check-circle' : 'exclamation-circle' ?>"></i>
          <?= ($scriptDeployed && $schedulerDeployed) ? 'Installe' : ($scriptDeployed ? 'Script OK, scheduler absent' : 'Non installe') ?>
        </span>
      </div>
      <div class="fr-auto-actions">
        <button class="fr-auto-btn primary" onclick="deviceScriptAction('inject', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-upload"></i> Installer / MAJ
        </button>
        <button class="fr-auto-btn ghost" onclick="deviceScriptAction('status', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-search"></i> Verifier
        </button>
        <button class="fr-auto-btn danger" onclick="deviceScriptAction('remove', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-trash"></i> Retirer
        </button>
      </div>
    </div>

    <!-- Composant 3 : DHCP Rogue Guard -->
    <div class="fr-component-card">
      <div class="fr-comp-header">
        <div class="fr-comp-icon" style="background:#fef2f2;border-color:#fecaca;">
          <i class="fa fa-bolt" style="color:#dc2626;"></i>
        </div>
        <div class="fr-comp-info">
          <div class="fr-comp-name">
            DHCP Rogue Guard
            <code class="fr-comp-interval" style="background:#fef2f2;color:#991b1b;border-color:#fecaca;"><i class="fa fa-bell"></i> Alerte temps reel</code>
          </div>
          <div class="fr-comp-desc">
            Surveille les serveurs DHCP non autorises sur le reseau (routeurs pirates, box connectees en amont).
            Alerte instantanee et remonte la MAC exacte du serveur DHCP inconnu des sa detection.
          </div>
        </div>
        <span class="fr-script-state <?= $rogueDhcpDeployed ? 'ok' : 'off' ?>" id="rogueDhcpState">
          <i class="fa fa-<?= $rogueDhcpDeployed ? 'check-circle' : 'exclamation-circle' ?>"></i>
          <?= $rogueDhcpDeployed ? 'Installe' : 'Non installe' ?>
        </span>
      </div>
      <div class="fr-auto-actions">
        <button class="fr-auto-btn primary" onclick="rogueDhcpAction('rogue_inject', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-upload"></i> Installer
        </button>
        <button class="fr-auto-btn danger" onclick="rogueDhcpAction('rogue_remove', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-trash"></i> Retirer
        </button>
      </div>
    </div>

    <!-- Parametres avances (collapsible) -->
    <div class="fr-adv-toggle" id="frAdvToggle" onclick="toggleAdvanced()">
      <i class="fa fa-sliders"></i>
      Parametres avances &mdash; cle API, URL webhook, scripts RouterOS manuels
      <i class="fa fa-chevron-down" id="frAdvChevron" style="margin-left:auto;transition:transform .2s;"></i>
    </div>

    <div id="frAdvBody" style="display:none;padding-top:8px;">

      <!-- Cle API -->
      <div class="fr-step">
        <div class="fr-step-num" style="background:#475569;">A</div>
        <div>
          <h5><i class="fa fa-key" style="margin-right:5px;color:#008BC9;"></i>Cle API secrete (auto-generee)</h5>
          <p>Authentifie MikroTik aupres de MIKHMON. Ne la partagez pas.</p>
          <div class="fr-key-box">
            <i class="fa fa-lock" style="color:#38bdf8;"></i>
            <code id="apiKeyVal"><?= $apiKeyEsc ?></code>
            <button class="fr-copy-btn" onclick="frCopy('apiKeyVal',this)"><i class="fa fa-clipboard"></i> Copier</button>
          </div>
        </div>
      </div>

      <!-- URL Webhook -->
      <div class="fr-step">
        <div class="fr-step-num" style="background:#475569;">B</div>
        <div>
          <h5><i class="fa fa-chain" style="margin-right:5px;color:#008BC9;"></i>URL du webhook MIKHMON</h5>
          <p>Adresse cible pour les POST depuis MikroTik (<code>/tool/fetch</code>).</p>
          <div class="fr-key-box">
            <i class="fa fa-globe" style="color:#38bdf8;"></i>
            <code id="webhookUrlAdv"><?= $webhookEsc ?></code>
            <button class="fr-copy-btn" onclick="frCopy('webhookUrlAdv',this)"><i class="fa fa-clipboard"></i> Copier</button>
          </div>
          <div class="fr-sched-box" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;margin-top:6px;">
            <i class="fa fa-check-circle"></i>
            Recommandation active : MikroTik doit poster vers
            <code><?= $webhookRecommendedEsc ?></code>
          </div>
          <?php if (in_array($webhookInfo['source'] ?? '', array('auto-host', 'env-host', 'env'), true)): ?>
            <div class="fr-sched-box" style="background:#ecfeff;border-color:#a5f3fc;color:#075985;margin-top:6px;">
              <i class="fa fa-magic"></i>
              URL adaptee automatiquement :
              <code><?= htmlspecialchars($webhookInfo['current_url'] ?? '') ?></code>
              &rarr; <code><?= htmlspecialchars($webhookUrl) ?></code>
            </div>
          <?php endif; ?>
          <div class="fr-sched-box" style="background:#fff7ed;border-color:#fed7aa;color:#9a3412;margin-top:6px;">
            <i class="fa fa-server"></i>
            En conteneur RouterOS, gardez le dossier <code>process</code> dans l'image. Pour persister les donnees,
            montez seulement <code>/src/src/logs</code>. Pour partager une cle fixe, configurez
            <code>MIKHMON_FRAUD_API_KEY</code> au lieu de monter <code>process/fraud_api_key.txt</code>.
          </div>
        </div>
      </div>

      <!-- Script AntiFraud manuel -->
      <div class="fr-step" style="flex-direction:column;border-left-color:#c2410c;">
        <div style="display:flex;gap:16px;align-items:flex-start;">
          <div class="fr-step-num" style="background:#c2410c;">C</div>
          <div>
            <h5><i class="fa fa-code" style="margin-right:5px;color:#c2410c;"></i>Script MIKHMON-AntiFraud &mdash; installation manuelle</h5>
            <p>Si vous ne pouvez pas utiliser le bouton Installer (API non disponible), copiez ce script dans
            <strong>System &rsaquo; Scripts &rsaquo; [+]</strong> &mdash; nom : <strong><?= htmlspecialchars($routerName) ?></strong></p>
            <p style="color:#64748b;font-size:11.5px;margin-top:4px;"><i class="fa fa-info-circle"></i>
            Ne collez pas ligne par ligne dans le terminal RouterOS &mdash; les blocs imbriques provoqueraient des erreurs. Utilisez Winbox / WebFig ou le bouton Installer.</p>
          </div>
        </div>
        <div style="position:relative;margin-top:10px;">
          <button class="fr-script-copy" onclick="frCopyScript(event)">
            <i class="fa fa-clipboard"></i> Copier le script
          </button>
          <div class="fr-routeros" id="routerosScript"><?php
echo htmlspecialchars(anti_fraud_build_script($webhookUrl, $fraudApiKey, $session ?? ''), ENT_QUOTES, 'UTF-8');
          ?></div>
        </div>
      </div>

      <!-- Scheduler manuel -->
      <div class="fr-step">
        <div class="fr-step-num" style="background:#16a34a;">D</div>
        <div style="flex:1;">
          <h5><i class="fa fa-calendar-check-o" style="margin-right:5px;color:#16a34a;"></i>Scheduler MIKHMON-AntiFraud-Task &mdash; installation manuelle</h5>
          <p>Creez le scheduler si le script a ete installe manuellement :</p>
          <div class="fr-sched-box">
            <b>Name :</b> <code>MIKHMON-AntiFraud-Task</code> &nbsp;
            <b>Interval :</b> <code>00:05:00</code> &nbsp;
            <b>On Event :</b> <code><?= htmlspecialchars($routerName) ?></code><br><br>
            <b>CLI :</b>
            <code>/system scheduler add name="MIKHMON-AntiFraud-Task" interval=5m on-event="<?= htmlspecialchars($routerName) ?>" policy=read,write,test,ftp start-time=startup</code>
          </div>
        </div>
      </div>

      <!-- Test -->
      <div class="fr-step">
        <div class="fr-step-num" style="background:#d97706;">E</div>
        <div>
          <h5><i class="fa fa-play-circle" style="margin-right:5px;color:#d97706;"></i>Tester le script</h5>
          <p>Lancez manuellement depuis <strong>System &rsaquo; Scripts &rsaquo; [Run]</strong> ou terminal :</p>
          <div class="fr-key-box" style="font-size:12px;">
            <i class="fa fa-terminal" style="color:#38bdf8;"></i>
            <code id="runCmd">/system script run <?= htmlspecialchars($routerName) ?></code>
            <button class="fr-copy-btn" onclick="frCopy('runCmd',this)"><i class="fa fa-clipboard"></i></button>
          </div>
          <p style="margin-top:6px;">Verifiez <strong>Log &rsaquo; topics:hotspot</strong> : message <code>[MIKHMON-AntiFraud] rapport envoye</code>, puis rechargez cette page.</p>
        </div>
      </div>

    </div><!-- /frAdvBody -->

  </div><!-- /fr-setup-body -->
</div><!-- /fr-setup -->

<!-- ── REFRESH BAR ────────────────────────────────────────────────── -->
<div class="fr-refresh-bar">
  <span class="fr-refresh-dot"></span>
  Actualisation dans <strong>&nbsp;<span id="refreshCountdown2">30</span>s</strong>
  &nbsp;&mdash;&nbsp;
  <span style="color:#cbd5e1;">
    <?php if ($apiOk): ?>
      <i class="fa fa-plug"></i> API directe active
    <?php elseif ($hasPushData): ?>
      <i class="fa fa-rss"></i> Push MikroTik actif
    <?php else: ?>
      <i class="fa fa-exclamation-circle" style="color:#f59e0b;"></i> En attente de connexion MikroTik
    <?php endif; ?>
  </span>
</div>

<!-- ── TABS ──────────────────────────────────────────────────────── -->
<div class="fr-tabs">
  <button class="fr-tab active" type="button" data-tab="incidents" onclick="frShowTab('incidents', this)">
    <i class="fa fa-user-secret"></i>
    Codes partagés
    <span class="fr-tab-count"><?= $totalCount ?></span>
  </button>
  <button class="fr-tab" type="button" data-tab="devices" onclick="frShowTab('devices', this)">
    <i class="fa fa-tv"></i>
    Appareils TV/PC
    <span class="fr-tab-count"><?= $deviceCount ?></span>
  </button>
  <button class="fr-tab" type="button" data-tab="aps" onclick="frShowTab('aps', this)">
    <i class="fa fa-sitemap"></i>
    Routeurs AP
    <span class="fr-tab-count"><?= (int)($apStats['total'] ?? 0) + $rogueDhcpCount ?></span>
  </button>
</div>

<!-- ── INCIDENTS ──────────────────────────────────────────────────── -->
<div class="fr-tab-panel active" id="frTab-incidents">
<?php if (empty($incidents)): ?>
  <div class="fr-empty">
    <div class="empty-icon-wrap">
      <i class="fa fa-check"></i>
    </div>
    <h3><?= isset($_fraud_none_detected) ? htmlspecialchars($_fraud_none_detected) : 'Aucun cas détecté' ?></h3>
    <p>
      <?php if ($apiOk): ?>
        Scan MikroTik effectué &mdash; aucune anomalie détectée.
      <?php else: ?>
        Installez le script Push (ci-dessus) pour activer la surveillance en temps réel.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="fr-incident-list">
  <?php foreach ($incidents as $i):
    $st          = $i['status'] ?? 'new';
    $macs        = $i['macs'] ?? [];
    $lockedMac   = strtoupper((string)($i['locked_mac'] ?? ''));
    $lockedDev   = $i['locked_device'] ?? [];
    $attempted   = $i['attempted_macs'] ?? [];
    $attemptMeta = $i['attempted_meta'] ?? [];
    $history     = $i['history'] ?? [];
    $pushSrc     = $i['push_source'] ?? '';
    $isLive      = strpos($pushSrc, 'active_') === 0;
    $macMeta     = $i['mac_meta'] ?? [];

    // Somme des tentatives
    $totalAttempts = 0;
    foreach ($attempted as $am) {
        $totalAttempts += (int)($attemptMeta[$am]['attempts'] ?? 1);
    }
  ?>
  <div class="fr-incident status-<?= htmlspecialchars($st) ?>">

    <!-- Incident header -->
    <div class="fr-inc-head">
      <div class="fr-inc-avatar">
        <i class="fa fa-user-secret"></i>
      </div>
      <div>
        <div class="fr-inc-code"><?= htmlspecialchars($i['user']) ?></div>
        <?php if (!empty($i['profile'])): ?>
          <div class="fr-inc-meta"><i class="fa fa-tag"></i> <?= htmlspecialchars($i['profile']) ?></div>
        <?php endif; ?>
      </div>
      <span class="fr-status-badge <?= $st ?>">
        <i class="fa fa-<?= $st === 'new' ? 'bell' : ($st === 'acknowledged' ? 'eye' : 'check') ?>"></i>
        <?= strtoupper($st) ?>
      </span>
      <?php if (!empty($attempted)): ?>
        <span class="fr-status-badge" style="background:#fef2f2;color:#991b1b;border-color:#fecaca;">
          <i class="fa fa-exclamation-triangle"></i>
          <?= count($attempted) ?> fraude<?= count($attempted) > 1 ? 's' : '' ?>
          &middot; <?= $totalAttempts ?> tentative<?= $totalAttempts > 1 ? 's' : '' ?>
        </span>
      <?php endif; ?>
      <?php if ($pushSrc): ?>
        <span class="fr-push-badge <?= $isLive ? 'live' : '' ?>">
          <i class="fa fa-<?= $isLive ? 'rss' : 'download' ?>"></i>
          <?= htmlspecialchars($pushSrc) ?>
        </span>
      <?php endif; ?>
      <div class="fr-inc-time">
        <i class="fa fa-clock-o"></i>
        <?= htmlspecialchars($i['last_seen'] ?? '-') ?>
      </div>
    </div>

    <!-- Incident body -->
    <div class="fr-inc-body">

      <!-- Appareil légitime (propriétaire du code) -->
      <?php
        $lMac    = strtoupper($lockedDev['mac']      ?? ($lockedMac ?: ($macs[0] ?? '')));
        $lHn     = $lockedDev['hostname']  ?? ($macMeta[$lMac]['hostname'] ?? '');
        $lVendor = $lockedDev['vendor']    ?? ($macMeta[$lMac]['vendor']   ?? '');
        $lLabel  = $lockedDev['label']     ?? ($macMeta[$lMac]['label']    ?? '');
        $lIp     = $lockedDev['ip']        ?? ($macMeta[$lMac]['ip']       ?? '');
        $lSrc    = $macMeta[$lMac]['source'] ?? '';
        $lIcon   = fraud_fa_icon($lLabel, $lVendor);
        [$lTxtClr, $lBgClr] = fraud_vendor_color($lVendor);
        $lName   = $lLabel ?: ($lVendor ?: 'Appareil inconnu');
      ?>
      <?php if ($lMac): ?>
      <div class="fr-section owner">
        <i class="fa fa-lock"></i>
        PROPRIÉTAIRE DU CODE — Appareil légitime
      </div>
      <div class="fr-devices">
        <div class="fr-device owner">
          <div class="fr-device-header">
            <div class="fr-device-icon-wrap"><i class="fa <?= $lIcon ?>"></i></div>
            <div>
              <div class="fr-device-brand-name"><?= htmlspecialchars($lName) ?></div>
              <?php if ($lHn && $lHn !== $lName): ?>
                <div class="fr-device-hostname"><?= htmlspecialchars($lHn) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($lVendor && $lVendor !== $lName): ?>
            <span class="fr-vendor-chip" style="background:<?= htmlspecialchars($lBgClr) ?>;color:<?= htmlspecialchars($lTxtClr) ?>;">
              <i class="fa fa-building" style="font-size:9px;margin-right:3px;"></i><?= htmlspecialchars($lVendor) ?>
            </span>
          <?php endif; ?>
          <?php if ($lMac): ?>
            <div class="fr-device-row"><i class="fa fa-barcode"></i><?= htmlspecialchars($lMac) ?></div>
          <?php endif; ?>
          <?php if ($lIp): ?>
            <div class="fr-device-row"><i class="fa fa-globe"></i><?= htmlspecialchars($lIp) ?></div>
          <?php endif; ?>
          <?php if (!empty($macMeta[$lMac]['first_seen'])): ?>
            <div class="fr-device-row"><i class="fa fa-clock-o"></i><span><?= htmlspecialchars($macMeta[$lMac]['first_seen']) ?></span></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Appareils fraudeurs -->
      <?php if (!empty($attempted)): ?>
      <div class="fr-section fraud" style="margin-top:<?= $lMac ? '4px' : '0' ?>;">
        <i class="fa fa-ban"></i>
        APPAREILS FRAUDEURS —
        <?= count($attempted) ?> appareil<?= count($attempted) > 1 ? 's' : '' ?>,
        <?= $totalAttempts ?> tentative<?= $totalAttempts > 1 ? 's' : '' ?> bloquée<?= $totalAttempts > 1 ? 's' : '' ?>
      </div>
      <div class="fr-devices">
        <?php foreach ($attempted as $am):
          $meta     = $attemptMeta[$am] ?? [];
          $aMac     = $meta['mac']        ?? (strncmp($am,'IP:',3) === 0 ? '' : $am);
          $aIp      = $meta['ip']         ?? '';
          $aHn      = $meta['hostname']   ?? '';
          $aVendor  = $meta['vendor']     ?? '';
          $aLabel   = $meta['label']      ?? '';
          $aFirst   = $meta['first_seen'] ?? '';
          $aLast    = $meta['last_seen']  ?? '';
          $aNb      = (int)($meta['attempts'] ?? 1);
          $aIcon    = fraud_fa_icon($aLabel, $aVendor);
          $aName    = $aLabel ?: ($aVendor ?: 'Appareil inconnu');
          [$aTxtClr, $aBgClr] = fraud_vendor_color($aVendor);
        ?>
        <div class="fr-device fraud">
          <div class="fr-device-header">
            <div class="fr-device-icon-wrap"><i class="fa <?= $aIcon ?>"></i></div>
            <div>
              <div class="fr-device-brand-name"><?= htmlspecialchars($aName) ?></div>
              <?php if ($aHn && $aHn !== $aName): ?>
                <div class="fr-device-hostname"><?= htmlspecialchars($aHn) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($aVendor && $aVendor !== $aName): ?>
            <span class="fr-vendor-chip" style="background:<?= htmlspecialchars($aBgClr) ?>;color:<?= htmlspecialchars($aTxtClr) ?>;">
              <i class="fa fa-building" style="font-size:9px;margin-right:3px;"></i><?= htmlspecialchars($aVendor) ?>
            </span>
          <?php endif; ?>
          <?php if ($aMac): ?>
            <div class="fr-device-row"><i class="fa fa-barcode"></i><?= htmlspecialchars($aMac) ?></div>
          <?php endif; ?>
          <?php if ($aIp): ?>
            <div class="fr-device-row"><i class="fa fa-globe"></i><?= htmlspecialchars($aIp) ?></div>
          <?php endif; ?>
          <?php if ($aFirst): ?>
            <div class="fr-device-row"><i class="fa fa-clock-o"></i><span>1re : <?= htmlspecialchars($aFirst) ?></span></div>
          <?php endif; ?>
          <?php if ($aLast && $aLast !== $aFirst): ?>
            <div class="fr-device-row"><i class="fa fa-history"></i><span>Dernière : <?= htmlspecialchars($aLast) ?></span></div>
          <?php endif; ?>
          <?php if ($aNb >= 1): ?>
            <div class="fr-attempt-badge">
              <i class="fa fa-repeat" style="font-size:9px;"></i>
              <?= $aNb ?> tentative<?= $aNb > 1 ? 's' : '' ?>
            </div>
          <?php endif; ?>

          <?php /* ── Blocage IP+MAC ────────────────────────────────── */ ?>
          <?php
            $isBlocked  = !empty($meta['blocked']);
            $blkAt      = $meta['blocked_at']  ?? '';
            $blkBndId   = $meta['binding_id']  ?? '';
            $blkFwId    = $meta['fw_id']        ?? '';
          ?>
          <div class="fr-block-area">
            <?php if ($isBlocked): ?>
              <span class="fr-blocked-badge is-blocked">
                <i class="fa fa-ban" style="font-size:9px;"></i>
                BLOQUÉ<?= $blkAt ? ' · ' . htmlspecialchars($blkAt) : '' ?>
              </span>
              <button class="fr-block-btn do-unblock"
                onclick="fraudBlockDevice(
                  <?= json_encode($i['user']) ?>,
                  <?= json_encode($am) ?>,
                  <?= json_encode($aMac) ?>,
                  <?= json_encode($aIp) ?>,
                  <?= json_encode($blkBndId) ?>,
                  <?= json_encode($blkFwId) ?>,
                  'unblock_device', this)">
                <i class="fa fa-unlock"></i> Débloquer
              </button>
            <?php else: ?>
              <button class="fr-block-btn do-block"
                onclick="fraudBlockDevice(
                  <?= json_encode($i['user']) ?>,
                  <?= json_encode($am) ?>,
                  <?= json_encode($aMac) ?>,
                  <?= json_encode($aIp) ?>,
                  '', '',
                  'block_device', this)">
                <i class="fa fa-ban"></i> Bloquer IP+MAC
              </button>
            <?php endif; ?>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Timeline -->
      <?php if (!empty($history)): ?>
      <div class="fr-timeline">
        <div class="fr-tl-title"><i class="fa fa-history"></i> Historique</div>
        <?php foreach (array_reverse(array_slice($history, -5)) as $h): ?>
          <?php
            $ev = $h['event'] ?? '';
            $evLabels = [
              'detected'         => ['fa-search',        'Incident détecté'],
              'new_attempt'      => ['fa-exclamation',   'Nouvelle tentative frauduleuse'],
              'acknowledged'     => ['fa-eye',            'Reconnu par admin'],
              'resolved'         => ['fa-check-circle',  'Résolu'],
              'device_blocked'   => ['fa-ban',            'Appareil bloqué (IP+MAC)'],
              'device_unblocked' => ['fa-unlock',         'Appareil débloqué'],
            ];
            [$evIco, $evTxt] = $evLabels[$ev] ?? ['fa-circle', htmlspecialchars($ev)];
          ?>
          <div class="fr-tl-item ev-<?= htmlspecialchars($ev) ?>">
            <span class="fr-tl-time"><i class="fa fa-clock-o" style="font-size:9px;"></i> <?= htmlspecialchars($h['at'] ?? '-') ?></span>
            <span class="fr-tl-evt"><i class="fa <?= $evIco ?>" style="font-size:10px;margin-right:4px;"></i><?= $evTxt ?></span>
            <?php if (!empty($h['by'])): ?>
              <span class="fr-tl-by">— <?= htmlspecialchars($h['by']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /fr-inc-body -->

    <!-- Actions -->
    <?php if ($st !== 'resolved'): ?>
    <div class="fr-inc-actions">
      <?php if ($st === 'new'): ?>
        <button class="fr-btn ack" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','acknowledged',false)">
          <i class="fa fa-eye"></i> Reconnaître
        </button>
      <?php endif; ?>
      <button class="fr-btn res" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','resolved',false)">
        <i class="fa fa-check"></i> Résoudre
      </button>
      <button class="fr-btn lock" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','resolved',true)">
        <i class="fa fa-ban"></i> Résoudre &amp; Déconnecter
      </button>
    </div>
    <?php endif; ?>

  </div><!-- /fr-incident -->
  <?php endforeach; ?>
  </div><!-- /fr-incident-list -->
<?php endif; ?>
</div>

<!-- ── ACCESS POINT MONITOR ─────────────────────────────────────── -->
<div class="fr-tab-panel" id="frTab-aps">
  <div class="fr-ap-hero">
    <div>
      <h3><i class="fa fa-sitemap" style="color:#008BC9;margin-right:7px;"></i>Routeurs branchés en point d'accès</h3>
      <p>Analyse les IP statiques visibles dans ARP et les voisins MikroTik. Les IP de gestion AP en 192.168.x.x sont priorisées; les clients ordinaires dans le pool DHCP sont ignorés sauf signature routeur forte.</p>
    </div>
    <div class="fr-ap-stats">
      <span class="fr-mini-badge"><i class="fa fa-server"></i><?= (int)($apStats['total'] ?? 0) ?> détecté<?= ((int)($apStats['total'] ?? 0) > 1) ? 's' : '' ?></span>
      <span class="fr-mini-badge danger"><i class="fa fa-exclamation-triangle"></i><?= (int)($apStats['conflicts'] ?? 0) ?> conflit<?= ((int)($apStats['conflicts'] ?? 0) > 1) ? 's' : '' ?></span>
      <span class="fr-mini-badge"><i class="fa fa-check"></i><?= (int)($apStats['static_ok'] ?? 0) ?> IP hors DHCP</span>
      <span class="fr-mini-badge danger"><i class="fa fa-bolt"></i><?= $rogueDhcpCount ?> DHCP rogue</span>
    </div>
  </div>

  <div class="fr-rogue-panel">
    <div class="fr-rogue-head">
      <div>
        <h4><i class="fa fa-bolt" style="color:#dc2626;margin-right:6px;"></i>DHCP Rogue Guard</h4>
        <p>Installe une alerte MikroTik qui accepte comme serveur valide la MAC du bridge MikroTik et remonte la MAC exacte de tout serveur DHCP inconnu détecté sur l'interface surveillée.</p>
        <p>
          <span class="fr-script-state <?= $rogueDhcpDeployed ? 'ok' : 'off' ?>" id="rogueDhcpState2">
            <i class="fa fa-<?= $rogueDhcpDeployed ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= $rogueDhcpDeployed ? 'DHCP Rogue Guard installe' : 'DHCP Rogue Guard non installe' ?>
          </span>
        </p>
      </div>
      <div class="fr-auto-actions">
        <button class="fr-auto-btn primary" onclick="rogueDhcpAction('rogue_inject', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-upload"></i> Installer
        </button>
        <button class="fr-auto-btn danger" onclick="rogueDhcpAction('rogue_remove', this)" <?= $apiOk ? '' : 'disabled title="MikroTik inaccessible"' ?>>
          <i class="fa fa-trash"></i> Retirer
        </button>
        <button class="fr-copy-btn" onclick="frCopy('rogueDhcpScript',this)">
          <i class="fa fa-clipboard"></i> Copier
        </button>
      </div>
    </div>
    <div class="fr-script-notice">
      <i class="fa fa-info-circle"></i>
      <strong>Déploiement manuel :</strong> ouvrez
      <strong>Winbox / WebFig &gt; IP &gt; DHCP Server &gt; Alerts &gt; New</strong>, puis renseignez les champs ci-dessous.
      <br>Le bloc sombre est uniquement le contenu du champ <strong>On Alert</strong>, pas un script à créer dans System Scripts.
      <br>Pour un déploiement automatique sans copier-coller, utilisez le bouton <strong>Installer</strong> ci-dessus.
    </div>
    <div class="fr-rogue-fields">
      <div class="fr-rogue-field">
        <label>Interface</label>
        <code><?= htmlspecialchars($rogueDhcpFields['interface'] ?? 'bridge') ?></code>
      </div>
      <div class="fr-rogue-field">
        <label>Valid Servers</label>
        <code><?= htmlspecialchars($rogueDhcpFields['valid_server'] ?? 'MAC_DU_BRIDGE_MIKROTIK') ?></code>
      </div>
      <div class="fr-rogue-field">
        <label>Alert Timeout</label>
        <code><?= htmlspecialchars($rogueDhcpFields['alert_timeout'] ?? '1h') ?></code>
      </div>
      <div class="fr-rogue-field">
        <label>Comment</label>
        <code><?= htmlspecialchars($rogueDhcpFields['comment'] ?? 'MIKHMON-RogueDHCP') ?></code>
      </div>
    </div>
    <div class="fr-rogue-script-title">On Alert</div>
    <div class="fr-rogue-script" id="rogueDhcpScript"><?= htmlspecialchars($rogueDhcpFields['on_alert'] ?? $rogueDhcpScript) ?></div>
    <?php if (!empty($rogueDhcpItems)): ?>
      <div class="fr-rogue-list">
        <?php foreach (array_slice($rogueDhcpItems, 0, 6) as $r): ?>
          <div class="fr-rogue-item">
            <div class="fr-rogue-title"><i class="fa fa-warning"></i> Serveur DHCP inconnu</div>
            <div class="fr-rogue-row"><i class="fa fa-barcode"></i><span>MAC rogue : <?= htmlspecialchars($r['rogue_mac'] ?? '-') ?></span></div>
            <?php if (!empty($r['rogue_ip'])): ?>
              <div class="fr-rogue-row"><i class="fa fa-map-marker"></i><span>IP vue : <?= htmlspecialchars($r['rogue_ip']) ?></span></div>
            <?php endif; ?>
            <div class="fr-rogue-row"><i class="fa fa-building"></i><span><?= htmlspecialchars($r['vendor'] ?? 'Fabricant inconnu') ?></span></div>
            <div class="fr-rogue-row"><i class="fa fa-random"></i><span>Interface : <?= htmlspecialchars($r['interface'] ?? '-') ?></span></div>
            <div class="fr-rogue-row"><i class="fa fa-check-circle"></i><span>Bridge valide : <?= htmlspecialchars($r['valid_mac'] ?? '-') ?></span></div>
            <div class="fr-rogue-row"><i class="fa fa-clock-o"></i><span>Dernière alerte : <?= htmlspecialchars($r['last_seen'] ?? '-') ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$apiOk): ?>
    <div class="fr-empty">
      <div class="empty-icon-wrap" style="background:#fff7ed;border-color:#fed7aa;">
        <i class="fa fa-plug" style="color:#c2410c;"></i>
      </div>
      <h3>MikroTik non connecté</h3>
      <p>Cette analyse nécessite l'accès API direct pour lire ARP, DHCP, IP pools et voisins réseau.</p>
    </div>
  <?php elseif (empty($apItems)): ?>
    <div class="fr-empty">
      <div class="empty-icon-wrap" style="background:#f0fdf4;border-color:#bbf7d0;">
        <i class="fa fa-check" style="color:#16a34a;"></i>
      </div>
      <h3>Aucun routeur AP statique repéré</h3>
      <p>Aucun équipement hors DHCP avec signature routeur/AP n'a été détecté dans ARP ou les voisins MikroTik.</p>
    </div>
  <?php else: ?>
    <div class="fr-ap-grid">
      <?php foreach ($apItems as $ap):
        $conflict = ($ap['risk'] ?? '') === 'pool_conflict';
      ?>
      <div class="fr-ap-card <?= $conflict ? 'conflict' : '' ?>">
        <div class="fr-ap-top">
          <div class="fr-ap-icon"><i class="fa fa-wifi"></i></div>
          <div>
            <div class="fr-ap-title"><?= htmlspecialchars($ap['brand'] ?? 'Routeur/AP') ?></div>
            <div class="fr-ap-model"><?= htmlspecialchars($ap['model'] ?? 'Modèle non exposé') ?></div>
          </div>
        </div>
        <div class="fr-ap-ip">
          <span><i class="fa fa-map-marker" style="margin-right:6px;"></i><?= htmlspecialchars($ap['ip'] ?? '-') ?></span>
          <span class="fr-ap-risk <?= $conflict ? 'conflict' : 'ok' ?>">
            <i class="fa fa-<?= $conflict ? 'warning' : 'check' ?>"></i><?= htmlspecialchars($ap['risk_label'] ?? '') ?>
          </span>
        </div>
        <?php if (!empty($ap['identity'])): ?>
          <div class="fr-ap-row"><i class="fa fa-tag"></i><span>Nom : <?= htmlspecialchars($ap['identity']) ?></span></div>
        <?php endif; ?>
        <div class="fr-ap-row"><i class="fa fa-barcode"></i><span><?= htmlspecialchars($ap['mac'] ?? '-') ?></span></div>
        <?php if (!empty($ap['interface'])): ?>
          <div class="fr-ap-row"><i class="fa fa-random"></i><span>Interface : <?= htmlspecialchars($ap['interface']) ?></span></div>
        <?php endif; ?>
        <div class="fr-ap-row"><i class="fa fa-percent"></i><span>Confiance : <?= (int)($ap['confidence'] ?? 0) ?>%</span></div>
        <?php if (!empty($ap['evidence'])): ?>
          <div class="fr-ap-evidence">
            <i class="fa fa-search"></i>
            <?= htmlspecialchars(implode(' · ', array_slice((array)$ap['evidence'], 0, 3))) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ── DEVICE MONITOR ─────────────────────────────────────────────── -->
<div class="fr-tab-panel" id="frTab-devices">
  <div class="fr-device-monitor-head">
    <div>
      <h3><i class="fa fa-desktop" style="color:#008BC9;margin-right:6px;"></i>Appareils détectés par ticket hotspot</h3>
      <p>Le script MikroTik remonte les appareils actifs toutes les 10 minutes : TV, ordinateurs, consoles, tablettes et téléphones.</p>
    </div>
    <div class="fr-device-summary">
      <span class="fr-mini-badge"><i class="fa fa-list"></i><?= $deviceCount ?> total</span>
      <span class="fr-mini-badge warn"><i class="fa fa-exclamation-triangle"></i><?= $deviceRiskCount ?> macOS/PC/TV/console</span>
      <span class="fr-mini-badge danger"><i class="fa fa-ban"></i><?= $deviceBlockedCount ?> bloqué<?= $deviceBlockedCount > 1 ? 's' : '' ?></span>
      <?php if ($deviceLastSeen): ?>
        <span class="fr-mini-badge"><i class="fa fa-clock-o"></i><?= htmlspecialchars($deviceLastSeen) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($devices)): ?>
    <div class="fr-empty">
      <div class="empty-icon-wrap" style="background:#ecfeff;border-color:#a5f3fc;">
        <i class="fa fa-tv" style="color:#075985;"></i>
      </div>
      <?php if ($scriptDeployed && $schedulerDeployed): ?>
        <h3>En attente du premier rapport</h3>
        <p><i class="fa fa-check-circle" style="color:#16a34a;"></i> Script <strong>MIKHMON-DeviceMonitor</strong> installé &amp; scheduler actif (10 min).<br>Les appareils apparaîtront ici au prochain cycle ou dès qu'un client hotspot se connecte.</p>
      <?php else: ?>
        <h3>Aucun appareil remonté</h3>
        <p>Injectez le script TV/PC puis attendez le prochain passage du scheduler de 10 minutes.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="fr-monitor-grid">
      <?php foreach ($devices as $d):
        $type = $d['type'] ?? 'unknown';
        $isRisk = in_array($type, array('tv', 'pc', 'macos', 'computer', 'console'), true);
        $isBlocked = !empty($d['blocked']);
        $icon = device_type_icon($type);
        [$typeTxt, $typeBg] = device_type_color($type);
        $name = $d['label'] ?: ($d['vendor'] ?: ($d['hostname'] ?: 'Appareil inconnu'));
        $mac = strtoupper($d['mac'] ?? '');
        $ip = $d['ip'] ?? '';
        $userVoucher = $d['user'] ?? '';
        $confidence = (int)($d['confidence'] ?? 0);
        $evidence = $d['evidence'] ?? [];
        $wifi = is_array($d['wifi'] ?? null) ? $d['wifi'] : [];
        $bindingId = $d['binding_id'] ?? '';
        $fwId = $d['fw_id'] ?? '';
      ?>
      <div class="fr-monitor-card <?= $isBlocked ? 'blocked' : ($isRisk ? 'risk' : '') ?>">
        <div class="fr-monitor-top">
          <div class="fr-monitor-icon" style="background:<?= htmlspecialchars($typeBg) ?>;color:<?= htmlspecialchars($typeTxt) ?>;">
            <i class="fa <?= htmlspecialchars($icon) ?>"></i>
          </div>
          <div>
            <div class="fr-monitor-title"><?= htmlspecialchars($name) ?></div>
            <div class="fr-monitor-sub">
              <?= htmlspecialchars(device_type_label($type)) ?>
              <?php if ($confidence): ?> · <?= $confidence ?>%<?php endif; ?>
              <?php if ($isRisk): ?> · appareil à surveiller<?php endif; ?>
              <?php if ($isBlocked): ?> · bloqué<?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($evidence)): ?>
          <div class="fr-monitor-row"><i class="fa fa-search"></i><span><?= htmlspecialchars(implode(' · ', array_slice((array)$evidence, 0, 2))) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($d['vendor']) && $d['vendor'] !== $name): ?>
          <div class="fr-monitor-row"><i class="fa fa-building"></i><span><?= htmlspecialchars($d['vendor']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($d['hostname']) && $d['hostname'] !== $name): ?>
          <div class="fr-monitor-row"><i class="fa fa-tag"></i><span><?= htmlspecialchars($d['hostname']) ?></span></div>
        <?php endif; ?>
        <?php if ($userVoucher): ?>
          <div class="fr-monitor-row"><i class="fa fa-ticket"></i><span>Ticket : <?= htmlspecialchars($userVoucher) ?></span></div>
        <?php endif; ?>
        <?php if ($mac): ?>
          <div class="fr-monitor-row"><i class="fa fa-barcode"></i><span><?= htmlspecialchars($mac) ?></span></div>
        <?php endif; ?>
        <?php if ($ip): ?>
          <div class="fr-monitor-row"><i class="fa fa-globe"></i><span><?= htmlspecialchars($ip) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($d['uptime'])): ?>
          <div class="fr-monitor-row"><i class="fa fa-signal"></i><span>Uptime : <?= htmlspecialchars($d['uptime']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($wifi['ssid']) || !empty($wifi['interface'])): ?>
          <div class="fr-monitor-row"><i class="fa fa-wifi"></i><span>WiFi : <?= htmlspecialchars($wifi['ssid'] ?: '-') ?><?= !empty($wifi['interface']) ? ' · ' . htmlspecialchars($wifi['interface']) : '' ?></span></div>
        <?php endif; ?>
        <?php if (!empty($wifi['wifi_key'])): ?>
          <div class="fr-monitor-row">
            <i class="fa fa-key"></i>
            <span>Code WiFi :
              <code class="wifi-key-mask" data-key="<?= htmlspecialchars($wifi['wifi_key'], ENT_QUOTES, 'UTF-8') ?>">••••••••••</code>
              <button type="button" class="fr-copy-btn" style="padding:2px 7px;margin-left:5px;" onclick="toggleWifiKey(this)">Afficher</button>
            </span>
          </div>
        <?php endif; ?>
        <?php if (!empty($d['last_seen'])): ?>
          <div class="fr-monitor-row"><i class="fa fa-clock-o"></i><span>Dernière vue : <?= htmlspecialchars($d['last_seen']) ?></span></div>
        <?php endif; ?>

        <div class="fr-monitor-actions">
          <button class="fr-monitor-btn disconnect" onclick="deviceMonitorAction('disconnect', <?= json_encode($mac) ?>, <?= json_encode($ip) ?>, '', '', this)">
            <i class="fa fa-sign-out"></i> Supprimer
          </button>
          <?php if ($isBlocked): ?>
            <button class="fr-monitor-btn unblock" onclick="deviceMonitorAction('unblock', <?= json_encode($mac) ?>, <?= json_encode($ip) ?>, <?= json_encode($bindingId) ?>, <?= json_encode($fwId) ?>, this)">
              <i class="fa fa-unlock"></i> Débloquer
            </button>
          <?php else: ?>
            <button class="fr-monitor-btn block" onclick="deviceMonitorAction('block', <?= json_encode($mac) ?>, <?= json_encode($ip) ?>, '', '', this)">
              <i class="fa fa-ban"></i> Bloquer
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

</div><!-- /fr-wrap -->
</div><!-- /content -->

<script>
/* ── Setup panel ──────────────────────────────────────────────── */
function toggleSetup() {
  var body = document.getElementById('frSetupBody');
  var head = document.getElementById('frSetupHead');
  var open = body.classList.toggle('open');
  head.classList.toggle('open', open);
  localStorage.setItem('frSetupOpen2', open ? '1' : '0');
}
if (localStorage.getItem('frSetupOpen2') === '1') {
  document.getElementById('frSetupBody').classList.add('open');
  document.getElementById('frSetupHead').classList.add('open');
}

/* ── Tabs ─────────────────────────────────────────────────────── */
function frShowTab(tab, btn) {
  var panels = document.querySelectorAll('.fr-tab-panel');
  var tabs = document.querySelectorAll('.fr-tab');
  for (var i = 0; i < panels.length; i++) panels[i].classList.remove('active');
  for (var j = 0; j < tabs.length; j++) tabs[j].classList.remove('active');
  var panel = document.getElementById('frTab-' + tab);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  localStorage.setItem('frActiveTab', tab);
}
(function restoreFraudTab() {
  var tab = localStorage.getItem('frActiveTab');
  if (!tab) return;
  var btn = document.querySelector('.fr-tab[data-tab="' + tab + '"]');
  if (btn) frShowTab(tab, btn);
})();

/* ── Copy helpers ─────────────────────────────────────────────── */
function frCopy(id, btn, raw) {
  var text = raw || document.getElementById(id).textContent;
  navigator.clipboard.writeText(text.trim()).then(function() {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copié !';
    btn.classList.add('copied');
    setTimeout(function() { btn.innerHTML = orig; btn.classList.remove('copied'); }, 2000);
  });
}
function frCopyScript(e) {
  var el  = document.getElementById('routerosScript');
  var raw = (el.innerText || el.textContent).trim();
  navigator.clipboard.writeText(raw).then(function() {
    var btn = e.target.closest('button');
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copié !';
    setTimeout(function() { btn.innerHTML = orig; }, 2500);
  });
}

function toggleWifiKey(btn) {
  var code = btn.parentNode.querySelector('.wifi-key-mask');
  if (!code) return;
  var shown = code.getAttribute('data-shown') === '1';
  if (shown) {
    code.textContent = '••••••••••';
    code.setAttribute('data-shown', '0');
    btn.textContent = 'Afficher';
  } else {
    code.textContent = code.getAttribute('data-key') || '';
    code.setAttribute('data-shown', '1');
    btn.textContent = 'Masquer';
  }
}

/* ── Injection du script TV/PC sur MikroTik ───────────────────── */
function deviceScriptAction(action, btn) {
  if (action === 'remove' && !confirm('Retirer le script TV/PC et son scheduler MikroTik ?')) return;
  btn.disabled = true;
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Traitement...';

  var fd = new FormData();
  fd.append('action', action);
  fd.append('session', <?= json_encode($session ?? '') ?>);

  fetch('./process/inject_script.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false;
      btn.innerHTML = orig;
      if (!j.ok) {
        alert('Erreur : ' + (j.error || (j.errors || []).join(', ') || 'inconnue'));
        return;
      }
      var state = document.getElementById('deviceScriptState');
      if (state) {
        var installed = action === 'remove' ? false : ((j.script_exists && j.sched_exists) || action === 'inject');
        state.className = 'fr-script-state ' + (installed ? 'ok' : 'off');
        state.innerHTML = '<i class="fa fa-' + (installed ? 'check-circle' : 'exclamation-circle') + '"></i>' + (installed ? 'Script + scheduler détectés' : 'Non installé');
      }
      var toastMsg = action === 'remove' ? 'Script TV/PC retiré' : 'Script TV/PC vérifié / installé';
      if (j.recommended_url && action !== 'remove') toastMsg += '\nWebhook injecte : ' + j.recommended_url;
      if (j.warning) toastMsg += '\n' + j.warning;
      frToast(toastMsg, j.warning ? 'info' : 'success');
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Erreur réseau : ' + e);
    });
}

/* ── Injection Anti-Fraud sur MikroTik ────────────────────────────── */
function antiFraudAction(action, btn) {
  if (action === 'antifr_remove' && !confirm('Retirer le script MIKHMON-AntiFraud et son scheduler ?')) return;
  btn.disabled = true;
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Traitement...';

  var fd = new FormData();
  fd.append('action', action);
  fd.append('session', <?= json_encode($session ?? '') ?>);

  fetch('./process/inject_script.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false;
      btn.innerHTML = orig;
      if (!j.ok) {
        alert('Erreur : ' + (j.error || (j.errors || []).join(', ') || 'inconnue'));
        return;
      }
      var state = document.getElementById('antiFrScriptState');
      if (state) {
        var installed = action === 'antifr_remove' ? false
          : ((j.script_exists && j.sched_exists) || action === 'antifr_inject');
        state.className = 'fr-script-state ' + (installed ? 'ok' : 'off');
        state.innerHTML = '<i class="fa fa-' + (installed ? 'check-circle' : 'exclamation-circle') + '"></i>'
          + (installed ? 'Installe' : 'Non installe');
      }
      var msg = action === 'antifr_remove' ? 'MIKHMON-AntiFraud retire'
        : (action === 'antifr_status'
            ? ('AntiFraud : script=' + (j.script_exists ? 'OK' : 'absent') + ' scheduler=' + (j.sched_exists ? 'OK' : 'absent'))
            : 'MIKHMON-AntiFraud installe / mis a jour');
      if (j.recommended_url && action !== 'antifr_remove') msg += '\nWebhook injecte : ' + j.recommended_url;
      if (j.warning) msg += '\n' + j.warning;
      frToast(msg, j.warning ? 'info' : 'success');
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Erreur reseau : ' + e);
    });
}

/* ── Parametres avances (toggle) ──────────────────────────────────── */
function toggleAdvanced() {
  var body = document.getElementById('frAdvBody');
  var chev = document.getElementById('frAdvChevron');
  var toggle = document.getElementById('frAdvToggle');
  if (!body) return;
  var open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
  if (toggle) toggle.style.background = open ? '' : '#e0f0fb';
}

function rogueDhcpAction(action, btn) {
  if (action === 'rogue_remove' && !confirm('Retirer la protection DHCP Rogue MikroTik ?')) return;
  btn.disabled = true;
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Traitement...';

  var fd = new FormData();
  fd.append('action', action);
  fd.append('session', <?= json_encode($session ?? '') ?>);
  fd.append('interface', <?= json_encode($rogueDhcpFields['interface'] ?? 'bridge') ?>);

  fetch('./process/inject_script.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false;
      btn.innerHTML = orig;
      if (!j.ok) {
        alert('Erreur : ' + (j.error || (j.errors || []).join(', ') || 'inconnue'));
        return;
      }
      var installed = action !== 'rogue_remove';
      var stateHtml = '<i class="fa fa-' + (installed ? 'check-circle' : 'exclamation-circle') + '"></i>'
        + (installed ? 'Installe' : 'Non installe');
      ['rogueDhcpState', 'rogueDhcpState2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.className = 'fr-script-state ' + (installed ? 'ok' : 'off'); el.innerHTML = stateHtml; }
      });
      var msg = installed
        ? 'DHCP Rogue Guard installe sur ' + (j.interface || 'bridge') + ' valid-server=' + (j.valid_mac || '-')
        : 'DHCP Rogue Guard retire';
      if (j.recommended_url && installed) msg += '\nWebhook injecte : ' + j.recommended_url;
      if (j.warning) msg += '\n' + j.warning;
      frToast(msg, j.warning ? 'info' : 'success');
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Erreur réseau : ' + e);
    });
}

/* ── Fraud actions (statut incident) ──────────────────────────── */
function fraudAct(user, status, clear) {
  var fd = new FormData();
  fd.append('action',  status);
  fd.append('status',  status);
  fd.append('user',    user);
  fd.append('session', <?= json_encode($session ?? '') ?>);
  if (clear) fd.append('clear_cookies', '1');
  fetch('./process/anti_fraud_action.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) { if (j.ok) window.location.reload(); else alert('Erreur : ' + (j.error || 'inconnue')); })
    .catch(function() { alert(<?= json_encode(isset($_fraud_action_failed) ? $_fraud_action_failed : 'Action échouée') ?>); });
}

/* ── Blocage / Déblocage appareil ─────────────────────────────── */
function fraudBlockDevice(user, deviceKey, mac, ip, bindingId, fwId, action, btn) {
  var isBlock = (action === 'block_device');
  var label   = isBlock
    ? 'Bloquer ' + (mac || ip) + ' sur MikroTik ?\n(IP Binding + Firewall Address List)'
    : 'Débloquer ' + (mac || ip) + ' ?\n(retire le blocage MikroTik)';

  if (!confirm(label)) return;

  btn.disabled = true;
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (isBlock ? 'Blocage…' : 'Déblocage…');

  var fd = new FormData();
  fd.append('action',     action);
  fd.append('user',       user);
  fd.append('device_key', deviceKey);
  fd.append('device_mac', mac);
  fd.append('device_ip',  ip);
  fd.append('binding_id', bindingId);
  fd.append('fw_id',      fwId);
  fd.append('session',    <?= json_encode($session ?? '') ?>);

  fetch('./process/anti_fraud_action.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      if (!j.ok) {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Erreur : ' + (j.error || (j.errors || []).join(', ') || 'inconnue'));
        return;
      }
      // Affichage du toast de résultat
      var msg  = isBlock ? 'Appareil bloqué sur MikroTik' : 'Appareil débloqué';
      var warn = j.warning || (j.warnings && j.warnings.length ? j.warnings.join('; ') : '');
      if (!j.applied) msg += ' (hors-ligne — appliqué localement)';
      if (warn)       msg += '\n⚠ ' + warn;
      frToast(msg, isBlock ? 'danger' : 'success');
      setTimeout(function() { window.location.reload(); }, 1400);
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Erreur réseau : ' + e);
    });
}

/* ── Actions sur la liste TV/PC ───────────────────────────────── */
function deviceMonitorAction(action, mac, ip, bindingId, fwId, btn) {
  var labels = {
    disconnect: 'Supprimer la session active de ' + (mac || ip) + ' ?',
    block: 'Bloquer ' + (mac || ip) + ' sur MikroTik ?',
    unblock: 'Débloquer ' + (mac || ip) + ' ?'
  };
  if (!confirm(labels[action] || 'Confirmer cette action ?')) return;

  btn.disabled = true;
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Action...';

  var fd = new FormData();
  fd.append('action', action);
  fd.append('mac', mac || '');
  fd.append('ip', ip || '');
  fd.append('binding_id', bindingId || '');
  fd.append('fw_id', fwId || '');
  fd.append('session', <?= json_encode($session ?? '') ?>);

  fetch('./process/device_action.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      if (!j.ok) {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Erreur : ' + (j.error || (j.errors || []).join(', ') || 'inconnue'));
        return;
      }
      var msg = action === 'disconnect' ? 'Session supprimée'
        : (action === 'block' ? 'Appareil bloqué' : 'Appareil débloqué');
      if (j.warning) msg += '\n' + j.warning;
      frToast(msg, action === 'block' ? 'danger' : 'success');
      localStorage.setItem('frActiveTab', 'devices');
      setTimeout(function() { window.location.reload(); }, 1200);
    })
    .catch(function(e) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Erreur réseau : ' + e);
    });
}

/* ── Toast notification ───────────────────────────────────────── */
function frToast(msg, type) {
  var colors = {
    danger:  { bg: '#fef2f2', border: '#fca5a5', color: '#991b1b', icon: 'fa-ban' },
    success: { bg: '#f0fdf4', border: '#86efac', color: '#166534', icon: 'fa-check-circle' },
    info:    { bg: '#ecfeff', border: '#a5f3fc', color: '#075985', icon: 'fa-info-circle' },
  };
  var c = colors[type] || colors.info;
  var t = document.createElement('div');
  t.style.cssText = [
    'position:fixed','bottom:24px','right:24px','z-index:9999',
    'padding:12px 18px','border-radius:10px','box-shadow:0 4px 16px rgba(0,0,0,.15)',
    'font-size:13px','font-weight:600','display:flex','align-items:center','gap:9px',
    'border:1px solid ' + c.border,
    'background:' + c.bg, 'color:' + c.color,
    'max-width:360px','word-break:break-word',
    'transition:opacity .4s','opacity:0'
  ].join(';');
  var icon = document.createElement('i');
  icon.className = 'fa ' + c.icon;
  icon.style.cssText = 'font-size:15px;flex:0 0 auto;';
  var text = document.createElement('span');
  text.textContent = msg;
  t.appendChild(icon);
  t.appendChild(text);
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.style.opacity = '1'; });
  setTimeout(function() {
    t.style.opacity = '0';
    setTimeout(function() { t.parentNode && t.parentNode.removeChild(t); }, 500);
  }, 3500);
}

/* ── Auto-refresh (30s) ───────────────────────────────────────── */
var rSecs = 30;
setInterval(function() {
  rSecs--;
  var e1 = document.getElementById('refreshCountdown');
  var e2 = document.getElementById('refreshCountdown2');
  if (e1) e1.textContent = rSecs;
  if (e2) e2.textContent = rSecs;
  if (rSecs <= 0) window.location.reload();
}, 1000);
</script>
