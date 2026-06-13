<?php

if (!function_exists('mikhmon_hotspot_user_is_available')) {
    function mikhmon_hotspot_user_is_available($user) {
        if (!is_array($user)) {
            return false;
        }
        $uptime = isset($user['uptime']) ? trim((string)$user['uptime']) : '';
        return $uptime === '' || $uptime === '0s';
    }
}

if (!function_exists('mikhmon_comment_seller_key')) {
    function mikhmon_seller_display_label($sellerKey, $sellerData) {
        $label = trim((string)$sellerKey);
        if (is_array($sellerData) && isset($sellerData['name']) && trim((string)$sellerData['name']) !== '') {
            $label = trim((string)$sellerData['name']);
        }

        $label = preg_replace('/\s*\(\s*historique\s*\)\s*$/i', '', $label);
        $label = preg_replace('/(?:[\s_-]+historique)\s*$/i', '', $label);
        $label = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label));

        return $label !== '' ? $label : trim((string)$sellerKey);
    }

    function mikhmon_comment_seller_aliases($sellerKey, $sellerData) {
        static $cache = array();
        $cacheKey = trim((string)$sellerKey) . '|' . (is_array($sellerData) && isset($sellerData['name']) ? (string)$sellerData['name'] : '');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $aliases = array();
        $sellerKey = trim((string)$sellerKey);
        if ($sellerKey !== '') {
            $aliases[] = $sellerKey;
        }
        if (is_array($sellerData) && isset($sellerData['name'])) {
            $sellerName = trim((string)$sellerData['name']);
            if ($sellerName !== '') {
                $aliases[] = $sellerName;

                // Les lots historiques utilisent parfois "Nom historique" sans
                // parenthèses au lieu de "Nom (historique)".
                $sellerNameNoParens = trim(preg_replace('/\s+/', ' ', str_replace(array('(', ')'), '', $sellerName)));
                if ($sellerNameNoParens !== '' && $sellerNameNoParens !== $sellerName) {
                    $aliases[] = $sellerNameNoParens;
                }
            }
        }

        $displayLabel = mikhmon_seller_display_label($sellerKey, $sellerData);
        if ($displayLabel !== '') {
            $aliases[] = $displayLabel;
            $aliases[] = $displayLabel . ' historique';
        }

        $out = array();
        $seen = array();
        foreach ($aliases as $alias) {
            $key = strtolower(preg_replace('/\s+/', ' ', trim((string)$alias)));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = trim((string)$alias);
        }

        $cache[$cacheKey] = $out;
        return $out;
    }

    function mikhmon_comment_alias_tail_regex($alias) {
        static $cache = array();
        $alias = (string)$alias;
        if (isset($cache[$alias])) {
            return $cache[$alias];
        }

        $parts = preg_split('/[\s_-]+/', strtolower(trim($alias)));
        $cleanParts = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $cleanParts[] = preg_quote($part, '/');
            }
        }
        if (empty($cleanParts)) {
            return $cache[$alias] = '';
        }

        return $cache[$alias] = '/(?:^|[\s_-])' . implode('[\s_-]+', $cleanParts) . '$/i';
    }

    function mikhmon_comment_alias_tail_or_profile_regex($alias) {
        static $cache = array();
        $alias = (string)$alias;
        if (isset($cache[$alias])) {
            return $cache[$alias];
        }

        $parts = preg_split('/[\s_-]+/', strtolower(trim($alias)));
        $cleanParts = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $cleanParts[] = preg_quote($part, '/');
            }
        }
        if (empty($cleanParts)) {
            return $cache[$alias] = '';
        }

        $profileTail = '(?:[\s_-]+\d{1,2}[\s_-]*(?:heures?|jours?|jour|semaines?|semaine|mois)(?:[\s_-]*pc)?)?';
        return $cache[$alias] = '/(?:^|[\s_-])' . implode('[\s_-]+', $cleanParts) . $profileTail . '$/i';
    }

    function mikhmon_comment_matches_seller_alias($comment, $alias) {
        $comment = preg_replace('/\s+/', ' ', trim((string)$comment));
        $alias = preg_replace('/\s+/', ' ', trim((string)$alias));
        if ($comment === '' || $alias === '') {
            return false;
        }

        if (strcasecmp($comment, $alias) === 0) {
            return true;
        }

        $regex = mikhmon_comment_alias_tail_regex($alias);
        if ($regex !== '' && preg_match($regex, $comment) === 1) {
            return true;
        }

        $regex = mikhmon_comment_alias_tail_or_profile_regex($alias);
        return $regex !== '' && preg_match($regex, $comment) === 1;
    }

    function mikhmon_comment_seller_key($comment, $sellersData) {
        $comment = trim((string)$comment);
        if ($comment === '' || !is_array($sellersData)) {
            return '';
        }

        if (preg_match('/MIKHMON_ACCOUNT\s+role=([^\s|]+)\s+session=([^\s|]+)\s+account=([^\s|]+)/i', $comment, $matches)) {
            $role = strtolower(trim($matches[1]));
            $account = preg_replace('/[^a-zA-Z0-9_]/', '', trim($matches[3]));
            if (($role === 'seller' || $role === 'vendeur') && $account !== '' && isset($sellersData[$account])) {
                return $account;
            }
        }

        // Les comptes actifs sont prioritaires sur les comptes historiques :
        // un commentaire "...-Mijai" doit être attribué au compte actif "mijai"
        // plutôt qu'à l'ancien compte historique "Mijai" partageant le même alias.
        $historicalMatch = '';
        foreach ($sellersData as $sellerKey => $sellerData) {
            foreach (mikhmon_comment_seller_aliases($sellerKey, $sellerData) as $alias) {
                if (mikhmon_comment_matches_seller_alias($comment, $alias)) {
                    if (mikhmon_seller_is_historical($sellerData)) {
                        if ($historicalMatch === '') {
                            $historicalMatch = $sellerKey;
                        }
                        continue 2;
                    }
                    return $sellerKey;
                }
            }
        }

        return $historicalMatch;
    }
}

