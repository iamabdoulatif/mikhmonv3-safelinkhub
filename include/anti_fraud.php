<?php
/*
 * Anti-fraud module — détection des codes/tickets utilisés sur plusieurs MAC.
 * Persistance dans logs/fraud.json
 * Webhook MikroTik → MIKHMON : MIKHMON_FRAUD_API_KEY ou logs/fraud_api_key.txt
 */

if (!function_exists('anti_fraud_log_path')) {
    function anti_fraud_log_path() {
        return __DIR__ . '/../logs/fraud.json';
    }

    /* ── API Key (webhook MikroTik → MIKHMON) ─────────────────────────────── */

    function anti_fraud_key_path() {
        return __DIR__ . '/../logs/fraud_api_key.txt';
    }

    function anti_fraud_env_key() {
        $key = getenv('MIKHMON_FRAUD_API_KEY');
        if (!is_string($key)) return '';
        $key = trim($key);
        return strlen($key) >= 32 ? $key : '';
    }

    /**
     * Retourne la clé API du webhook (la génère si absente).
     */
    function anti_fraud_get_api_key() {
        $envKey = anti_fraud_env_key();
        if ($envKey !== '') return $envKey;

        $path = anti_fraud_key_path();
        if (file_exists($path)) {
            $k = trim((string)@file_get_contents($path));
            if (strlen($k) >= 32) return $k;
        }
        // Génère une clé aléatoire de 48 caractères hex
        $key = bin2hex(random_bytes(24));
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($path, $key, LOCK_EX);
        @chmod($path, 0600);
        return $key;
    }

    function anti_fraud_validate_key($candidate) {
        $stored = anti_fraud_get_api_key();
        return hash_equals($stored, (string)$candidate);
    }

    /* ── Webhook data processor (push depuis MikroTik) ───────────────────── */

    /**
     * Traite les données brutes envoyées par le script RouterOS.
     *
     * Format attendu (POST) :
     *   active  = "user|MAC|IP,user|MAC|IP,"
     *   cookies = "user|MAC,user|MAC,"
     *   hosts   = "MAC|IP|hostname|authorized,..."  ← table des hôtes MikroTik
     *   logs    = "message|time;message|time;"      ← logs invalid MAC
     *   source  = identifiant du routeur (ex: "ROUTEUR-1")
     *
     * Retourne le nombre d'incidents (re)détectés.
     */
    function anti_fraud_process_webhook($postData) {
        require_once __DIR__ . '/oui_lookup.php';

        $now    = date('Y-m-d H:i:s');
        $source = isset($postData['source']) ? trim($postData['source']) : 'mikrotik';

        // ── 0. Table des hôtes MikroTik → map IP→device + MAC→device ────────
        // Format: "MAC|IP|hostname|authorized,..."
        $ipToDevice  = array(); // ip  → [mac, hostname, vendor, label]
        $macToDevice = array(); // mac → [hostname, vendor, label]

        $hostsRaw = isset($postData['hosts']) ? $postData['hosts'] : '';
        foreach (explode(',', $hostsRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p    = explode('|', $entry);
            $mac  = strtoupper(trim($p[0] ?? ''));
            $ip   = trim($p[1] ?? '');
            $hn   = trim($p[2] ?? '');           // hostname
            $auth = trim($p[3] ?? '0');           // "1" = autorisé, "0" = non
            if (strlen($mac) < 11) continue;
            $vendor = oui_vendor($mac);
            $label  = oui_device_label($mac, $hn);
            $dev    = array(
                'mac'        => $mac,
                'hostname'   => $hn,
                'vendor'     => $vendor,
                'label'      => $label,
                'authorized' => $auth === '1',
            );
            if ($ip !== '') $ipToDevice[$ip] = $dev;
            $macToDevice[$mac] = $dev;
        }

        // ── 1. Sessions actives → userMacs ──────────────────────────────────
        $userMacs  = array();
        $activeRaw = isset($postData['active']) ? $postData['active'] : '';
        foreach (explode(',', $activeRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p    = explode('|', $entry);
            $user = trim($p[0] ?? '');
            $mac  = strtoupper(trim($p[1] ?? ''));
            $ip   = trim($p[2] ?? '');
            if ($user === '' || strlen($mac) < 11) continue;

            // Enrichir depuis la table des hôtes
            $dev    = $macToDevice[$mac] ?? ($ipToDevice[$ip] ?? array());
            $vendor = $dev['vendor'] ?? oui_vendor($mac);
            $hn     = $dev['hostname'] ?? '';
            $label  = $dev['label']    ?? oui_device_label($mac, $hn);

            if (!isset($userMacs[$user])) $userMacs[$user] = array();
            if (!isset($userMacs[$user][$mac])) {
                $userMacs[$user][$mac] = array(
                    'ip'         => $ip,
                    'hostname'   => $hn,
                    'vendor'     => $vendor,
                    'label'      => $label,
                    'first_seen' => $now,
                    'source'     => 'active_' . $source,
                );
            }
            $userMacs[$user][$mac]['last_seen'] = $now;
        }

        // ── 2. Cookies → compléter userMacs ────────────────────────────────
        $cookiesRaw = isset($postData['cookies']) ? $postData['cookies'] : '';
        foreach (explode(',', $cookiesRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            $p    = explode('|', $entry);
            $user = trim($p[0] ?? '');
            $mac  = strtoupper(trim($p[1] ?? ''));
            if ($user === '' || strlen($mac) < 11) continue;

            $dev    = $macToDevice[$mac] ?? array();
            $vendor = $dev['vendor'] ?? oui_vendor($mac);
            $hn     = $dev['hostname'] ?? '';
            $label  = $dev['label']    ?? oui_device_label($mac, $hn);

            if (!isset($userMacs[$user])) $userMacs[$user] = array();
            if (!isset($userMacs[$user][$mac])) {
                $userMacs[$user][$mac] = array(
                    'hostname'   => $hn,
                    'vendor'     => $vendor,
                    'label'      => $label,
                    'first_seen' => $now,
                    'source'     => 'cookie_' . $source,
                );
            }
            $userMacs[$user][$mac]['last_seen'] = $now;
        }

        // ── 3. Logs "invalid MAC" → tentatives de fraude avec device info ───
        // Format: "msg|time;msg|time;" ou "msg;" (time optionnel)
        $attemptedMacs = array();
        $logsRaw       = isset($postData['logs']) ? $postData['logs'] : '';
        foreach (explode(';', $logsRaw) as $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === '') continue;

            // Sépare message|time
            $pipPos  = strrpos($rawLine, '|');
            $logMsg  = $pipPos !== false ? substr($rawLine, 0, $pipPos) : $rawLine;
            $logTime = $pipPos !== false ? trim(substr($rawLine, $pipPos + 1)) : $now;

            // Pattern: "user (IP): login failed: invalid MAC address"
            if (!preg_match(
                '/(?:->:\s*)?([\w\-\.@]+)\s*\((\d+\.\d+\.\d+\.\d+)\):\s*login failed:\s*invalid MAC/i',
                $logMsg, $m
            )) continue;

            $user       = $m[1];
            $attackerIp = $m[2];

            // Résoudre MAC + device de l'appareil attaquant depuis la table des hôtes
            $dev    = $ipToDevice[$attackerIp] ?? array();
            $attackMac = !empty($dev['mac'])      ? $dev['mac']    : '';
            $hn        = !empty($dev['hostname'])  ? $dev['hostname'] : '';
            $vendor    = !empty($dev['vendor'])    ? $dev['vendor']   : ($attackMac ? oui_vendor($attackMac) : '');
            $label     = !empty($dev['label'])     ? $dev['label']    : oui_device_label($attackMac, $hn);

            // Clé unique : MAC connue > fallback IP
            $key = $attackMac !== '' ? $attackMac : ('IP:' . $attackerIp);

            if (!isset($attemptedMacs[$user])) $attemptedMacs[$user] = array();
            if (!isset($attemptedMacs[$user][$key])) {
                $attemptedMacs[$user][$key] = array(
                    'mac'        => $attackMac,
                    'ip'         => $attackerIp,
                    'hostname'   => $hn,
                    'vendor'     => $vendor,
                    'label'      => $label,
                    'first_seen' => $logTime ?: $now,
                    'attempts'   => 0,
                    'source'     => 'log_' . $source,
                );
            }
            $attemptedMacs[$user][$key]['last_seen'] = $logTime ?: $now;
            $attemptedMacs[$user][$key]['attempts']  = ($attemptedMacs[$user][$key]['attempts'] ?? 0) + 1;
        }

        // ── 4. Mise à jour fraud.json ────────────────────────────────────────
        $existing = anti_fraud_load();
        $byKey    = array();
        foreach ($existing as $i) {
            if (isset($i['user'])) $byKey[$i['user']] = $i;
        }

        $allUsers = array();
        foreach ($userMacs      as $u => $_) $allUsers[$u] = true;
        foreach ($attemptedMacs as $u => $_) $allUsers[$u] = true;

        foreach (array_keys($allUsers) as $user) {
            $macs      = isset($userMacs[$user])       ? $userMacs[$user]      : array();
            $attempted = isset($attemptedMacs[$user])  ? $attemptedMacs[$user] : array();

            // Retirer des tentatives les MACs déjà identifiés comme légitimes
            foreach (array_keys($attempted) as $am) {
                if (isset($macs[$am])) unset($attempted[$am]);
            }

            $hasMulti   = count($macs) >= 2;
            $hasAttempt = count($attempted) >= 1;
            if (!$hasMulti && !$hasAttempt) continue;

            // Premier MAC = MAC légitime (le vrai propriétaire du ticket)
            $firstMac   = !empty($macs) ? array_key_first($macs) : '';
            $firstDev   = $firstMac ? ($macs[$firstMac] ?? array()) : array();

            $payload = array(
                'user'            => $user,
                'profile'         => '',
                'comment'         => '',
                'locked_mac'      => $firstMac,
                'locked_device'   => array(
                    'mac'      => $firstMac,
                    'hostname' => $firstDev['hostname'] ?? '',
                    'vendor'   => $firstDev['vendor']   ?? '',
                    'label'    => $firstDev['label']    ?? '',
                    'ip'       => $firstDev['ip']        ?? '',
                ),
                'macs'            => array_keys($macs),
                'mac_meta'        => $macs,
                'count'           => count($macs),
                'attempted_macs'  => array_keys($attempted),
                'attempted_meta'  => $attempted,
                'attempted_count' => count($attempted),
                'last_seen'       => $now,
                'push_source'     => $source,
            );

            if (isset($byKey[$user])) {
                // Fusionner — conserver historique des tentatives
                foreach ($payload as $k => $v) $byKey[$user][$k] = $v;
                if (!isset($byKey[$user]['status']))  $byKey[$user]['status']  = 'new';
                if (!isset($byKey[$user]['history'])) $byKey[$user]['history'] = array();
                if ($byKey[$user]['status'] !== 'new' && $hasAttempt) {
                    $byKey[$user]['status'] = 'new';
                    $byKey[$user]['history'][] = array(
                        'at' => $now, 'event' => 'new_attempt', 'by' => $source,
                        'devices' => array_column(array_values($attempted), 'label'),
                    );
                }
            } else {
                $payload['first_detected'] = $now;
                $payload['status']         = 'new';
                $payload['history']        = array(array(
                    'at' => $now, 'event' => 'detected', 'by' => $source,
                    'devices' => array_column(array_values($attempted), 'label'),
                ));
                $byKey[$user] = $payload;
            }
        }

        $list = array_values($byKey);
        usort($list, function ($a, $b) {
            $sa = $a['status'] === 'new' ? 0 : ($a['status'] === 'acknowledged' ? 1 : 2);
            $sb = $b['status'] === 'new' ? 0 : ($b['status'] === 'acknowledged' ? 1 : 2);
            if ($sa !== $sb) return $sa - $sb;
            return strcmp(
                $b['last_seen'] ?? '', $a['last_seen'] ?? ''
            );
        });
        anti_fraud_save($list);
        return count(array_filter($list, function ($i) { return $i['status'] === 'new'; }));
    }

    function anti_fraud_load() {
        $path = anti_fraud_log_path();
        if (!file_exists($path)) return array();
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return array();
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    function anti_fraud_save($incidents) {
        $path = anti_fraud_log_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp  = $path . '.tmp';
        $json = json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        return @rename($tmp, $path);
    }

    /**
     * Lit les logs hotspot récents et extrait les tentatives échouées
     * "USER (IP): login failed: invalid MAC address" en corrélant l'IP
     * avec le MAC le plus récent vu pour cette IP (logs hotspot précédents
     * ou table /ip/hotspot/host).
     *
     * Retourne array<string user, array<string mac => array{ip,time,source:'log_failed'}>>
     */
    function anti_fraud_scan_failed_attempts($API) {
        $out = array();
        if (!$API) return $out;

        // Snapshot IP → MAC depuis /ip/hotspot/host pour fallback
        $ipToMac = array();
        try {
            $hosts = $API->comm('/ip/hotspot/host/print');
            if (is_array($hosts)) {
                foreach ($hosts as $h) {
                    $ip  = isset($h['address']) ? trim($h['address']) : '';
                    $mac = isset($h['mac-address']) ? strtoupper(trim($h['mac-address'])) : '';
                    if ($ip !== '' && $mac !== '') $ipToMac[$ip] = $mac;
                }
            }
        } catch (Exception $e) {}

        // Lit les logs hotspot récents
        $logs = array();
        try {
            $logs = $API->comm('/log/print', array('?topics' => 'hotspot'));
        } catch (Exception $e) {}
        if (!is_array($logs)) return $out;

        // Premier passage : extrait les couples IP → MAC depuis les lignes
        // "<MAC> (<IP>): ..." (préfixe d'une tentative ou login échoué auth générique)
        foreach ($logs as $row) {
            $msg = isset($row['message']) ? $row['message'] : '';
            if (preg_match('/^([0-9A-F]{2}(?::[0-9A-F]{2}){5})\s*\((\d+\.\d+\.\d+\.\d+)\)/i', $msg, $m)) {
                $ipToMac[$m[2]] = strtoupper($m[1]);
            }
        }

        // Second passage : repère les "invalid MAC address" et associe à l'IP/MAC
        foreach ($logs as $row) {
            $msg  = isset($row['message']) ? $row['message'] : '';
            $time = isset($row['time']) ? $row['time'] : '';
            // Patterns possibles :
            //   "user (ip): login failed: invalid MAC address"
            //   "->: user (ip): login failed: invalid MAC address"
            if (preg_match('/(?:->: )?([\w\-\.]+)\s*\((\d+\.\d+\.\d+\.\d+)\):\s*login failed:\s*invalid MAC address/i', $msg, $m)) {
                $user = $m[1];
                $ip   = $m[2];
                $mac  = isset($ipToMac[$ip]) ? $ipToMac[$ip] : '';
                if ($mac === '') continue;
                if (!isset($out[$user])) $out[$user] = array();
                if (!isset($out[$user][$mac])) {
                    $out[$user][$mac] = array(
                        'ip'         => $ip,
                        'first_seen' => $time,
                        'source'     => 'log_failed',
                    );
                }
                $out[$user][$mac]['last_seen'] = $time;
            }
        }
        return $out;
    }

    /**
     * Scanne MikroTik, met à jour logs/fraud.json. Retourne le nombre d'incidents (re)détectés.
     */
    function anti_fraud_scan($API) {
        if (!$API) return 0;

        $cookies = $API->comm('/ip/hotspot/cookie/print');
        $active  = $API->comm('/ip/hotspot/active/print');
        if (!is_array($cookies)) $cookies = array();
        if (!is_array($active))  $active  = array();

        // Map user → unique MAC list (succès)
        $userMacs = array();
        foreach ($cookies as $c) {
            $u = isset($c['user']) ? trim($c['user']) : '';
            $m = isset($c['mac-address']) ? strtoupper(trim($c['mac-address'])) : '';
            if ($u === '' || $m === '') continue;
            if (!isset($userMacs[$u])) $userMacs[$u] = array();
            $userMacs[$u][$m] = isset($userMacs[$u][$m]) ? $userMacs[$u][$m] : array(
                'first_seen' => date('Y-m-d H:i:s'),
                'last_seen'  => date('Y-m-d H:i:s'),
                'source'     => 'cookie',
            );
        }
        foreach ($active as $a) {
            $u = isset($a['user']) ? trim($a['user']) : '';
            $m = isset($a['mac-address']) ? strtoupper(trim($a['mac-address'])) : '';
            if ($u === '' || $m === '') continue;
            if (!isset($userMacs[$u])) $userMacs[$u] = array();
            if (!isset($userMacs[$u][$m])) {
                $userMacs[$u][$m] = array(
                    'first_seen' => date('Y-m-d H:i:s'),
                    'source'     => 'active',
                );
            }
            $userMacs[$u][$m]['last_seen'] = date('Y-m-d H:i:s');
        }

        // Tentatives bloquées par MAC-lock (depuis logs)
        $attemptedMacs = anti_fraud_scan_failed_attempts($API);

        $existing = anti_fraud_load();
        $byKey = array();
        foreach ($existing as $i) {
            if (isset($i['user'])) $byKey[$i['user']] = $i;
        }

        $now = date('Y-m-d H:i:s');

        // Liste tous les utilisateurs concernés : ceux avec ≥2 MACs réussis OU ceux avec
        // au moins une tentative bloquée (MAC-lock rejeté).
        $allUsers = array();
        foreach ($userMacs as $u => $_) $allUsers[$u] = true;
        foreach ($attemptedMacs as $u => $_) $allUsers[$u] = true;

        foreach (array_keys($allUsers) as $user) {
            $macs       = isset($userMacs[$user])      ? $userMacs[$user]      : array();
            $attempted  = isset($attemptedMacs[$user]) ? $attemptedMacs[$user] : array();

            // Filtrer les attempted déjà présents en succès (pas vraiment de fraude)
            foreach (array_keys($attempted) as $am) {
                if (isset($macs[$am])) unset($attempted[$am]);
            }

            $hasSuccess = count($macs) >= 2;
            $hasAttempt = count($attempted) >= 1;
            if (!$hasSuccess && !$hasAttempt) continue;

            // Get user profile + comment + locked MAC for context
            $profile  = '';
            $comment  = '';
            $lockedMac = '';
            try {
                $userRow = $API->comm('/ip/hotspot/user/print', array('?name' => $user));
                if (is_array($userRow) && !empty($userRow[0])) {
                    $profile   = isset($userRow[0]['profile']) ? $userRow[0]['profile'] : '';
                    $comment   = isset($userRow[0]['comment']) ? $userRow[0]['comment'] : '';
                    $lockedMac = isset($userRow[0]['mac-address']) ? strtoupper(trim($userRow[0]['mac-address'])) : '';
                }
            } catch (Exception $e) {}

            $payload = array(
                'user'             => $user,
                'profile'          => $profile,
                'comment'          => $comment,
                'locked_mac'       => $lockedMac,
                'macs'             => array_keys($macs),
                'mac_meta'         => $macs,
                'count'            => count($macs),
                'attempted_macs'   => array_keys($attempted),
                'attempted_meta'   => $attempted,
                'attempted_count'  => count($attempted),
                'last_seen'        => $now,
            );

            if (isset($byKey[$user])) {
                // Update existing
                foreach ($payload as $k => $v) $byKey[$user][$k] = $v;
                if (!isset($byKey[$user]['status'])) $byKey[$user]['status'] = 'new';
                if (!isset($byKey[$user]['history'])) $byKey[$user]['history'] = array();
                // Si nouvelle tentative depuis dernière reconnaissance → repasser en 'new'
                if ($byKey[$user]['status'] !== 'new' && $hasAttempt) {
                    $byKey[$user]['status'] = 'new';
                    $byKey[$user]['history'][] = array('at' => $now, 'event' => 'new_attempt');
                }
            } else {
                $payload['first_detected'] = $now;
                $payload['status']         = 'new';
                $payload['history']        = array(array('at' => $now, 'event' => 'detected'));
                $byKey[$user]              = $payload;
            }
        }

        $list = array_values($byKey);
        // Sort: new first, then by last_seen desc
        usort($list, function ($a, $b) {
            $sa = $a['status'] === 'new' ? 0 : ($a['status'] === 'acknowledged' ? 1 : 2);
            $sb = $b['status'] === 'new' ? 0 : ($b['status'] === 'acknowledged' ? 1 : 2);
            if ($sa !== $sb) return $sa - $sb;
            return strcmp(isset($b['last_seen']) ? $b['last_seen'] : '', isset($a['last_seen']) ? $a['last_seen'] : '');
        });
        anti_fraud_save($list);
        return count(array_filter($list, function ($i) { return $i['status'] === 'new'; }));
    }

    function anti_fraud_count_unack() {
        $list = anti_fraud_load();
        $n = 0;
        foreach ($list as $i) {
            if (($i['status'] ?? 'new') === 'new') $n++;
        }
        return $n;
    }

    /* ── Blocage / Déblocage appareil sur MikroTik ───────────────────────── */

    /**
     * Bloque un appareil fraudeur sur MikroTik :
     *   - /ip/hotspot/ip-binding  type=blocked  (par MAC)
     *   - /ip/firewall/address-list  list=fraud-blocked  (par IP)
     *
     * Retourne ['ok'=>bool, 'binding_id'=>string, 'fw_id'=>string, 'errors'=>array]
     */
    function anti_fraud_block_device($API, $mac, $ip, $user) {
        $bindingId = '';
        $fwId      = '';
        $errors    = array();
        $comment   = 'MIKHMON-fraud|' . $user . '|' . date('Y-m-d H:i:s');

        // 1. IP Binding hotspot (blocage par MAC)
        if ($mac !== '') {
            try {
                // Vérifier si une entrée bloquée existe déjà pour ce MAC
                $exist = $API->comm('/ip/hotspot/ip-binding/print',
                    array('?mac-address' => $mac, '?type' => 'blocked'));
                if (is_array($exist) && !empty($exist)) {
                    $bindingId = $exist[0]['.id'] ?? '';
                }
                if ($bindingId === '') {
                    $res = $API->comm('/ip/hotspot/ip-binding/add', array(
                        'mac-address' => $mac,
                        'type'        => 'blocked',
                        'comment'     => $comment,
                    ));
                    // RouterOS API renvoie ['.id' => '*N'] pour les add
                    if (is_array($res) && isset($res[0]['.id'])) {
                        $bindingId = $res[0]['.id'];
                    } elseif (is_array($res) && isset($res['.id'])) {
                        $bindingId = $res['.id'];
                    } else {
                        // Fallback : récupérer l'id par recherche
                        $found = $API->comm('/ip/hotspot/ip-binding/print',
                            array('?mac-address' => $mac, '?type' => 'blocked'));
                        $bindingId = is_array($found) && !empty($found)
                            ? ($found[0]['.id'] ?? '') : '';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'ip-binding: ' . $e->getMessage();
            }
        }

        // 2. Firewall Address List "fraud-blocked" (blocage par IP)
        if ($ip !== '' && $ip !== '0.0.0.0') {
            try {
                $exist = $API->comm('/ip/firewall/address-list/print', array(
                    '?list'    => 'fraud-blocked',
                    '?address' => $ip,
                ));
                if (is_array($exist) && !empty($exist)) {
                    $fwId = $exist[0]['.id'] ?? '';
                }
                if ($fwId === '') {
                    $res = $API->comm('/ip/firewall/address-list/add', array(
                        'list'    => 'fraud-blocked',
                        'address' => $ip,
                        'comment' => $comment,
                    ));
                    if (is_array($res) && isset($res[0]['.id'])) {
                        $fwId = $res[0]['.id'];
                    } elseif (is_array($res) && isset($res['.id'])) {
                        $fwId = $res['.id'];
                    } else {
                        $found = $API->comm('/ip/firewall/address-list/print', array(
                            '?list' => 'fraud-blocked', '?address' => $ip));
                        $fwId = is_array($found) && !empty($found)
                            ? ($found[0]['.id'] ?? '') : '';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'fw-address-list: ' . $e->getMessage();
            }
        }

        return array(
            'ok'         => ($bindingId !== '' || $fwId !== ''),
            'binding_id' => $bindingId,
            'fw_id'      => $fwId,
            'errors'     => $errors,
        );
    }

    /**
     * Débloque un appareil sur MikroTik :
     *   - retire l'entrée IP Binding (par id stocké ou recherche par MAC)
     *   - retire l'entrée Address List (par id stocké ou recherche par IP)
     */
    function anti_fraud_unblock_device($API, $mac, $ip, $bindingId = '', $fwId = '') {
        $errors = array();

        // Retirer IP Binding
        if ($mac !== '') {
            try {
                if ($bindingId !== '') {
                    $API->comm('/ip/hotspot/ip-binding/remove', array('.id' => $bindingId));
                } else {
                    $exist = $API->comm('/ip/hotspot/ip-binding/print',
                        array('?mac-address' => $mac, '?type' => 'blocked'));
                    if (is_array($exist)) {
                        foreach ($exist as $e) {
                            if (!empty($e['.id'])) {
                                $API->comm('/ip/hotspot/ip-binding/remove', array('.id' => $e['.id']));
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'ip-binding: ' . $e->getMessage();
            }
        }

        // Retirer Address List
        if ($ip !== '' && $ip !== '0.0.0.0') {
            try {
                if ($fwId !== '') {
                    $API->comm('/ip/firewall/address-list/remove', array('.id' => $fwId));
                } else {
                    $exist = $API->comm('/ip/firewall/address-list/print', array(
                        '?list' => 'fraud-blocked', '?address' => $ip));
                    if (is_array($exist)) {
                        foreach ($exist as $e) {
                            if (!empty($e['.id'])) {
                                $API->comm('/ip/firewall/address-list/remove', array('.id' => $e['.id']));
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'fw-address-list: ' . $e->getMessage();
            }
        }

        return array('ok' => true, 'errors' => $errors);
    }

    /**
     * Met à jour le statut blocked d'un appareil dans fraud.json.
     * $deviceKey = MAC ou "IP:x.x.x.x" (clé dans attempted_meta).
     */
    function anti_fraud_update_device_block($user, $deviceKey, $blocked,
                                            $bindingId = '', $fwId = '') {
        $list = anti_fraud_load();
        $now  = date('Y-m-d H:i:s');
        foreach ($list as &$incident) {
            if (($incident['user'] ?? '') !== $user) continue;
            if (isset($incident['attempted_meta'][$deviceKey])) {
                $incident['attempted_meta'][$deviceKey]['blocked']     = (bool)$blocked;
                $incident['attempted_meta'][$deviceKey]['blocked_at']  = $blocked ? $now : null;
                $incident['attempted_meta'][$deviceKey]['binding_id']  = $bindingId;
                $incident['attempted_meta'][$deviceKey]['fw_id']       = $fwId;
            }
            if (!isset($incident['history']) || !is_array($incident['history'])) {
                $incident['history'] = array();
            }
            $incident['history'][] = array(
                'at'     => $now,
                'event'  => $blocked ? 'device_blocked' : 'device_unblocked',
                'by'     => 'admin',
                'device' => $deviceKey,
            );
            break;
        }
        unset($incident);
        return anti_fraud_save($list);
    }

    function anti_fraud_set_status($user, $status, $by) {
        $list = anti_fraud_load();
        $now  = date('Y-m-d H:i:s');
        foreach ($list as &$i) {
            if (($i['user'] ?? '') === $user) {
                $i['status'] = $status;
                if (!isset($i['history']) || !is_array($i['history'])) $i['history'] = array();
                $i['history'][] = array('at' => $now, 'event' => $status, 'by' => $by);
            }
        }
        unset($i);
        return anti_fraud_save($list);
    }

    /* ── Script RouterOS — MIKHMON-AntiFraud ─────────────────────────────── */

    function anti_fraud_ros_quote($value) {
        return '"' . str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\"', '', ''), (string)$value) . '"';
    }

    /**
     * Genere le contenu du script RouterOS MIKHMON-AntiFraud.
     * Collecte sessions, cookies, WiFi (v6+v7) et logs invalid MAC.
     * Envoie via /tool/fetch POST vers $url.
     */
    function anti_fraud_build_script($url, $key, $session) {
        $nl = "\n";
        $q  = 'anti_fraud_ros_quote';

        $s  = '# ============================================================' . $nl;
        $s .= '# MIKHMON-AntiFraud v3.3 - SafeLink Africa' . $nl;
        $s .= '# Detecte tickets partages, OS probable, WiFi et invalid MAC' . $nl;
        $s .= '# Rapport envoye toutes les 5 minutes vers MIKHMON' . $nl;
        $s .= '# Compatible RouterOS v6/v7 - deploye via API' . $nl;
        $s .= '# ============================================================' . $nl . $nl;

        $s .= '# Configuration' . $nl;
        $s .= ':local mikhmonUrl ' . $q($url)     . $nl;
        $s .= ':local apiKey '     . $q($key)     . $nl;
        $s .= ':local sessionName '. $q($session) . $nl;
        $s .= ':local sourceId '   . $q($session) . $nl . $nl;

        $s .= '# Table des hotes hotspot: MAC|IP|hostname|authorized' . $nl;
        $s .= ':local hostPart ""' . $nl;
        $s .= ':foreach h in=[/ip hotspot host print as-value] do={' . $nl;
        $s .= '    :local mac ""' . $nl . '    :local ip ""' . $nl . '    :local hn ""' . $nl . '    :local auth "0"' . $nl;
        $s .= '    :do { :set mac ($h->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set ip ($h->"address") } on-error={}' . $nl;
        $s .= '    :do { :set hn ($h->"host-name") } on-error={}' . $nl;
        $s .= '    :do { :if (($h->"authorized") = "true") do={ :set auth "1" } } on-error={}' . $nl;
        $s .= '    :if ([:len $mac] > 0) do={' . $nl;
        $s .= '        :if ([:len $hostPart] < 5000) do={' . $nl;
        $s .= '            :set hostPart ($hostPart . $mac . "|" . $ip . "|" . $hn . "|" . $auth . ",")' . $nl;
        $s .= '        }' . $nl . '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Sessions actives: ticket|MAC|IP' . $nl;
        $s .= ':local activePart ""' . $nl;
        $s .= ':foreach a in=[/ip hotspot active print as-value] do={' . $nl;
        $s .= '    :local u ""' . $nl . '    :local m ""' . $nl . '    :local ip ""' . $nl;
        $s .= '    :do { :set u ($a->"user") } on-error={}' . $nl;
        $s .= '    :do { :set m ($a->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set ip ($a->"address") } on-error={}' . $nl;
        $s .= '    :if ([:len $activePart] < 4000) do={' . $nl;
        $s .= '        :set activePart ($activePart . $u . "|" . $m . "|" . $ip . ",")' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Cookies hotspot: ticket|MAC' . $nl;
        $s .= ':local cookiePart ""' . $nl;
        $s .= ':foreach c in=[/ip hotspot cookie print as-value] do={' . $nl;
        $s .= '    :local u ""' . $nl . '    :local m ""' . $nl;
        $s .= '    :do { :set u ($c->"user") } on-error={}' . $nl;
        $s .= '    :do { :set m ($c->"mac-address") } on-error={}' . $nl;
        $s .= '    :if ([:len $cookiePart] < 4000) do={' . $nl;
        $s .= '        :set cookiePart ($cookiePart . $u . "|" . $m . ",")' . $nl;
        $s .= '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# WiFi associe: MAC|interface|ssid|security-profile|wifi-key|signal' . $nl;
        $s .= ':local wifiPart ""' . $nl;
        // RouterOS v6 (pre-fetch pour eviter -> a profondeur 3 en terminal)
        $s .= ':local wirelessRows {}' . $nl;
        $s .= ':do { :set wirelessRows [/interface wireless registration-table print as-value] } on-error={}' . $nl;
        $s .= ':foreach r in=$wirelessRows do={' . $nl;
        $s .= '    :local mac ""' . $nl . '    :local iface ""' . $nl . '    :local ssid ""' . $nl;
        $s .= '    :local sec ""' . $nl . '    :local key ""' . $nl . '    :local sig ""' . $nl;
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
        $s .= '        }' . $nl . '    }' . $nl;
        $s .= '}' . $nl;
        // RouterOS v7 (pre-fetch idem)
        $s .= ':local wifiRows {}' . $nl;
        $s .= ':do { :set wifiRows [/interface wifi registration-table print as-value] } on-error={}' . $nl;
        $s .= ':foreach r in=$wifiRows do={' . $nl;
        $s .= '    :local mac ""' . $nl . '    :local iface ""' . $nl . '    :local ssid ""' . $nl;
        $s .= '    :local sec ""' . $nl . '    :local key ""' . $nl . '    :local sig ""' . $nl;
        $s .= '    :do { :set mac ($r->"mac-address") } on-error={}' . $nl;
        $s .= '    :do { :set iface ($r->"interface") } on-error={}' . $nl;
        $s .= '    :do { :set sig ($r->"signal") } on-error={}' . $nl;
        $s .= '    :do { :set ssid [/interface wifi get [find where name=$iface] ssid] } on-error={}' . $nl;
        $s .= '    :do { :set sec [/interface wifi get [find where name=$iface] security] } on-error={}' . $nl;
        $s .= '    :do { :set key [/interface wifi security get [find where name=$sec] passphrase] } on-error={}' . $nl;
        $s .= '    :if ([:len $mac] > 0) do={' . $nl;
        $s .= '        :if ([:len $wifiPart] < 4000) do={' . $nl;
        $s .= '            :set wifiPart ($wifiPart . $mac . "|" . $iface . "|" . $ssid . "|" . $sec . "|" . $key . "|" . $sig . ",")' . $nl;
        $s .= '        }' . $nl . '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Logs hotspot invalid MAC: message|time;' . $nl;
        $s .= ':local logPart ""' . $nl;
        $s .= ':foreach l in=[/log print as-value where topics~"hotspot"] do={' . $nl;
        $s .= '    :local msg ""' . $nl . '    :local tm ""' . $nl;
        $s .= '    :do { :set msg ($l->"message") } on-error={}' . $nl;
        $s .= '    :do { :set tm ($l->"time") } on-error={}' . $nl;
        $s .= '    :local found [:find $msg "invalid MAC"]' . $nl;
        $s .= '    :if ([:typeof $found] != "nil") do={' . $nl;
        $s .= '        :if ([:len $logPart] < 3000) do={' . $nl;
        $s .= '            :set logPart ($logPart . $msg . "|" . $tm . ";")' . $nl;
        $s .= '        }' . $nl . '    }' . $nl;
        $s .= '}' . $nl . $nl;

        $s .= '# Envoi vers MIKHMON (anti-fraude)' . $nl;
        $s .= ':local postData ""' . $nl;
        $s .= ':set postData ("key=" . $apiKey)' . $nl;
        $s .= ':set postData ($postData . "&session=" . $sessionName)' . $nl;
        $s .= ':set postData ($postData . "&source=" . $sourceId)' . $nl;
        $s .= ':set postData ($postData . "&hosts=" . $hostPart)' . $nl;
        $s .= ':set postData ($postData . "&active=" . $activePart)' . $nl;
        $s .= ':set postData ($postData . "&cookies=" . $cookiePart)' . $nl;
        $s .= ':set postData ($postData . "&wifi=" . $wifiPart)' . $nl;
        $s .= ':set postData ($postData . "&logs=" . $logPart)' . $nl;
        $s .= ':do {' . $nl;
        $s .= '    /tool fetch url=$mikhmonUrl http-method=post http-data=$postData output=none' . $nl;
        $s .= '    :log info "[MIKHMON-AntiFraud] rapport envoye"' . $nl;
        $s .= '} on-error={ :log warning "[MIKHMON-AntiFraud] echec envoi" }' . $nl;

        return $s;
    }
}
