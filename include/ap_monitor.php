<?php
/*
 * ap_monitor.php — Détection des routeurs configurés en point d'accès.
 * Objectif : afficher les IP statiques de gestion qui ne viennent pas du DHCP
 * MikroTik, avec marque/modèle si les voisins MNDP/LLDP/CDP les exposent.
 */

if (!function_exists('ap_monitor_scan')) {

    function ap_monitor_ip_long($ip) {
        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
        $long = ip2long($ip);
        return $long === false ? null : sprintf('%u', $long);
    }

    function ap_monitor_ip_in_range($ip, $start, $end) {
        $i = ap_monitor_ip_long($ip);
        $s = ap_monitor_ip_long($start);
        $e = ap_monitor_ip_long($end);
        if ($i === null || $s === null || $e === null) return false;
        return $i >= $s && $i <= $e;
    }

    function ap_monitor_parse_pool_ranges($ranges) {
        $out = array();
        foreach (explode(',', (string)$ranges) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (strpos($part, '-') !== false) {
                [$start, $end] = array_map('trim', explode('-', $part, 2));
            } else {
                $start = $part;
                $end = $part;
            }
            if (filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $out[] = array($start, $end);
            }
        }
        return $out;
    }

    function ap_monitor_ip_in_ranges($ip, $ranges) {
        foreach ($ranges as $r) {
            if (ap_monitor_ip_in_range($ip, $r[0], $r[1])) return true;
        }
        return false;
    }

    function ap_monitor_is_192_management_ip($ip) {
        $parts = explode('.', (string)$ip);
        return count($parts) === 4 && $parts[0] === '192' && $parts[1] === '168';
    }

    function ap_monitor_is_link_local_or_reserved($ip) {
        $long = ap_monitor_ip_long($ip);
        if ($long === null) return true;
        $checks = array(
            array('0.0.0.0', '0.255.255.255'),
            array('127.0.0.0', '127.255.255.255'),
            array('169.254.0.0', '169.254.255.255'),
            array('224.0.0.0', '239.255.255.255'),
            array('255.255.255.255', '255.255.255.255'),
        );
        foreach ($checks as $r) {
            if (ap_monitor_ip_in_range($ip, $r[0], $r[1])) return true;
        }
        return false;
    }

    function ap_monitor_router_brand($vendor, $identity, $platform) {
        $text = strtolower($vendor . ' ' . $identity . ' ' . $platform);
        $brands = array(
            'mikrotik' => 'MikroTik',
            'tp-link' => 'TP-Link',
            'tplink' => 'TP-Link',
            'ubiquiti' => 'Ubiquiti',
            'unifi' => 'Ubiquiti UniFi',
            'mercusys' => 'Mercusys',
            'tenda' => 'Tenda',
            'd-link' => 'D-Link',
            'dlink' => 'D-Link',
            'linksys' => 'Linksys',
            'netis' => 'Netis',
            'totolink' => 'TOTOLINK',
            'wavlink' => 'Wavlink',
            'xiaomi' => 'Xiaomi',
            'huawei' => 'Huawei',
            'zte' => 'ZTE',
            'cisco' => 'Cisco',
            'zyxel' => 'Zyxel',
            'ruijie' => 'Ruijie',
            'cambium' => 'Cambium',
            'grandstream' => 'Grandstream',
        );
        foreach ($brands as $needle => $brand) {
            if (strpos($text, $needle) !== false) return $brand;
        }
        return $vendor ?: 'Routeur/AP inconnu';
    }

    function ap_monitor_score($vendor, $identity, $platform, $isStatic) {
        $text = strtolower($vendor . ' ' . $identity . ' ' . $platform);
        $score = $isStatic ? 45 : 15;
        $evidence = array();
        if ($isStatic) $evidence[] = 'IP non attribuée par le DHCP MikroTik';

        if (preg_match('/router|routeur|access.?point|\\bap\\b|wlan|wireless|bridge|repeater|extender|cpe/i', $text)) {
            $score += 35;
            $evidence[] = 'Nom/plateforme indique un routeur ou point d accès';
        }
        if (preg_match('/mikrotik|tp-?link|ubiquiti|unifi|mercusys|tenda|d-?link|linksys|netis|totolink|wavlink|huawei|zte|cisco|zyxel|ruijie|cambium|grandstream|xiaomi/i', $text)) {
            $score += 25;
            $evidence[] = 'Fabricant réseau reconnu';
        }
        if ($identity !== '' || $platform !== '') {
            $score += 10;
            $evidence[] = 'Détecté dans les voisins MikroTik';
        }

        return array(min(99, $score), $evidence);
    }

    function ap_monitor_should_include($ip, $fromDhcp, $inPool, $score, &$evidence, &$risk, &$riskLabel) {
        $is192Management = ap_monitor_is_192_management_ip($ip);

        if ($fromDhcp) {
            if ($score < 75) return false;
            $risk = 'dhcp_router';
            $riskLabel = 'Routeur/AP via DHCP';
            $evidence[] = 'Bail DHCP avec signature routeur/AP forte';
            return true;
        }

        if ($inPool) {
            if ($score < 75) return false;
            $risk = 'pool_conflict';
            $riskLabel = 'AP dans plage DHCP';
            $evidence[] = 'IP statique placée dans le pool DHCP MikroTik';
            return true;
        }

        if ($is192Management) {
            $risk = 'ap_static';
            $riskLabel = 'IP AP probable';
            $evidence[] = 'Adresse de gestion typique 192.168.x.x hors DHCP';
            return true;
        }

        if ($score >= 60) {
            $risk = 'static_ok';
            $riskLabel = 'IP statique hors DHCP';
            return true;
        }

        return false;
    }

    function ap_monitor_scan($API) {
        require_once __DIR__ . '/oui_lookup.php';
        if (!$API) return array('items' => array(), 'stats' => array(), 'error' => 'api_unavailable');

        $leases = $arp = $neighbors = $addresses = $pools = array();
        try { $leases = $API->comm('/ip/dhcp-server/lease/print'); } catch (Exception $e) {}
        try { $arp = $API->comm('/ip/arp/print'); } catch (Exception $e) {}
        try { $neighbors = $API->comm('/ip/neighbor/print'); } catch (Exception $e) {}
        try { $addresses = $API->comm('/ip/address/print'); } catch (Exception $e) {}
        try { $pools = $API->comm('/ip/pool/print'); } catch (Exception $e) {}

        $dhcpIps = array();
        $dhcpMacs = array();
        foreach ((array)$leases as $l) {
            foreach (array('address', 'active-address') as $k) {
                if (!empty($l[$k])) $dhcpIps[$l[$k]] = true;
            }
            foreach (array('mac-address', 'active-mac-address') as $k) {
                if (!empty($l[$k])) $dhcpMacs[strtoupper($l[$k])] = true;
            }
        }

        $localIps = array();
        foreach ((array)$addresses as $a) {
            if (empty($a['address'])) continue;
            $ip = explode('/', $a['address'])[0];
            if ($ip !== '') $localIps[$ip] = true;
        }

        $poolRanges = array();
        foreach ((array)$pools as $p) {
            if (!empty($p['ranges'])) {
                $poolRanges = array_merge($poolRanges, ap_monitor_parse_pool_ranges($p['ranges']));
            }
        }

        $neighborsByMac = array();
        $neighborsByIp = array();
        foreach ((array)$neighbors as $n) {
            $mac = strtoupper($n['mac-address'] ?? '');
            $ip = $n['address'] ?? '';
            if ($mac !== '') $neighborsByMac[$mac] = $n;
            if ($ip !== '') $neighborsByIp[$ip] = $n;
        }

        $items = array();
        $seen = array();
        foreach ((array)$arp as $row) {
            $ip = trim($row['address'] ?? '');
            $mac = strtoupper(trim($row['mac-address'] ?? ''));
            if ($ip === '' || $mac === '' || isset($localIps[$ip]) || ap_monitor_is_link_local_or_reserved($ip)) continue;
            $seenKey = $ip . '|' . $mac;
            if (isset($seen[$seenKey])) continue;
            $seen[$seenKey] = true;

            $fromDhcp = isset($dhcpIps[$ip]) || isset($dhcpMacs[$mac]);
            $neighbor = $neighborsByMac[$mac] ?? ($neighborsByIp[$ip] ?? array());
            $vendor = oui_vendor($mac);
            $identity = $neighbor['identity'] ?? ($row['host-name'] ?? '');
            $platform = $neighbor['platform'] ?? '';
            [$score, $evidence] = ap_monitor_score($vendor, $identity, $platform, !$fromDhcp);
            $inPool = ap_monitor_ip_in_ranges($ip, $poolRanges);
            $risk = '';
            $riskLabel = '';
            if (!ap_monitor_should_include($ip, $fromDhcp, $inPool, $score, $evidence, $risk, $riskLabel)) continue;
            if ($risk === 'ap_static' && $score < 62) $score = 62;

            $items[] = array(
                'ip' => $ip,
                'mac' => $mac,
                'brand' => ap_monitor_router_brand($vendor, $identity, $platform),
                'vendor' => $vendor,
                'model' => $platform ?: ($identity ?: 'Modèle non exposé'),
                'identity' => $identity,
                'platform' => $platform,
                'interface' => $row['interface'] ?? ($neighbor['interface'] ?? ''),
                'dynamic' => ($row['dynamic'] ?? '') === 'true',
                'from_dhcp' => $fromDhcp,
                'in_dhcp_pool' => $inPool,
                'risk' => $risk,
                'risk_label' => $riskLabel,
                'confidence' => $score,
                'evidence' => $evidence,
                'last_seen' => date('Y-m-d H:i:s'),
            );
        }

        usort($items, function ($a, $b) {
            $ra = $a['risk'] === 'pool_conflict' ? 0 : 1;
            $rb = $b['risk'] === 'pool_conflict' ? 0 : 1;
            if ($ra !== $rb) return $ra - $rb;
            return strcmp($a['ip'], $b['ip']);
        });

        return array(
            'items' => $items,
            'stats' => array(
                'total' => count($items),
                'conflicts' => count(array_filter($items, fn($i) => $i['risk'] === 'pool_conflict')),
                'static_ok' => count(array_filter($items, fn($i) => in_array($i['risk'], array('static_ok', 'ap_static'), true))),
                'pools' => $poolRanges,
            ),
            'error' => '',
        );
    }

    function ap_rogue_log_path() {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/rogue_dhcp.json';
    }

    function ap_rogue_load() {
        $file = ap_rogue_log_path();
        if (!is_file($file)) return array();
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : array();
    }

    function ap_rogue_save($items) {
        return (bool)@file_put_contents(ap_rogue_log_path(), json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    function ap_rogue_process_webhook($postData) {
        require_once __DIR__ . '/oui_lookup.php';
        $rogueMac = strtoupper(trim((string)($postData['rogue_mac'] ?? $postData['unknown_server'] ?? $postData['unknown-server'] ?? '')));
        $rogueIp  = trim((string)($postData['rogue_ip'] ?? $postData['server_ip'] ?? ''));
        if ($rogueMac === '') return 0;

        $now = date('Y-m-d H:i:s');
        $items = ap_rogue_load();
        $updated = false;
        foreach ($items as &$item) {
            if (($item['rogue_mac'] ?? '') !== $rogueMac) continue;
            $item['last_seen'] = $now;
            $item['count'] = (int)($item['count'] ?? 1) + 1;
            $item['rogue_ip'] = $rogueIp ?: ($item['rogue_ip'] ?? '');
            $item['interface'] = trim((string)($postData['interface'] ?? ($item['interface'] ?? '')));
            $item['valid_mac'] = strtoupper(trim((string)($postData['valid_mac'] ?? ($item['valid_mac'] ?? ''))));
            $item['source'] = trim((string)($postData['source'] ?? ($item['source'] ?? 'mikrotik')));
            $updated = true;
            break;
        }
        unset($item);

        if (!$updated) {
            $items[] = array(
                'rogue_mac' => $rogueMac,
                'rogue_ip' => $rogueIp,
                'vendor' => oui_vendor($rogueMac),
                'interface' => trim((string)($postData['interface'] ?? '')),
                'valid_mac' => strtoupper(trim((string)($postData['valid_mac'] ?? ''))),
                'session' => trim((string)($postData['session'] ?? '')),
                'source' => trim((string)($postData['source'] ?? 'mikrotik')),
                'first_seen' => $now,
                'last_seen' => $now,
                'count' => 1,
                'status' => 'new',
            );
        }

        usort($items, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
        ap_rogue_save(array_slice($items, 0, 100));
        return 1;
    }

    function ap_rogue_escape_ros_inline($value) {
        return str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\"', '', ''), (string)$value);
    }

    function ap_rogue_build_on_alert($url, $key, $session, $listenInterface = '', $validMac = '') {
        $listenInterface = trim((string)$listenInterface);
        if ($listenInterface === '') $listenInterface = 'bridge';
        $validMac = trim((string)$validMac);
        if ($validMac === '') $validMac = 'MAC_DU_BRIDGE_MIKROTIK';

        return ':local alertId [/ip dhcp-server alert find where comment="MIKHMON-RogueDHCP"]; '
            . ':local validMac "' . ap_rogue_escape_ros_inline($validMac) . '"; '
            . ':local rogueMac ""; '
            . ':if ([:len $alertId] > 0) do={ :set rogueMac [/ip dhcp-server alert get $alertId unknown-server] }; '
            . ':if ([:len $rogueMac] = 0) do={ :set rogueMac "unknown" }; '
            . ':if (($rogueMac != $validMac) && ($rogueMac != "unknown")) do={ '
            . ':local postData ""; '
            . ':set postData ("key=' . ap_rogue_escape_ros_inline($key)
            . '&session=' . ap_rogue_escape_ros_inline($session)
            . '&source=' . ap_rogue_escape_ros_inline($session)
            . '&mode=rogue_dhcp&interface=' . ap_rogue_escape_ros_inline($listenInterface)
            . '&valid_mac=' . ap_rogue_escape_ros_inline($validMac)
            . '&rogue_mac=" . $rogueMac); '
            . '/tool fetch url="' . ap_rogue_escape_ros_inline($url) . '" http-method=post http-data=$postData output=none; '
            . ':log warning ("[MIKHMON-RogueDHCP] serveur DHCP rogue MAC=" . $rogueMac) '
            . '} else={ :log info ("[MIKHMON-RogueDHCP] serveur DHCP valide ignore MAC=" . $rogueMac) }';
    }

    function ap_rogue_build_alert_fields($url, $key, $session, $listenInterface = '', $validMac = '') {
        $listenInterface = trim((string)$listenInterface);
        if ($listenInterface === '') $listenInterface = 'bridge';
        $validMac = trim((string)$validMac);
        if ($validMac === '') $validMac = 'MAC_DU_BRIDGE_MIKROTIK';

        return array(
            'interface' => $listenInterface,
            'valid_server' => $validMac,
            'alert_timeout' => '1h',
            'on_alert' => ap_rogue_build_on_alert($url, $key, $session, $listenInterface, $validMac),
            'comment' => 'MIKHMON-RogueDHCP',
        );
    }

    function ap_rogue_pick_bridge_valid_mac($bridges, $preferredInterface = '') {
        $choices = array();
        foreach ((array)$bridges as $bridge) {
            $name = trim((string)($bridge['name'] ?? ''));
            $mac = trim((string)($bridge['mac-address'] ?? ($bridge['admin-mac'] ?? '')));
            if ($name !== '' && $mac !== '') {
                $choices[$name] = $mac;
            }
        }
        if (empty($choices)) {
            return array('interface' => '', 'mac' => '');
        }

        foreach (array($preferredInterface, 'HOTSPOT', 'bridge') as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && isset($choices[$candidate])) {
                return array('interface' => $candidate, 'mac' => $choices[$candidate]);
            }
        }

        $first = (string)array_key_first($choices);
        return array('interface' => $first, 'mac' => $choices[$first]);
    }

    function ap_rogue_build_script($url, $key, $session, $listenInterface = '') {
        if (function_exists('device_monitor_ros_quote')) {
            $quote = 'device_monitor_ros_quote';
        } else {
            $quote = function ($value) {
                return '"' . str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\"', '', ''), (string)$value) . '"';
            };
        }

        $listenInterface = trim((string)$listenInterface);
        if ($listenInterface === '') $listenInterface = 'bridge';
        $nl = "\n";
        $s  = '# ============================================================' . $nl;
        $s .= '# MIKHMON Rogue DHCP Guard - SafeLink Africa' . $nl;
        $s .= '# Utilise /ip dhcp-server alert pour detecter un serveur DHCP inconnu' . $nl;
        $s .= '# Valid-server = MAC du bridge MikroTik' . $nl;
        $s .= '# ============================================================' . $nl . $nl;
        $s .= ':local mikhmonUrl ' . $quote($url) . $nl;
        $s .= ':local apiKey ' . $quote($key) . $nl;
        $s .= ':local sessionName ' . $quote($session) . $nl;
        $s .= ':local listenInterface ' . $quote($listenInterface) . $nl;
        $s .= ':local bridgeName $listenInterface' . $nl;
        $s .= ':local validMac ""' . $nl;
        # FIX: priorite au bridge surveille, ex. HOTSPOT, puis fallbacks.
        $s .= ':do { :set validMac [/interface bridge get $bridgeName mac-address] } on-error={}' . $nl;
        $s .= ':if ([:len $validMac] = 0) do={' . $nl;
        $s .= '    :do { :set bridgeName "HOTSPOT" } on-error={}' . $nl;
        $s .= '    :do { :set validMac [/interface bridge get $bridgeName mac-address] } on-error={}' . $nl;
        $s .= '}' . $nl;
        # FIX: fallback sans opérateur -> à 3 niveaux
        $s .= ':if ([:len $validMac] = 0) do={' . $nl;
        $s .= '    :do { :set bridgeName "bridge" } on-error={}' . $nl;
        $s .= '    :do { :set validMac [/interface bridge get $bridgeName mac-address] } on-error={}' . $nl;
        $s .= '}' . $nl;
        $s .= ':if ([:len $validMac] = 0) do={' . $nl;
        $s .= '    :do { :set bridgeName [/interface bridge get 0 name] } on-error={}' . $nl;
        $s .= '    :do { :set validMac [/interface bridge get 0 mac-address] } on-error={}' . $nl;
        $s .= '}' . $nl;
        $s .= ':if ([:len $validMac] = 0) do={ :log error "[MIKHMON-RogueDHCP] MAC bridge introuvable"; :error "bridge-mac-not-found" }' . $nl;
        # FIX: [:len [/interface find ...]] -> séparer en deux instructions
        $s .= ':local ifaceCheck [/interface find where name=$listenInterface]' . $nl;
        $s .= ':if ([:len $ifaceCheck] = 0) do={ :set listenInterface $bridgeName }' . $nl;
        $s .= ':local onAlert ""' . $nl;
        $s .= ':set onAlert (":local alertId [/ip dhcp-server alert find where comment=\\\"MIKHMON-RogueDHCP\\\"]; :local validMac \\\"" . $validMac . "\\\"; :local rogueMac \\\"\\\"; :if ([:len \\$alertId] > 0) do={ :set rogueMac [/ip dhcp-server alert get \\$alertId unknown-server] }; :if ([:len \\$rogueMac] = 0) do={ :set rogueMac \\\"unknown\\\" }; :if ((\\$rogueMac != \\$validMac) && (\\$rogueMac != \\\"unknown\\\")) do={ :local postData \\\"\\\"; :set postData (\\\"key=' . addcslashes((string)$key, '\\"') . '&session=' . addcslashes((string)$session, '\\"') . '&source=' . addcslashes((string)$session, '\\"') . '&mode=rogue_dhcp&interface=" . $listenInterface . "&valid_mac=" . $validMac . "&rogue_mac=\\\" . \\$rogueMac); /tool fetch url=\\\"' . addcslashes((string)$url, '\\"') . '\\\" http-method=post http-data=\\$postData output=none; :log warning (\\\"[MIKHMON-RogueDHCP] serveur DHCP rogue MAC=\\\" . \\$rogueMac) } else={ :log info (\\\"[MIKHMON-RogueDHCP] serveur DHCP valide ignore MAC=\\\" . \\$rogueMac) }")' . $nl;
        $s .= ':do { /ip dhcp-server alert remove [find where comment="MIKHMON-RogueDHCP"] } on-error={}' . $nl;
        $s .= '/ip dhcp-server alert add interface=$listenInterface valid-server=$validMac alert-timeout=1h on-alert=$onAlert comment="MIKHMON-RogueDHCP"' . $nl;
        $s .= ':log info ("[MIKHMON-RogueDHCP] active sur " . $listenInterface . " valid-server=" . $validMac)' . $nl;
        return $s;
    }
}