if (!function_exists('mikhmon_comment_base_lot')) {
    function mikhmon_comment_base_lot($comment, $sellersData) {
        $comment = trim((string)$comment);
        if ($comment === '') {
            return '';
        }

        $sellerKey = mikhmon_comment_seller_key($comment, $sellersData);
        if ($sellerKey === '') {
            return $comment;
        }

        foreach (mikhmon_comment_seller_aliases($sellerKey, $sellersData[$sellerKey]) as $alias) {
            if (strcasecmp($comment, $alias) === 0) {
                return '';
            }
            $regex = mikhmon_comment_alias_tail_or_profile_regex($alias);
            if ($regex !== '' && preg_match($regex, $comment) === 1) {
                return rtrim(preg_replace($regex, '', $comment), "-_ \t\n\r\0\x0B");
            }
        }

        return $comment;
    }
}

if (!function_exists('mikhmon_comment_assign_seller')) {
    function mikhmon_comment_assign_seller($comment, $sellerKey, $sellersData) {
        $sellerKey = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)$sellerKey));
        if ($sellerKey === '') {
            return trim((string)$comment);
        }

        $sellerLabel = $sellerKey;
        if (is_array($sellersData) && isset($sellersData[$sellerKey]) && is_array($sellersData[$sellerKey])) {
            $displayName = mikhmon_seller_display_label($sellerKey, $sellersData[$sellerKey]);
            if ($displayName !== '') {
                $sellerLabel = $displayName;
            }
        }

        $baseLot = trim(mikhmon_comment_base_lot($comment, $sellersData));
        if ($baseLot === '') {
            return $sellerLabel;
        }

        $normalizedBase = strtolower($baseLot);
        $normalizedSeller = strtolower($sellerLabel);
        $suffix = '-' . $normalizedSeller;
        if ($normalizedBase === $normalizedSeller || substr($normalizedBase, -strlen($suffix)) === $suffix) {
            return $baseLot;
        }

        return $baseLot . '-' . $sellerLabel;
    }
}

if (!function_exists('mikhmon_normalize_seller_lot_comment')) {
    function mikhmon_normalize_seller_lot_comment($comment, $sellersData) {
        $comment = trim((string)$comment);
        if ($comment === '' || !is_array($sellersData)) {
            return $comment;
        }

        $sellerKey = mikhmon_comment_seller_key($comment, $sellersData);
        if ($sellerKey === '') {
            return $comment;
        }

        return mikhmon_comment_assign_seller($comment, $sellerKey, $sellersData);
    }
}

