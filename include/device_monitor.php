<?php
/*
 * device_monitor.php — Surveillance TV/PC connectés via ticket hotspot.
 * Stockage  : logs/hotspot_devices.json
 * Script ROS: MIKHMON-DeviceMonitor  (v1.0)
 */

if (is_file(__DIR__ . '/local_env.php')) {
    include_once __DIR__ . '/local_env.php';
}

if (!function_exists('device_monitor_path')) {

    /* ── URL webhook accessible depuis MikroTik ───────────────────────── */

    function device_monitor_strip_port($host) {
        $host = trim((string)$host);
        if ($host === '') return '';
        if ($host[0] === '[' && strpos($host, ']') !== false) {
            return trim($host, '[]');
        }
        if (substr_count($host, ':') > 1) return $host;
        return explode(':', $host)[0];
    }

    function device_monitor_env($name) {
        $v = getenv($name);
        return $v === false ? '' : trim((string)$v);
    }

    function device_monitor_first_header($names) {
        foreach ($names as $name) {
            if (!empty($_SERVER[$name])) {
                $value = trim((string)$_SERVER[$name]);
                if ($value !== '') return trim(explode(',', $value)[0]);
            }
        }
        return '';
    }

    function device_monitor_request_scheme() {
        $proto = strtolower(device_monitor_first_header(array('HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME')));
        if ($proto === 'http' || $proto === 'https') return $proto;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') return 'https';
        return 'http';
    }

    function device_monitor_is_loopback_host($host) {
        $host = strtolower(device_monitor_strip_port($host));
        return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }

    function device_monitor_is_private_host($host) {
        $host = device_monitor_strip_port($host);
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $long = ip2long($host);
        $ranges = array(
            array('10.0.0.0', '10.255.255.255'),
            array('172.16.0.0', '172.31.255.255'),
            array('192.168.0.0', '192.168.255.255'),
        );
        foreach ($ranges as $range) {
            if ($long >= ip2long($range[0]) && $long <= ip2long($range[1])) return true;
        }
        return false;
    }

    function device_monitor_normalize_host_candidate($host) {
        $host = trim((string)$host);
        if ($host === '' || $host === '0.0.0.0') return '';
        if (strpos($host, '://') !== false) {
            $parsed = parse_url($host);
            $host = isset($parsed['host']) ? $parsed['host'] : '';
        }
        $host = device_monitor_strip_port($host);
        return $host;
    }

    function device_monitor_host_with_port($host, $defaultPort = '') {
        $host = trim((string)$host);
        if ($host === '') return '';
        if (strpos($host, '://') !== false) {
            $parsed = parse_url($host);
            if (empty($parsed['host'])) return '';
            $host = $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
        }
        if (device_monitor_is_loopback_host($host) || $host === '0.0.0.0') return '';
        if (strpos($host, ':') === false && $defaultPort !== '') {
            $host .= ':' . $defaultPort;
        }
        return $host;
    }

    function device_monitor_public_url_from_env($endpoint) {
        $direct = device_monitor_env('MIKHMON_WEBHOOK_URL');
        if ($direct !== '') return $direct;

        $direct = device_monitor_env('MIKHMON_PUBLIC_URL');
        if ($direct !== '') {
            if (strpos($direct, 'fraud_webhook.php') !== false) return $direct;
            $processPath = preg_replace('#^.*?/process/#', '/process/', $endpoint);
            return rtrim($direct, '/') . $processPath;
        }
        return '';
    }

    function device_monitor_app_url_from_webhook($webhookUrl, $session = '') {
        $webhookUrl = trim((string)$webhookUrl);
        if ($webhookUrl === '') return '';
        $marker = '/process/fraud_webhook.php';
        $pos = strpos($webhookUrl, $marker);
        if ($pos === false) return '';
        $base = substr($webhookUrl, 0, $pos);
        $url = rtrim($base, '/') . '/admin.php?id=fraud';
        if ((string)$session !== '') $url .= '&session=' . rawurlencode((string)$session);
        return $url;
    }

    function device_monitor_build_webhook_url($basePath, $iphost = '', $dnsname = '') {
        $scheme = device_monitor_request_scheme();
        $requestHost = device_monitor_first_header(array('HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_HOST'));
        $requestHostOnly = device_monitor_strip_port($requestHost);
        $path = rtrim((string)$basePath, '/');
        if ($path === '/' || $path === '.') $path = '';
        $endpoint = $path . '/process/fraud_webhook.php';

        // Priorité absolue : MIKHMON_WEBHOOK_URL défini dans local_env.php ou l'environnement serveur.
        $envDirect = device_monitor_env('MIKHMON_WEBHOOK_URL');
        if ($envDirect !== '') {
            $envHost = parse_url($envDirect, PHP_URL_HOST) ?: '';
            return array(
                'url'         => $envDirect,
                'host'        => $envHost,
                'source'      => 'env',
                'warnings'    => device_monitor_is_loopback_host($requestHost)
                    ? array('Webhook recommande: MikroTik doit appeler ' . $envHost . '; localhost reste seulement l adresse du navigateur local.')
                    : array(),
                'lan_url'     => '',
                'current_url' => $scheme . '://' . $requestHost . $endpoint,
                'recommended_url' => $envDirect,
                'recommended_host' => $envHost,
                'recommendation' => 'Adresse forcee par MIKHMON_WEBHOOK_URL: utilisez cette URL pour la liaison MikroTik.',
                'browser_recommended_url' => device_monitor_app_url_from_webhook($envDirect),
            );
        }

        $host = $requestHost;
        $source = 'current';
        $warnings = array();

        if (device_monitor_is_loopback_host($requestHost)) {
            $envUrl = device_monitor_public_url_from_env($endpoint);
            if ($envUrl !== '') {
                return array(
                    'url'      => $envUrl,
                    'host'     => parse_url($envUrl, PHP_URL_HOST) ?: '',
                    'source'   => 'env',
                    'warnings' => array('URL webhook prise depuis MIKHMON_WEBHOOK_URL ou MIKHMON_PUBLIC_URL.'),
                    'lan_url'  => '',
                    'current_url' => $scheme . '://' . $requestHost . $endpoint,
                    'recommended_url' => $envUrl,
                    'recommended_host' => parse_url($envUrl, PHP_URL_HOST) ?: '',
                    'recommendation' => 'Adresse configuree dans l environnement: utilisez cette URL pour la liaison MikroTik.',
                    'browser_recommended_url' => device_monitor_app_url_from_webhook($envUrl),
                );
            }

            $defaultPort = device_monitor_env('MIKHMON_WEBHOOK_PORT');
            if ($defaultPort === '') $defaultPort = '8087';

            $envHost = device_monitor_env('MIKHMON_WEBHOOK_HOST');
            $candidate = device_monitor_host_with_port($envHost, $defaultPort);
            if ($candidate === '') $candidate = device_monitor_host_with_port($_SERVER['SERVER_NAME'] ?? '', $defaultPort);
            if ($candidate === '') $candidate = device_monitor_host_with_port($_SERVER['SERVER_ADDR'] ?? '', $defaultPort);
            if ($candidate === '') $candidate = device_monitor_host_with_port($dnsname, $defaultPort);
            if ($candidate === '') $candidate = device_monitor_host_with_port($iphost, $defaultPort);

            if ($candidate !== '') {
                $host = $candidate;
                $source = $envHost !== '' ? 'env-host' : 'auto-host';
            }
        }

        $url = $scheme . '://' . $host . $endpoint;
        if (device_monitor_is_loopback_host($host)) {
            $warnings[] = 'URL locale: MikroTik ne peut pas appeler localhost. Ouvrez MIKHMON avec une IP accessible ou configurez le conteneur.';
        }
        if ($source === 'auto-host') {
            $warnings[] = 'URL auto: host adapte depuis le serveur MIKHMON ou la session routeur.';
        } elseif ($source === 'env-host') {
            $warnings[] = 'URL auto: host adapte depuis MIKHMON_WEBHOOK_HOST.';
        } elseif (!device_monitor_is_loopback_host($host) && !device_monitor_is_private_host($requestHostOnly) && strpos($requestHost, ':8088') !== false) {
            $source = 'public';
        }

        $lanHost = device_monitor_host_with_port($dnsname, device_monitor_env('MIKHMON_WEBHOOK_PORT') ?: '8087');
        if ($lanHost === '') $lanHost = device_monitor_host_with_port($iphost, device_monitor_env('MIKHMON_WEBHOOK_PORT') ?: '8087');

        return array(
            'url'      => $url,
            'host'     => $host,
            'source'   => $source,
            'warnings' => $warnings,
            'lan_url'  => $lanHost !== '' ? 'http://' . $lanHost . $endpoint : '',
            'current_url' => $scheme . '://' . $requestHost . $endpoint,
            'recommended_url' => $url,
            'recommended_host' => device_monitor_strip_port($host),
            'recommendation' => device_monitor_is_loopback_host($requestHost)
                ? 'MIKHMON est ouvert en localhost; le webhook utilise une adresse accessible depuis MikroTik.'
                : 'MIKHMON est ouvert sur une adresse reseau; cette adresse est recommandee pour MikroTik.',
            'browser_recommended_url' => device_monitor_app_url_from_webhook($url),
        );
    }

    /* ── Stockage ──────────────────────────────────────────────────────── */

    function device_monitor_path() {
        return __DIR__ . '/../logs/hotspot_devices.json';
    }

    function device_monitor_load() {
        $path = device_monitor_path();
        if (!file_exists($path)) return array();
        $raw = @file_get_contents($path);
        if (!$raw) return array();
        $d = json_decode($raw, true);
        return is_array($d) ? $d : array();
    }

    function device_monitor_save($devices) {
        $path = device_monitor_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp  = $path . '.tmp';
        $json = json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        return @rename($tmp, $path);
    }

    /* ── Classification d'appareil ────────────────────────────────────── */

    function device_detection($mac, $hostname, $vendor = '', $wifi = array()) {
        $hn  = strtolower((string)$hostname);
        $mac = strtoupper(str_replace(array('-', '.', ':'), '', (string)$mac));
        $pfx = strlen($mac) >= 6 ? substr($mac, 0, 6) : '';
        $vendorLower = strtolower((string)$vendor);
        $evidence = array();

        $result = array(
            'type'       => 'unknown',
            'os'         => 'unknown',
            'confidence' => 25,
            'evidence'   => array('Signal insuffisant: MikroTik ne voit pas toujours l OS exact'),
        );

        $set = function ($type, $os, $confidence, $why) use (&$result) {
            $result = array(
                'type'       => $type,
                'os'         => $os,
                'confidence' => $confidence,
                'evidence'   => is_array($why) ? $why : array($why),
            );
        };

        /* ─ Hostname patterns ─────────────────────────────────────────── */

        // Consoles de jeu (avant TV pour "xbox")
        if (preg_match('/\b(xbox|playstation|ps[345]|nintendo|wii)\b/i', $hn)) {
            $set('console', 'console', 92, 'Nom DHCP console: ' . $hostname);
            return $result;
        }

        // macOS / Apple ordinateurs
        if (preg_match('/\b(macbook|imac|mac-mini|macmini|mac-pro|macpro|mba|mbp)\b/i', $hn)) {
            $set('macos', 'macOS', 96, 'Nom DHCP macOS: ' . $hostname);
            return $result;
        }

        // Ordinateurs Windows/Linux/ChromeOS
        if (preg_match('/\b(desktop|laptop|notebook|thinkpad|ideapad|zenbook|vivobook|
            surface|chromebook|dell-|hp-|lenovo-|asus-|acer-|msi-|gigabyte-|
            win-|pc-|workstation|ubuntu|linux)\b/xi', $hn) ||
            preg_match('/^(DESKTOP-|LAPTOP-|PC-|WIN-|WORKSTATION)/i', (string)$hostname)) {
            $set('pc', 'PC', 94, 'Nom DHCP ordinateur: ' . $hostname);
            return $result;
        }

        // Tablettes / mobiles avant TV, car "android" seul ne veut pas dire TV.
        if (preg_match('/\b(ipad|tablet|kindle|fire.?hd|galaxy.?tab|tab.?[se])\b/i', $hn)) {
            $set('tablet', 'Tablette', 90, 'Nom DHCP tablette: ' . $hostname);
            return $result;
        }
        if (preg_match('/\b(iphone|redmi|xiaomi|huawei|tecno|infinix|itel|
            pixel|oneplus|realme|oppo|vivo|motorola|samsung.?sm|nokia)\b/xi', $hn)) {
            $set('mobile', 'Mobile', 90, 'Nom DHCP mobile: ' . $hostname);
            return $result;
        }

        // TV / Smart TV: on exige un indice fort, pas seulement "LG" ou un OUI ambigu.
        if (preg_match('/\b(bravia|webos|tizen|smart.?tv|android.?tv|hisense|tcl|
            philips|vizio|panasonic|toshiba.?tv|lg.?tv|samsung.?tv|sony.?tv|
            skyworth|coocaa|haier.?tv|changhong|konka|xiaomi.?tv|mibox|
            fire.?tv|firetv|chromecast|appletv|apple.?tv|roku|shield|nvidia.?shield|
            4ktv|oled|qled)\b/xi', $hn)) {
            if (preg_match('/\blg.?tv\b/i', $hn) && stripos($vendorLower, 'lg') !== false) {
                $set('unknown', 'unknown', 45, 'Indice LG-TV ambigu: LG fabrique aussi des ordinateurs');
                return $result;
            }
            $set('tv', 'Smart TV', 94, 'Nom DHCP TV: ' . $hostname);
            return $result;
        }
        if (preg_match('/^tv[\-_]/i', (string)$hostname)) {
            $set('tv', 'Smart TV', 88, 'Nom DHCP commence par TV: ' . $hostname);
            return $result;
        }

        /* ─ OUI Prefix lookup ─────────────────────────────────────────── */

        static $tvOuis = array(
            // Samsung Smart TV
            '0012FB','08373D','10D542','40167E','444E1A','5001BB','5C4979',
            '70F927','78BDBC','84A466','9852B1','A41162','B4EFFA','C048E6',
            'D0667B','E89EB4','FC039F','28987B','F46B8F',
            // Sony BRAVIA
            '00014A','18002D','280DFC','305A3A','402BA1','40B076','501AC5',
            '703509','78843C','7CC709','84C7EA','AC9B0A','C462EA','CCFB65',
            'D4F547','F0BF97',
            // TCL / FFalcon
            '085531','40A48F','4CDD31','508A06','54A493','6C49CB','780F77','A43E51',
            // Hisense
            '000AF5','44CB8B','48EF76','587F66','686CE6','80D21D','9C8ECD','D04FBE',
            // Xiaomi TV / MiBox
            '208200','34CE00','38A4ED','584498','640980','A45046','B40B44','F48E92',
            // Vizio
            '647B6D','84DDE1','A8B86D',
            // Roku
            'B0A737','DC3A5E','B8340D','AC3AEB',
            // Apple TV
            '7C6DF8','9C8BB8','A4C361','58B035',
            // Amazon Fire TV
            '40B7F3','68FCA7','74C246','84D6D0','A002DC','FC65DE',
            // Nvidia Shield
            '4CBD3B','001517',
            // Philips/TP Vision
            '000164','0017E3','245BA7','388B59','74D435',
        );
        if (in_array($pfx, $tvOuis)) {
            $set('tv', 'Smart TV', 70, 'Préfixe MAC souvent TV: ' . $pfx);
            return $result;
        }

        static $computerOuis = array(
            // Intel NIC (nombreux PC)
            '001B21','001517','001F3B','04D4C4','080027',
            // Realtek (desktops communs)
            '00E04C',
            // Dell
            '001422','002170','0024E8','BC305B','D4BED9','F04DA2',
            // HP / HPE
            '0017A4','001CC4','00215A','3CD92B','705A0F',
            // Lenovo / ThinkPad
            '00262D','4CEB42','54E1AD','6C4008','88708C','D0ABD5','E8D4A1',
            // ASUS
            '002618','0C9D92','1CB72C','2CFDA1','38D547','704D7B',
            'AC220B','B06EBF','BCEE7B','D45D64',
            // Acer
            '001A73','002268','002622','3859F9','6C626D',
            // Microsoft Surface
            '281878','7C1E52','985FD3','B831B5','DC41A9','F82819',
            // Apple Mac possibles
            '0016CB','0017F2','001B63','001EC2','0023DF','002500','0026BB',
            '3C0754','3C15C2','40A6D9','4C3275','5C969D','60F81D','8C8590',
        );
        if (in_array($pfx, $computerOuis)) {
            if (stripos($vendorLower, 'apple') !== false) {
                $set('macos', 'macOS probable', 68, 'Préfixe MAC Apple: ' . $pfx);
            } else {
                $set('pc', 'PC probable', 65, 'Préfixe MAC ordinateur: ' . $pfx);
            }
            return $result;
        }

        if (stripos($vendorLower, 'apple') !== false) {
            $set('macos', 'macOS/iOS possible', 55, 'Fabricant Apple, hostname non discriminant');
            return $result;
        }
        if (stripos($vendorLower, 'lg') !== false) {
            $set('pc', 'PC LG probable', 55, 'Fabricant LG sans indice Smart TV fort');
            return $result;
        }

        return $result;
    }

    /**
     * Classifie : 'tv' | 'pc' | 'macos' | 'tablet' | 'mobile' | 'console' | 'unknown'
     */
    function device_classify($mac, $hostname, $vendor = '', $wifi = array()) {
        $d = device_detection($mac, $hostname, $vendor, $wifi);
        return $d['type'];
    }

    /** Label FR selon le type */
    function device_type_label($type) {
        $map = array(
            'tv'       => 'Télévision',
            'pc'       => 'PC Windows/Linux',
            'macos'    => 'macOS',
            'computer' => 'Ordinateur',
            'tablet'   => 'Tablette',
            'mobile'   => 'Téléphone',
            'console'  => 'Console de jeu',
            'unknown'  => 'Inconnu',
        );
        return $map[$type] ?? 'Inconnu';
    }

    /** Classe FA selon le type */
    function device_type_icon($type) {
        $map = array(
            'tv'       => 'fa-tv',
            'pc'       => 'fa-desktop',
            'macos'    => 'fa-laptop',
            'computer' => 'fa-desktop',
            'tablet'   => 'fa-tablet',
            'mobile'   => 'fa-mobile',
            'console'  => 'fa-gamepad',
            'unknown'  => 'fa-question-circle',
        );
        return $map[$type] ?? 'fa-question-circle';
    }

    /** [text, bg] selon le type */
    function device_type_color($type) {
        $map = array(
            'tv'       => array('#6d28d9','#f5f3ff'),
            'pc'       => array('#1d4ed8','#eff6ff'),
            'macos'    => array('#0f172a','#f8fafc'),
            'computer' => array('#1d4ed8','#eff6ff'),
            'tablet'   => array('#0891b2','#ecfeff'),
            'mobile'   => array('#16a34a','#f0fdf4'),
            'console'  => array('#c2410c','#fff7ed'),
            'unknown'  => array('#475569','#f1f5f9'),
        );
        return $map[$type] ?? array('#475569','#f1f5f9');
    }

    /* ── Traitement webhook (mode=devices) ────────────────────────────── */

    function device_monitor_process($postData) {
        require_once __DIR__ . '/oui_lookup.php';

        $now    = date('Y-m-d H:i:s');
        $source = isset($postData['source']) ? trim($postData['source']) : 'mikrotik';

        // Charger l'existant
        $existing = device_monitor_load();
        $byMac    = array();
        foreach ($existing as $d) {
            if (!empty($d['mac'])) $byMac[$d['mac']] = $d;
        }

        // ── Table des hôtes ─────────────────────────────────────────────
        $hostsRaw = isset($postData['hosts']) ? $postData['hosts'] : '';
        $hostMap  = array();
        foreach (explode(',', $hostsRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p    = explode('|', $entry);
            $mac  = strtoupper(trim($p[0] ?? ''));
            $ip   = trim($p[1] ?? '');
            $hn   = trim($p[2] ?? '');
            $auth = trim($p[3] ?? '0');
            if (strlen($mac) < 11) continue;
            $hostMap[$mac] = array('ip' => $ip, 'hostname' => $hn, 'authorized' => $auth === '1');
        }

        // ── WiFi registration + SSID + clé, si le script MikroTik a les droits ──
        $wifiRaw = isset($postData['wifi']) ? $postData['wifi'] : '';
        $wifiMap = array();
        foreach (explode(',', $wifiRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p      = explode('|', $entry);
            $mac    = strtoupper(trim($p[0] ?? ''));
            if (strlen($mac) < 11) continue;
            $wifiMap[$mac] = array(
                'interface' => trim($p[1] ?? ''),
                'ssid'      => trim($p[2] ?? ''),
                'security'  => trim($p[3] ?? ''),
                'wifi_key'  => trim($p[4] ?? ''),
                'signal'    => trim($p[5] ?? ''),
            );
        }

        // ── Sessions actives (user = ticket/voucher) ────────────────────
        $activeRaw = isset($postData['active']) ? $postData['active'] : '';
        $macToUser = array();
        foreach (explode(',', $activeRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p      = explode('|', $entry);
            $user   = trim($p[0] ?? '');
            $mac    = strtoupper(trim($p[1] ?? ''));
            $ip     = trim($p[2] ?? '');
            $uptime = trim($p[3] ?? '');
            if (strlen($mac) < 11) continue;
            $macToUser[$mac] = array('user' => $user, 'ip' => $ip, 'uptime' => $uptime);
            if (!isset($hostMap[$mac])) {
                $hostMap[$mac] = array('ip' => $ip, 'hostname' => '', 'authorized' => true);
            }
        }

        // ── Traiter chaque appareil ──────────────────────────────────────
        $seenMacs = array();
        foreach ($hostMap as $mac => $host) {
            $hn     = $host['hostname'];
            $ip     = $host['ip'];
            $auth   = $host['authorized'];
            $vendor = oui_vendor($mac);
            $label  = oui_device_label($mac, $hn);
            $wifi   = $wifiMap[$mac] ?? array();
            $detect = device_detection($mac, $hn, $vendor, $wifi);
            $type   = $detect['type'];
            $uInfo  = $macToUser[$mac] ?? null;
            $seenMacs[$mac] = true;

            if (isset($byMac[$mac])) {
                $byMac[$mac]['ip']         = $ip;
                $byMac[$mac]['hostname']   = $hn;
                $byMac[$mac]['type']       = $type;
                $byMac[$mac]['os']         = $detect['os'];
                $byMac[$mac]['confidence'] = $detect['confidence'];
                $byMac[$mac]['evidence']   = $detect['evidence'];
                $byMac[$mac]['wifi']       = $wifi;
                $byMac[$mac]['vendor']     = $vendor;
                $byMac[$mac]['label']      = $label;
                $byMac[$mac]['authorized'] = $auth;
                $byMac[$mac]['last_seen']  = $now;
                $byMac[$mac]['source']     = $source;
                if ($uInfo) {
                    $byMac[$mac]['user']   = $uInfo['user'];
                    $byMac[$mac]['uptime'] = $uInfo['uptime'];
                }
                if (($byMac[$mac]['status'] ?? '') !== 'blocked') {
                    $byMac[$mac]['status'] = $auth ? 'active' : 'seen';
                }
            } else {
                $byMac[$mac] = array(
                    'mac'        => $mac,
                    'ip'         => $ip,
                    'hostname'   => $hn,
                    'type'       => $type,
                    'os'         => $detect['os'],
                    'confidence' => $detect['confidence'],
                    'evidence'   => $detect['evidence'],
                    'wifi'       => $wifi,
                    'vendor'     => $vendor,
                    'label'      => $label,
                    'authorized' => $auth,
                    'user'       => $uInfo ? $uInfo['user'] : '',
                    'uptime'     => $uInfo ? $uInfo['uptime'] : '',
                    'first_seen' => $now,
                    'last_seen'  => $now,
                    'source'     => $source,
                    'status'     => $auth ? 'active' : 'seen',
                    'blocked'    => false,
                    'binding_id' => '',
                    'fw_id'      => '',
                );
            }
        }

        // Trier : actifs d'abord, puis par type suspect (tv, computer, console…)
        $typeOrder = array('tv'=>0,'pc'=>1,'macos'=>2,'computer'=>3,'console'=>4,'tablet'=>5,'mobile'=>6,'unknown'=>7);
        $list = array_values($byMac);
        usort($list, function ($a, $b) use ($typeOrder) {
            $sa = ($a['status'] ?? '') === 'active' ? 0 : 1;
            $sb = ($b['status'] ?? '') === 'active' ? 0 : 1;
            if ($sa !== $sb) return $sa - $sb;
            $ta = $typeOrder[$a['type'] ?? 'unknown'] ?? 5;
            $tb = $typeOrder[$b['type'] ?? 'unknown'] ?? 5;
            return $ta - $tb;
        });

        device_monitor_save($list);
        return count($list);
    }

    function device_monitor_update_block($mac, $blocked, $bindingId = '', $fwId = '') {
        $list = device_monitor_load();
        $now  = date('Y-m-d H:i:s');
        foreach ($list as &$d) {
            if (($d['mac'] ?? '') !== $mac) continue;
            $d['blocked']    = (bool)$blocked;
            $d['blocked_at'] = $blocked ? $now : null;
            $d['binding_id'] = $bindingId;
            $d['fw_id']      = $fwId;
            $d['status']     = $blocked ? 'blocked' : 'active';
            break;
        }
        unset($d);
        return device_monitor_save($list);
    }

    /* ── Génération du script RouterOS (compatible terminal RouterOS) ─── */

    function device_monitor_ros_quote($value) {
        return '"' . str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\"', '', ''), (string)$value) . '"';
    }

    function device_monitor_build_script($url, $key, $session) {
        $nl = "\n";
        $n  = 'MIKHMON-DeviceMonitor';
        $s  = '# ============================================================' . $nl;
        $s .= '# ' . $n . ' v1.2 - SafeLink Africa' . $nl;
        $s .= '# Detecte macOS, PC, TV, tablettes via ticket hotspot' . $nl;
        $s .= '# Rapport envoye toutes les 10 minutes vers MIKHMON' . $nl;
        $s .= '# Compatible terminal RouterOS v6/v7' . $nl;
        $s .= '# ============================================================' . $nl . $nl;

        $s .= '# Configuration' . $nl;
        $s .= ':local mikhmonUrl ' . device_monitor_ros_quote($url) . $nl;
        $s .= ':local apiKey ' . device_monitor_ros_quote($key) . $nl;
        $s .= ':local sessionName ' . device_monitor_ros_quote($session) . $nl;
        $s .= ':local sourceId ' . device_monitor_ros_quote($session) . $nl . $nl;

        $s .= '# Table des hotes hotspot: MAC|IP|hostname|authorized' . $nl;
        $s .= ':local hostPart ""' . $nl;
        $s .= ':foreach h in=[/ip hotspot host print as-value] do={' . $nl;
        $s .= '    :local mac ""' . $nl;
        $s .= '    :local ip ""' . $nl;
        $s .= '    :local hn ""' . $nl . '    :local auth "0"' . $nl;
        $s .= '    :do { :set mac ($h->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set ip ($h->"address") } on-error={}' . $nl;
        $s .= '    :do { :set hn ($h->"host-name") } on-error={}' . $nl;
        $s .= '    :do { :if (($h->"authorized") = "true") do={ :set auth "1" } } on-error={}' . $nl;
        $s .= '    :if ([:len $mac] > 0) do={' . $nl;
        $s .= '        :if ([:len $hostPart] < 5500) do={' . $nl;
        $s .= '            :set hostPart ($hostPart . $mac . "|" . $ip . "|" . $hn . "|" . $auth . ",")' . $nl;
        $s .= '        }' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Sessions actives: ticket|MAC|IP|uptime' . $nl;
        $s .= ':local activePart ""' . $nl;
        $s .= ':foreach a in=[/ip hotspot active print as-value] do={' . $nl;
        $s .= '    :local u ""' . $nl;
        $s .= '    :local m ""' . $nl;
        $s .= '    :local ip ""' . $nl;
        $s .= '    :local up ""' . $nl;
        $s .= '    :do { :set u ($a->"user") } on-error={}' . $nl;
        $s .= '    :do { :set m ($a->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set ip ($a->"address") } on-error={}' . $nl;
        $s .= '    :do { :set up ($a->"uptime") } on-error={}' . $nl;
        $s .= '    :if ([:len $activePart] < 4000) do={' . $nl;
        $s .= '        :set activePart ($activePart . $u . "|" . $m . "|" . $ip . "|" . $up . ",")' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# WiFi associe: MAC|interface|ssid|security-profile|wifi-key|signal' . $nl;
        $s .= ':local wifiPart ""' . $nl;
        // RouterOS v6 — /interface wireless (pre-fetch evite \'-> a profondeur 3 dans terminal)
        $s .= ':local wirelessRows {}' . $nl;
        $s .= ':do { :set wirelessRows [/interface wireless registration-table print as-value] } on-error={}' . $nl;
        $s .= ':foreach r in=$wirelessRows do={' . $nl;
        $s .= '    :local mac ""' . $nl;
        $s .= '    :local iface ""' . $nl;
        $s .= '    :local ssid ""' . $nl;
        $s .= '    :local sec ""' . $nl;
        $s .= '    :local key ""' . $nl;
        $s .= '    :local sig ""' . $nl;
        $s .= '    :do { :set mac ($r->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set iface ($r->"interface") } on-error={}' . $nl;
        $s .= '    :do { :set sig ($r->"signal-strength") } on-error={}' . $nl;
        $s .= '    :do { :set ssid [/interface wireless get [find where name=$iface] ssid] } on-error={}' . $nl;
        $s .= '    :do { :set sec [/interface wireless get [find where name=$iface] security-profile] } on-error={}' . $nl;
        $s .= '    :do { :set key [/interface wireless security-profiles get [find where name=$sec] wpa2-pre-shared-key] } on-error={}' . $nl;
        $s .= '    :if ([:len $key] = 0) do={ :do { :set key [/interface wireless security-profiles get [find where name=$sec] wpa-pre-shared-key] } on-error={} }' . $nl;
        $s .= '    :if ([:len $mac] > 0) do={' . $nl;
        $s .= '        :if ([:len $wifiPart] < 4000) do={' . $nl;
        $s .= '            :set wifiPart ($wifiPart . $mac . "|" . $iface . "|" . $ssid . "|" . $sec . "|" . $key . "|" . $sig . ",")' . $nl;
        $s .= '        }' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl;
        // RouterOS v7 — /interface wifi (pre-fetch idem)
        $s .= ':local wifiRows {}' . $nl;
        $s .= ':do { :set wifiRows [/interface wifi registration-table print as-value] } on-error={}' . $nl;
        $s .= ':foreach r in=$wifiRows do={' . $nl;
        $s .= '    :local mac ""' . $nl;
        $s .= '    :local iface ""' . $nl;
        $s .= '    :local ssid ""' . $nl;
        $s .= '    :local sec ""' . $nl;
        $s .= '    :local key ""' . $nl;
        $s .= '    :local sig ""' . $nl;
        $s .= '    :do { :set mac ($r->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set iface ($r->"interface") } on-error={}' . $nl;
        $s .= '    :do { :set sig ($r->"signal") } on-error={}' . $nl;
        $s .= '    :do { :set ssid [/interface wifi get [find where name=$iface] ssid] } on-error={}' . $nl;
        $s .= '    :do { :set sec [/interface wifi get [find where name=$iface] security] } on-error={}' . $nl;
        $s .= '    :do { :set key [/interface wifi security get [find where name=$sec] passphrase] } on-error={}' . $nl;
        $s .= '    :if ([:len $mac] > 0) do={' . $nl;
        $s .= '        :if ([:len $wifiPart] < 4000) do={' . $nl;
        $s .= '            :set wifiPart ($wifiPart . $mac . "|" . $iface . "|" . $ssid . "|" . $sec . "|" . $key . "|" . $sig . ",")' . $nl;
        $s .= '        }' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Envoi vers MIKHMON' . $nl;
        $s .= ':local postData ""' . $nl;
        $s .= ':set postData ("key=" . $apiKey)' . $nl;
        $s .= ':set postData ($postData . "&session=" . $sessionName)' . $nl;
        $s .= ':set postData ($postData . "&source=" . $sourceId)' . $nl;
        $s .= ':set postData ($postData . "&mode=devices")' . $nl;
        $s .= ':set postData ($postData . "&hosts=" . $hostPart)' . $nl;
        $s .= ':set postData ($postData . "&active=" . $activePart)' . $nl;
        $s .= ':set postData ($postData . "&wifi=" . $wifiPart)' . $nl;
        $s .= ':do {' . $nl;
        $s .= '    /tool fetch url=$mikhmonUrl http-method=post http-data=$postData output=none' . $nl;
        $s .= '    :log info "[MIKHMON-DeviceMonitor] rapport envoye"' . $nl;
        $s .= '} on-error={ :log warning "[MIKHMON-DeviceMonitor] echec envoi" }' . $nl;

        return $s;
    }

} // end function_exists guard
