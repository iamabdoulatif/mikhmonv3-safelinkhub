<?php

if (!function_exists('mikhmon_comment_seller_key')) {
    function mikhmon_comment_seller_key($comment, $sellersData) {
        $normalizedComment = strtolower(trim((string)$comment));
        if ($normalizedComment === '' || !is_array($sellersData)) {
            return '';
        }

        foreach ($sellersData as $sellerKey => $sellerData) {
            $normalizedSeller = strtolower(trim((string)$sellerKey));
            if ($normalizedSeller === '') {
                continue;
            }

            $suffix = '-' . $normalizedSeller;
            if ($normalizedComment === $normalizedSeller || substr($normalizedComment, -strlen($suffix)) === $suffix) {
                return $sellerKey;
            }
        }

        return '';
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

        if (strcasecmp($comment, $sellerKey) === 0) {
            return '';
        }

        $suffix = '-' . $sellerKey;
        if (strlen($comment) > strlen($suffix) && strcasecmp(substr($comment, -strlen($suffix)), $suffix) === 0) {
            return substr($comment, 0, -strlen($suffix));
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

        $baseLot = trim(mikhmon_comment_base_lot($comment, $sellersData));
        if ($baseLot === '') {
            return $sellerKey;
        }

        $normalizedBase = strtolower($baseLot);
        $normalizedSeller = strtolower($sellerKey);
        $suffix = '-' . $normalizedSeller;
        if ($normalizedBase === $normalizedSeller || substr($normalizedBase, -strlen($suffix)) === $suffix) {
            return $baseLot;
        }

        return $baseLot . '-' . $sellerKey;
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