if (!function_exists('mikhmon_extract_ticket_number')) {
    /**
     * Extrait la valeur numérique d'un nom de ticket pour le tri séquentiel.
     * Priorité aux chiffres en fin de nom (ex. "vcr042" → 42, "lot5-001" → 1).
     * Si aucun chiffre n'est trouvé, retourne PHP_INT_MAX (tri en dernier).
     */
    function mikhmon_extract_ticket_number($name) {
        $name = trim((string)$name);
        if (preg_match('/(\d+)\D*$/', $name, $m)) return (int)$m[1];
        if (preg_match('/^(\d+)/',    $name, $m)) return (int)$m[1];
        return PHP_INT_MAX;
    }
}

if (!function_exists('mikhmon_select_sequential')) {
    /**
     * Sélectionne exactement $qty tickets formant une suite consécutive
     * (n, n+1, n+2, …) parmi $users triés par numéro extrait du nom.
     *
     * Retourne le tableau des tickets sélectionnés si une suite existe,
     * ou false si aucune suite de longueur $qty n'est disponible.
     *
     * @param  array    $users  Enregistrements tickets (clé 'name' obligatoire)
     * @param  int      $qty    Longueur de la suite demandée
     * @return array|false
     */
    function mikhmon_select_sequential($users, $qty) {
        if (empty($users) || $qty < 1 || count($users) < $qty) return false;

        // Tri croissant par numéro extrait du nom
        usort($users, function($a, $b) {
            return mikhmon_extract_ticket_number($a['name'])
                 - mikhmon_extract_ticket_number($b['name']);
        });

        // Un seul ticket demandé : le premier suffit
        if ($qty === 1) return array($users[0]);

        $n        = count($users);
        $runStart = 0;
        $runLen   = 1;

        for ($i = 1; $i < $n; $i++) {
            $prev = mikhmon_extract_ticket_number($users[$i - 1]['name']);
            $curr = mikhmon_extract_ticket_number($users[$i]['name']);

            if ($curr === $prev + 1) {
                $runLen++;
                if ($runLen >= $qty) {
                    return array_slice($users, $runStart, $qty);
                }
            } else {
                $runStart = $i;
                $runLen   = 1;
            }
        }

        return false; // aucune suite consécutive de longueur $qty
    }
}

if (!function_exists('mikhmon_collect_profiles_from_users')) {
    function mikhmon_collect_profiles_from_users($users) {
        $profiles = array();
        if (!is_array($users)) {
            return $profiles;
        }

        foreach ($users as $user) {
            $profile = isset($user['profile']) ? trim((string)$user['profile']) : '';
            if ($profile !== '') {
                $profiles[$profile] = true;
            }
        }

        $profiles = array_keys($profiles);
        natcasesort($profiles);
        return array_values($profiles);
    }
}

if (!function_exists('mikhmon_seller_is_historical')) {
    function mikhmon_seller_is_historical($sellerData) {
        return is_array($sellerData)
            && !empty($sellerData['historical']);
    }
}

if (!function_exists('mikhmon_filter_display_sellers')) {
    function mikhmon_filter_display_sellers($sellersData) {
        $filtered = array();
        if (!is_array($sellersData)) {
            return $filtered;
        }

        foreach ($sellersData as $sellerKey => $sellerData) {
            if (mikhmon_seller_is_historical($sellerData)) {
                continue;
            }
            $filtered[$sellerKey] = $sellerData;
        }

        return $filtered;
    }
}

if (!function_exists('mikhmon_seller_comment_lot_key')) {
    function mikhmon_seller_comment_lot_key($comment, $profile, $sellersData) {
        $comment = trim((string)$comment);
        if ($comment === '') {
            return '';
        }

        $base = mikhmon_comment_base_lot($comment, $sellersData);
        $base = preg_replace('/(?:\s*\|\s*)?MIKHMON_ACCOUNT\b.*$/i', '', $base);
        $base = trim((string)$base);

        $profile = trim((string)$profile);
        if ($profile !== '') {
            $quotedProfile = preg_quote($profile, '/');
            $base = preg_replace('/[-_ ]+' . $quotedProfile . '$/i', '', $base);
        }

        $base = preg_replace('/[-_ ]+$/', '', $base);
        $base = strtolower(preg_replace('/\s+/', ' ', trim((string)$base)));
        $profileKey = strtolower(preg_replace('/\s+/', ' ', $profile));

        return $base !== '' ? $base . '|' . $profileKey : '';
    }
}

if (!function_exists('mikhmon_seller_lot_owner_map_from_users')) {
    function mikhmon_seller_lot_owner_map_from_users($users, $sellersData) {
        $owners = array();
        $conflicts = array();
        if (!is_array($users)) {
            return $owners;
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $comment = isset($user['comment']) ? $user['comment'] : '';
            $sellerKey = mikhmon_comment_seller_key($comment, $sellersData);
            if ($sellerKey === '') {
                continue;
            }

            $profile = isset($user['profile']) ? $user['profile'] : '';
            $lotKey = mikhmon_seller_comment_lot_key($comment, $profile, $sellersData);
            if ($lotKey === '') {
                continue;
            }

            if (isset($owners[$lotKey]) && $owners[$lotKey] !== $sellerKey) {
                $conflicts[$lotKey] = true;
                unset($owners[$lotKey]);
                continue;
            }
            if (!isset($conflicts[$lotKey])) {
                $owners[$lotKey] = $sellerKey;
            }
        }

        return $owners;
    }
}

if (!function_exists('mikhmon_enrich_sales_with_lot_owner')) {
    function mikhmon_enrich_sales_with_lot_owner($sales, $lotOwnerMap, $sellersData) {
        if (!is_array($sales) || empty($lotOwnerMap)) {
            return is_array($sales) ? $sales : array();
        }

        $out = array();
        foreach ($sales as $sale) {
            if (!is_array($sale)) {
                continue;
            }

            $row = isset($sale['profile']) && array_key_exists('comment', $sale)
                ? $sale
                : (function_exists('mikhmon_parse_sale_script') ? mikhmon_parse_sale_script($sale) : $sale);

            $comment = isset($row['comment']) ? (string)$row['comment'] : '';
            if (mikhmon_comment_seller_key($comment, $sellersData) === '') {
                $profile = isset($row['profile']) ? $row['profile'] : '';
                $lotKey = mikhmon_seller_comment_lot_key($comment, $profile, $sellersData);
                if ($lotKey !== '' && isset($lotOwnerMap[$lotKey])) {
                    $row['comment'] = rtrim($comment, "-_ \t\n\r\0\x0B") . '-' . $lotOwnerMap[$lotKey];
                }
            }

            $out[] = $row;
        }

        return $out;
    }
}

if (!function_exists('mikhmon_seller_profile_metrics')) {
    function mikhmon_seller_profile_metrics($sales, $availableBySeller, $sellersData) {
        $metrics = array();

        if (is_array($availableBySeller)) {
            foreach ($availableBySeller as $sellerKey => $profiles) {
                if (!isset($metrics[$sellerKey])) {
                    $metrics[$sellerKey] = array();
                }
                if (!is_array($profiles)) {
                    continue;
                }

                foreach ($profiles as $profile => $quantity) {
                    $profile = trim((string)$profile);
                    if ($profile === '') {
                        continue;
                    }

                    $available = max(0, (int)$quantity);
                    $metrics[$sellerKey][$profile] = array(
                        'sold' => 0,
                        'available' => $available,
                        'total' => $available,
                    );
                }
            }
        }

        $saleRows = function_exists('mikhmon_unique_sale_scripts')
            ? mikhmon_unique_sale_scripts($sales)
            : (is_array($sales) ? $sales : array());

        foreach ($saleRows as $sale) {
            if (!is_array($sale)) {
                continue;
            }

            $row = isset($sale['profile']) && isset($sale['comment'])
                ? $sale
                : (function_exists('mikhmon_parse_sale_script') ? mikhmon_parse_sale_script($sale) : array());
            $profile = isset($row['profile']) ? trim((string)$row['profile']) : '';
            $comment = isset($row['comment']) ? $row['comment'] : '';
            $sellerKey = mikhmon_comment_seller_key($comment, $sellersData);

            if ($sellerKey === '' || $profile === '') {
                continue;
            }
            if (!isset($metrics[$sellerKey])) {
                $metrics[$sellerKey] = array();
            }
            if (!isset($metrics[$sellerKey][$profile])) {
                $metrics[$sellerKey][$profile] = array(
                    'sold' => 0,
                    'available' => 0,
                    'total' => 0,
                );
            }

            $metrics[$sellerKey][$profile]['sold']++;
            $metrics[$sellerKey][$profile]['total']++;
        }

        foreach ($metrics as &$profiles) {
            uksort($profiles, 'strnatcasecmp');
        }
        unset($profiles);

        return $metrics;
    }
}
