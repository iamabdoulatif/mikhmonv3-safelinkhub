<?php
/*
 * oui_lookup.php — Base OUI (MAC prefix → fabricant) pour identification d'appareils.
 * Couvre les marques mobiles les plus communes en Afrique de l'Ouest.
 * Format OUI : 6 chiffres hex uppercase sans séparateurs (ex: "AABBCC").
 */

if (!function_exists('oui_vendor')) {

    function oui_vendor($mac) {
        if (empty($mac) || strlen($mac) < 8) return 'Inconnu';
        // Normalise : retire séparateurs, prend les 6 premiers hex
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        $prefix = substr($clean, 0, 6);

        static $db = null;
        if ($db === null) $db = _oui_db();

        return isset($db[$prefix]) ? $db[$prefix] : _oui_prefix_guess($prefix);
    }

    /** Détecte le modèle/marque depuis le hostname MikroTik */
    function oui_parse_hostname($hostname) {
        if (empty($hostname)) return '';
        $h = strtolower(trim($hostname));

        $patterns = [
            '/iphone/i'                => 'Apple iPhone',
            '/ipad/i'                  => 'Apple iPad',
            '/macbook/i'               => 'Apple MacBook',
            '/samsung[_\-]?(sm|gt)/i'  => 'Samsung (mobile)',
            '/samsung/i'               => 'Samsung',
            '/sm[\-_][a-z]\d{3}/i'     => 'Samsung Galaxy',
            '/gt[\-_][a-z]\d{4}/i'     => 'Samsung (ancien)',
            '/redmi/i'                 => 'Xiaomi Redmi',
            '/xiaomi/i'                => 'Xiaomi',
            '/mi[\-_ ]\d/i'            => 'Xiaomi Mi',
            '/poco/i'                  => 'Xiaomi POCO',
            '/tecno/i'                 => 'Tecno',
            '/infinix/i'               => 'Infinix',
            '/itel/i'                  => 'Itel',
            '/transsion/i'             => 'Transsion',
            '/huawei/i'                => 'Huawei',
            '/honor/i'                 => 'Honor',
            '/oppo/i'                  => 'Oppo',
            '/realme/i'                => 'Realme',
            '/vivo/i'                  => 'Vivo',
            '/nokia/i'                 => 'Nokia',
            '/motorola|moto[_ \-]/i'   => 'Motorola',
            '/lenovo/i'                => 'Lenovo',
            '/asus/i'                  => 'Asus',
            '/lg[\-_ ][a-z]/i'         => 'LG',
            '/oneplus/i'               => 'OnePlus',
            '/pixel/i'                 => 'Google Pixel',
            '/android/i'               => 'Android (générique)',
            '/windows/i'               => 'Windows PC',
            '/laptop/i'                => 'PC/Laptop',
            '/desktop/i'               => 'PC Desktop',
            '/ubuntu|linux/i'          => 'Linux',
            '/router|mikrotik/i'       => 'Routeur',
        ];

        foreach ($patterns as $pat => $brand) {
            if (preg_match($pat, $hostname)) return $brand;
        }
        return '';
    }

    /** Combinaison OUI + hostname pour un label complet */
    function oui_device_label($mac, $hostname) {
        $brand    = oui_vendor($mac);
        $fromHost = oui_parse_hostname($hostname);

        if ($fromHost !== '') {
            // Hostname est plus précis : brand (hostname brut)
            return $fromHost . ($hostname ? ' — ' . $hostname : '');
        }
        $label = $brand !== 'Inconnu' ? $brand : 'Appareil inconnu';
        if ($hostname !== '') $label .= ' — ' . $hostname;
        return $label;
    }

    /* ── Fallback par blocs connus ──────────────────────────────────────── */
    function _oui_prefix_guess($prefix) {
        // Certains fabricants utilisent des blocs LAA (Locally Administered)
        $byte1 = hexdec(substr($prefix, 0, 2));
        if ($byte1 & 0x02) return 'Adresse MAC aléatoire (vie privée)';
        return 'Fabricant inconnu';
    }

    /* ── Base OUI compacte ──────────────────────────────────────────────── */
    function _oui_db() {
        return [
            // ── Apple ────────────────────────────────────────────────────
            '000393' => 'Apple', '000A27' => 'Apple', '000D93' => 'Apple',
            '001124' => 'Apple', '001451' => 'Apple', '0016CB' => 'Apple',
            '0017F2' => 'Apple', '001B63' => 'Apple', '001CB3' => 'Apple',
            '001D4F' => 'Apple', '001E52' => 'Apple', '001EC2' => 'Apple',
            '001F5B' => 'Apple', '001FF3' => 'Apple', '0021E9' => 'Apple',
            '002241' => 'Apple', '002312' => 'Apple', '002332' => 'Apple',
            '00236C' => 'Apple', '0023DF' => 'Apple', '002436' => 'Apple',
            '002500' => 'Apple', '00254B' => 'Apple', '0025BC' => 'Apple',
            '002608' => 'Apple', '00264A' => 'Apple', '0026B0' => 'Apple',
            '0026BB' => 'Apple', '003EE1' => 'Apple', '005AC6' => 'Apple',
            '007040' => 'Apple', '00F4B9' => 'Apple', '040CCE' => 'Apple',
            '0C15C2' => 'Apple', '0C3021' => 'Apple', '0C4DE9' => 'Apple',
            '0C74BB' => 'Apple', '0CF3EE' => 'Apple', '101C0C' => 'Apple',
            '106F3F' => 'Apple', '10DD00' => 'Apple', '14109F' => 'Apple',
            '14998F' => 'Apple', '189EFC' => 'Apple', '1C1AC0' => 'Apple',
            '1C5CF2' => 'Apple', '1C9E46' => 'Apple', '202328' => 'Apple',
            '20AB37' => 'Apple', '24A074' => 'Apple', '283737' => 'Apple',
            '28CFE9' => 'Apple', '28E02C' => 'Apple', '2CF0EE' => 'Apple',
            '3010E4' => 'Apple', '340285' => 'Apple', '38C986' => 'Apple',
            '3C07F4' => 'Apple', '3C15C2' => 'Apple', '400CCC' => 'Apple',
            '40331A' => 'Apple', '40A6D9' => 'Apple', '44D884' => 'Apple',
            '48D705' => 'Apple', '4C3275' => 'Apple', '4C57CA' => 'Apple',
            '4C8D79' => 'Apple', '50EAD6' => 'Apple', '54AE27' => 'Apple',
            '54E43A' => 'Apple', '54EA25' => 'Apple', '5CD4AB' => 'Apple',
            '6031D8' => 'Apple', '6096A5' => 'Apple', '60FB42' => 'Apple',
            '6C40BC' => 'Apple', '6CAB31' => 'Apple', '6CF049' => 'Apple',
            '703A56' => 'Apple', '709C92' => 'Apple', '7099C6' => 'Apple',
            '745EA5' => 'Apple', '749DC0' => 'Apple', '74E2F5' => 'Apple',
            '78888A' => 'Apple', '787B8A' => 'Apple', '7C6D62' => 'Apple',
            '7CF05F' => 'Apple', '80929F' => 'Apple', '8496D8' => 'Apple',
            '84B153' => 'Apple', '8C8590' => 'Apple', '90601C' => 'Apple',
            '908D6C' => 'Apple', '946D5D' => 'Apple', '9810E8' => 'Apple',
            '9CF48E' => 'Apple', 'A0D795' => 'Apple', 'A45E60' => 'Apple',
            'A48195' => 'Apple', 'A8667F' => 'Apple', 'A8BDCE' => 'Apple',
            'AC3C0B' => 'Apple', 'AC7F3E' => 'Apple', 'ACBC32' => 'Apple',
            'ACFDCE' => 'Apple', 'B06308' => 'Apple', 'B418D1' => 'Apple',
            'B4F0AB' => 'Apple', 'B8782E' => 'Apple', 'B8C75F' => 'Apple',
            'B8E856' => 'Apple', 'BC3BAF' => 'Apple', 'BC4CC4' => 'Apple',
            'BC52B7' => 'Apple', 'C42C03' => 'Apple', 'C88550' => 'Apple',
            'C89CD1' => 'Apple', 'CC08E0' => 'Apple', 'CC29F5' => 'Apple',
            'CCF854' => 'Apple', 'D0034B' => 'Apple', 'D089E9' => 'Apple',
            'D4619D' => 'Apple', 'D4F46F' => 'Apple', 'D8004D' => 'Apple',
            'D801DE' => 'Apple', 'D8196B' => 'Apple', 'D83CE1' => 'Apple',
            'DC2B2A' => 'Apple', 'DC0C5C' => 'Apple', 'E09758' => 'Apple',
            'E4258A' => 'Apple', 'E89895' => 'Apple', 'E8BBD6' => 'Apple',
            'ECE998' => 'Apple', 'F0181D' => 'Apple', 'F0B479' => 'Apple',
            'F0D1A9' => 'Apple', 'F40F24' => 'Apple', 'F4F15A' => 'Apple',
            'F81EDF' => 'Apple', 'FC253F' => 'Apple', 'FCE998' => 'Apple',

            // ── Samsung ──────────────────────────────────────────────────
            '0007AB' => 'Samsung', '001247' => 'Samsung', '001377' => 'Samsung',
            '0015B9' => 'Samsung', '0016DB' => 'Samsung', '0017C9' => 'Samsung',
            '001A8A' => 'Samsung', '001B98' => 'Samsung', '001C43' => 'Samsung',
            '001D25' => 'Samsung', '001E7D' => 'Samsung', '001FCC' => 'Samsung',
            '002119' => 'Samsung', '0021D2' => 'Samsung', '002339' => 'Samsung',
            '002454' => 'Samsung', '002637' => 'Samsung', '002C9D' => 'Samsung',
            '04180F' => 'Samsung', '08373D' => 'Samsung', '08D42B' => 'Samsung',
            '0C1420' => 'Samsung', '101DC0' => 'Samsung', '102F6B' => 'Samsung',
            '1489FD' => 'Samsung', '183F47' => 'Samsung', '18895B' => 'Samsung',
            '1C66AA' => 'Samsung', '2013E0' => 'Samsung', '205531' => 'Samsung',
            '209FDB' => 'Samsung', '244B81' => 'Samsung', '28BAB5' => 'Samsung',
            '2CAE2B' => 'Samsung', '301966' => 'Samsung', '342387' => 'Samsung',
            '38AA3C' => 'Samsung', '3C5AB4' => 'Samsung', '400E85' => 'Samsung',
            '44783E' => 'Samsung', '44F459' => 'Samsung', '48137E' => 'Samsung',
            '4C3C16' => 'Samsung', '5001BB' => 'Samsung', '5440AD' => 'Samsung',
            '58C38B' => 'Samsung', '5CA39D' => 'Samsung', '606BBD' => 'Samsung',
            '64B310' => 'Samsung', '682737' => 'Samsung', '6CF373' => 'Samsung',
            '70F927' => 'Samsung', '7413EA' => 'Samsung', '74458A' => 'Samsung',
            '789ED0' => 'Samsung', '7C1C68' => 'Samsung', '80656D' => 'Samsung',
            '8425DB' => 'Samsung', '8455A5' => 'Samsung', '88329B' => 'Samsung',
            '8C7712' => 'Samsung', '90187C' => 'Samsung', '94350A' => 'Samsung',
            '9852B1' => 'Samsung', '9C3AAF' => 'Samsung', 'A00798' => 'Samsung',
            'A0821F' => 'Samsung', 'A4EBD3' => 'Samsung', 'A89CED' => 'Samsung',
            'AC5F3E' => 'Samsung', 'B072BF' => 'Samsung', 'B43A28' => 'Samsung',
            'B8BC1B' => 'Samsung', 'BC1485' => 'Samsung', 'C09727' => 'Samsung',
            'C44202' => 'Samsung', 'C819F7' => 'Samsung', 'CCF954' => 'Samsung',
            'D059E4' => 'Samsung', 'D4E8B2' => 'Samsung', 'D857EF' => 'Samsung',
            'DC7196' => 'Samsung', 'E4121D' => 'Samsung', 'E47CF9' => 'Samsung',
            'E8039A' => 'Samsung', 'EC1D8B' => 'Samsung', 'F008F1' => 'Samsung',
            'F47B5E' => 'Samsung', 'F8D027' => 'Samsung', 'FC0012' => 'Samsung',
            '40F3DC' => 'Samsung', 'F05A09' => 'Samsung', 'B4EF39' => 'Samsung',
            '7C0BC6' => 'Samsung', 'B8692A' => 'Samsung', '80A902' => 'Samsung',

            // ── Huawei ───────────────────────────────────────────────────
            '001882' => 'Huawei', '001E10' => 'Huawei', '0022A1' => 'Huawei',
            '00259E' => 'Huawei', '0034FE' => 'Huawei', '00464B' => 'Huawei',
            '005A13' => 'Huawei', '00664B' => 'Huawei', '00E0FC' => 'Huawei',
            '04021F' => 'Huawei', '04B0E7' => 'Huawei', '04BD70' => 'Huawei',
            '04F938' => 'Huawei', '081076' => 'Huawei', '0819A6' => 'Huawei',
            '087A4C' => 'Huawei', '089B4B' => 'Huawei', '0C37DC' => 'Huawei',
            '0C96BF' => 'Huawei', '101B54' => 'Huawei', '102C6B' => 'Huawei',
            '149FE8' => 'Huawei', '18C58A' => 'Huawei', '1C1D67' => 'Huawei',
            '2008ED' => 'Huawei', '20F3A3' => 'Huawei', '241FA0' => 'Huawei',
            '2469A5' => 'Huawei', '24C696' => 'Huawei', '283152' => 'Huawei',
            '286ED4' => 'Huawei', '2C9D1E' => 'Huawei', '304596' => 'Huawei',
            '346AC2' => 'Huawei', '38378B' => 'Huawei', '3CF808' => 'Huawei',
            '40CBA8' => 'Huawei', '4455B1' => 'Huawei', '480031' => 'Huawei',
            '485702' => 'Huawei', '4C5499' => 'Huawei', '4CABF8' => 'Huawei',
            '5001D9' => 'Huawei', '50680A' => 'Huawei', '54511B' => 'Huawei',
            '548998' => 'Huawei', '54A51B' => 'Huawei', '582AF7' => 'Huawei',
            '58605F' => 'Huawei', '5C7D5E' => 'Huawei', '60BC44' => 'Huawei',
            '60DFA9' => 'Huawei', '64136C' => 'Huawei', '68A0F6' => 'Huawei',
            '6C4B90' => 'Huawei', '7072CF' => 'Huawei', '74A02F' => 'Huawei',
            '781DBA' => 'Huawei', '7CA7B0' => 'Huawei', '8038FD' => 'Huawei',
            '80D09B' => 'Huawei', '84DB2F' => 'Huawei', '884AEA' => 'Huawei',
            '88CF98' => 'Huawei', '8C0D76' => 'Huawei', '8C34FD' => 'Huawei',
            '9017AC' => 'Huawei', '94772B' => 'Huawei', '98523D' => 'Huawei',
            '9C28EF' => 'Huawei', 'A086C6' => 'Huawei', 'A4CAA0' => 'Huawei',
            'A8CA7B' => 'Huawei', 'AC4E91' => 'Huawei', 'B0E5ED' => 'Huawei',
            'B41513' => 'Huawei', 'B48655' => 'Huawei', 'B808CF' => 'Huawei',
            'BC25E0' => 'Huawei', 'C070CF' => 'Huawei', 'C4072F' => 'Huawei',
            'C4F081' => 'Huawei', 'C81479' => 'Huawei', 'CC96A0' => 'Huawei',
            'D07AB5' => 'Huawei', 'D420B0' => 'Huawei', 'D46E5C' => 'Huawei',
            'DCD2FC' => 'Huawei', 'E0191D' => 'Huawei', 'E02481' => 'Huawei',
            'E037BF' => 'Huawei', 'E40EEE' => 'Huawei', 'E8CD2D' => 'Huawei',
            'ECCB30' => 'Huawei', 'F07959' => 'Huawei', 'F48E92' => 'Huawei',
            'F4CBA4' => 'Huawei', 'F823B2' => 'Huawei', 'F83DFF' => 'Huawei',
            'FC48EF' => 'Huawei',

            // ── Honor (ex-Huawei) ─────────────────────────────────────────
            '0CDE9A' => 'Honor', '4CCB0E' => 'Honor', '7CF2FB' => 'Honor',
            '98C3DE' => 'Honor', 'A0F6FD' => 'Honor', 'DC0059' => 'Honor',

            // ── Xiaomi / Redmi / POCO ─────────────────────────────────────
            '009EC8' => 'Xiaomi', '00EC0A' => 'Xiaomi', '04CF8C' => 'Xiaomi',
            '08D46A' => 'Xiaomi', '0C1DAF' => 'Xiaomi', '102AB3' => 'Xiaomi',
            '14F65A' => 'Xiaomi', '185936' => 'Xiaomi', '1C5F2B' => 'Xiaomi',
            '2034FB' => 'Xiaomi', '24181D' => 'Xiaomi', '286C07' => 'Xiaomi',
            '2CB05D' => 'Xiaomi', '30AA22' => 'Xiaomi', '3480B3' => 'Xiaomi',
            '384608' => 'Xiaomi', '3CBD3E' => 'Xiaomi', '40313C' => 'Xiaomi',
            '44A160' => 'Xiaomi', '483452' => 'Xiaomi', '4C49E3' => 'Xiaomi',
            '508F4C' => 'Xiaomi', '544810' => 'Xiaomi', '584498' => 'Xiaomi',
            '5CA86A' => 'Xiaomi', '601BB4' => 'Xiaomi', '640980' => 'Xiaomi',
            '68DFDD' => 'Xiaomi', '6C4020' => 'Xiaomi', '703A51' => 'Xiaomi',
            '742344' => 'Xiaomi', '7802F8' => 'Xiaomi', '7C1E52' => 'Xiaomi',
            '8035C1' => 'Xiaomi', '840D8E' => 'Xiaomi', '88C9D0' => 'Xiaomi',
            '8CBEBE' => 'Xiaomi', '90C172' => 'Xiaomi', '947533' => 'Xiaomi',
            '98FAE3' => 'Xiaomi', '9C99A0' => 'Xiaomi', 'A053F1' => 'Xiaomi',
            'A473CC' => 'Xiaomi', 'A81374' => 'Xiaomi', 'ACC1EE' => 'Xiaomi',
            'B0E235' => 'Xiaomi', 'B48C9D' => 'Xiaomi', 'B81EA4' => 'Xiaomi',
            'BC9A7A' => 'Xiaomi', 'C0EEFB' => 'Xiaomi', 'C40BCB' => 'Xiaomi',
            'C89AD9' => 'Xiaomi', 'CC2DE0' => 'Xiaomi', 'D05875' => 'Xiaomi',
            'D4970B' => 'Xiaomi', 'D8C771' => 'Xiaomi', 'DC86D8' => 'Xiaomi',
            'E0B655' => 'Xiaomi', 'E446DA' => 'Xiaomi', 'E8EB1B' => 'Xiaomi',
            'ECD09F' => 'Xiaomi', 'F049B5' => 'Xiaomi',

            // ── Oppo / Realme ─────────────────────────────────────────────
            '001B44' => 'Oppo', '040D84' => 'Oppo', '10683F' => 'Oppo',
            '14CF92' => 'Oppo', '1C77F6' => 'Oppo', '20DBAB' => 'Oppo',
            '24DF6A' => 'Oppo', '283DC2' => 'Oppo', '2CE412' => 'Oppo',
            '30CBF8' => 'Oppo', '34E78E' => 'Oppo', '386BBB' => 'Oppo',
            '3C846A' => 'Oppo', '40CBC0' => 'Oppo', '443553' => 'Oppo',
            '487D2E' => 'Oppo', '4C0FC7' => 'Oppo', '50FC9F' => 'Oppo',
            '544A16' => 'Oppo', '58A2B5' => 'Oppo', '5C353B' => 'Oppo',
            '606EE8' => 'Oppo', '640BCA' => 'Oppo', '686F2F' => 'Oppo',
            '6CF049' => 'Oppo', '703ACB' => 'Oppo', '7453F7' => 'Oppo',
            '78A3E4' => 'Oppo', '7C8BCA' => 'Oppo', '805A04' => 'Oppo',
            '84A153' => 'Oppo', '8821E5' => 'Oppo', '8C0DF0' => 'Oppo',
            '90B686' => 'Oppo', '941700' => 'Oppo', '9836BD' => 'Oppo',
            '9C3859' => 'Oppo', 'A04425' => 'Oppo', 'A40CC3' => 'Oppo',
            'A81FA5' => 'Oppo', 'AC43D8' => 'Oppo', 'B0A3F2' => 'Oppo',
            'B419F1' => 'Oppo', 'B8721D' => 'Oppo', 'BC07BA' => 'Oppo',
            'C0143D' => 'Oppo', 'C4DEE2' => 'Oppo', 'C8FF28' => 'Oppo',
            'CCA223' => 'Oppo', 'D00401' => 'Oppo', 'D8D9C0' => 'Oppo',
            'E00E22' => 'Oppo', 'E46ADA' => 'Oppo', 'EC08C3' => 'Oppo',
            'F0421C' => 'Oppo', 'F49554' => 'Oppo', 'F85C7D' => 'Oppo',
            // Realme (sous-marque Oppo)
            '2004F0' => 'Realme', '3C2293' => 'Realme', '407AB5' => 'Realme',
            '583A52' => 'Realme', '7C4CA5' => 'Realme', '8CFE74' => 'Realme',
            'A8346A' => 'Realme', 'CC40D0' => 'Realme', 'DCC02B' => 'Realme',

            // ── Vivo ─────────────────────────────────────────────────────
            '002A4A' => 'Vivo', '146C7E' => 'Vivo', '1C8BC0' => 'Vivo',
            '208984' => 'Vivo', '2C857A' => 'Vivo', '30074D' => 'Vivo',
            '3826C5' => 'Vivo', '44D1FA' => 'Vivo', '502B73' => 'Vivo',
            '5CB301' => 'Vivo', '649ABE' => 'Vivo', '6C55E8' => 'Vivo',
            '78A49C' => 'Vivo', '80E4DA' => 'Vivo', '88D3A8' => 'Vivo',
            '9039F0' => 'Vivo', '9C79B5' => 'Vivo', 'A4BDA1' => 'Vivo',
            'ACEDB8' => 'Vivo', 'B44CD5' => 'Vivo', 'BCE343' => 'Vivo',
            'C470AB' => 'Vivo', 'CC7BEA' => 'Vivo', 'D43A2C' => 'Vivo',
            'DC1BA1' => 'Vivo', 'E4956E' => 'Vivo',

            // ── Tecno (Transsion) ─────────────────────────────────────────
            '00253C' => 'Tecno', '103B59' => 'Tecno', '244D0B' => 'Tecno',
            '2C543D' => 'Tecno', '4045DA' => 'Tecno', '50C7BF' => 'Tecno',
            '646266' => 'Tecno', '78F681' => 'Tecno', '909710' => 'Tecno',
            'B06EBF' => 'Tecno', 'C8A70E' => 'Tecno', 'D0164E' => 'Tecno',
            '5C313A' => 'Tecno', '24F27F' => 'Tecno', 'B89A2A' => 'Tecno',
            // Infinix
            '0403D6' => 'Infinix', '188796' => 'Infinix', '2CF0EE' => 'Infinix',
            '444E1A' => 'Infinix', '54A6C8' => 'Infinix', '7CB27D' => 'Infinix',
            '8C59C3' => 'Infinix', '9C65B0' => 'Infinix', 'ACF7F3' => 'Infinix',
            'D4C804' => 'Infinix', '607ACE' => 'Infinix',
            // Itel
            '00D869' => 'Itel', '3CC4B7' => 'Itel', '68866A' => 'Itel',
            'A088C2' => 'Itel', 'C49CC7' => 'Itel',

            // ── Nokia / HMD Global ────────────────────────────────────────
            '0002EE' => 'Nokia', '000AD9' => 'Nokia', '0014A8' => 'Nokia',
            '001A6C' => 'Nokia', '001BAF' => 'Nokia', '001F00' => 'Nokia',
            '002140' => 'Nokia', '00E18C' => 'Nokia', '0CC6CC' => 'Nokia',
            '1458D0' => 'Nokia', '181F7E' => 'Nokia', '1C336A' => 'Nokia',
            '201404' => 'Nokia', '282C02' => 'Nokia', '2C01CB' => 'Nokia',
            '300705' => 'Nokia', '344DEA' => 'Nokia', '3C970E' => 'Nokia',
            '40B837' => 'Nokia', '44ECCE' => 'Nokia', '4801C5' => 'Nokia',
            '4CBCA5' => 'Nokia', '503DC5' => 'Nokia', '5405DB' => 'Nokia',
            '58EE2E' => 'Nokia', '5C4627' => 'Nokia', '605DC7' => 'Nokia',
            '681DEF' => 'Nokia', '6CEAA8' => 'Nokia', '7093F8' => 'Nokia',
            '74251B' => 'Nokia', '78563A' => 'Nokia', '7C6E67' => 'Nokia',
            '800B24' => 'Nokia', '84E892' => 'Nokia', '88B4A6' => 'Nokia',
            '8C984F' => 'Nokia', '90086F' => 'Nokia', '94659C' => 'Nokia',
            '9844A2' => 'Nokia', '9C7444' => 'Nokia', 'A0C8A3' => 'Nokia',
            'A4C85C' => 'Nokia', 'A87650' => 'Nokia', 'AC2078' => 'Nokia',
            'B03CDC' => 'Nokia', 'B482FE' => 'Nokia', 'B88687' => 'Nokia',
            'BCF5AC' => 'Nokia', 'C05D89' => 'Nokia', 'C4917C' => 'Nokia',
            'C89665' => 'Nokia', 'CC9C71' => 'Nokia', 'D06BC6' => 'Nokia',
            'D4827B' => 'Nokia', 'D84732' => 'Nokia', 'DC00F3' => 'Nokia',
            'E02538' => 'Nokia', 'E42771' => 'Nokia', 'E80B6E' => 'Nokia',
            'ECE1A9' => 'Nokia', 'F02800' => 'Nokia', 'F48A4F' => 'Nokia',

            // ── Motorola ──────────────────────────────────────────────────
            '000B6B' => 'Motorola', '001156' => 'Motorola', '0018A4' => 'Motorola',
            '001E81' => 'Motorola', '00A0F7' => 'Motorola', '0C7BF0' => 'Motorola',
            '1C2E8A' => 'Motorola', '2067F1' => 'Motorola', '30F72A' => 'Motorola',
            '346895' => 'Motorola', '40FDEF' => 'Motorola', '4C80D7' => 'Motorola',
            '5CABFD' => 'Motorola', '6C0E0D' => 'Motorola', '80E8F2' => 'Motorola',
            '842A85' => 'Motorola', 'A88600' => 'Motorola', 'CC2D8C' => 'Motorola',
            'D4CFBA' => 'Motorola', 'E4B021' => 'Motorola', 'FC4B5C' => 'Motorola',

            // ── Lenovo ────────────────────────────────────────────────────
            '001018' => 'Lenovo', '08BE45' => 'Lenovo', '103084' => 'Lenovo',
            '1479E8' => 'Lenovo', '2C44FD' => 'Lenovo', '346432' => 'Lenovo',
            '3C4F65' => 'Lenovo', '48FABC' => 'Lenovo', '506788' => 'Lenovo',
            '5C9711' => 'Lenovo', '684FE8' => 'Lenovo', '6CE8B5' => 'Lenovo',
            '7006CB' => 'Lenovo', '785A05' => 'Lenovo', '7CA129' => 'Lenovo',
            '80E82C' => 'Lenovo', '8414CB' => 'Lenovo', '8C0D3A' => 'Lenovo',
            '98FA9B' => 'Lenovo', '9C2DB9' => 'Lenovo', 'A4CCCC' => 'Lenovo',
            'A8E31E' => 'Lenovo', 'AC5E51' => 'Lenovo', 'B89DC4' => 'Lenovo',
            'C050D9' => 'Lenovo', 'C4A453' => 'Lenovo', 'D0277B' => 'Lenovo',
            'D0ABD5' => 'Lenovo', 'D4E882' => 'Lenovo', 'DC7FA4' => 'Lenovo',
            'E899C4' => 'Lenovo', 'EC8531' => 'Lenovo', 'F04BE4' => 'Lenovo',
            'F41BA1' => 'Lenovo', 'F48B32' => 'Lenovo',

            // ── Google / Pixel ────────────────────────────────────────────
            '1C8261' => 'Google Pixel', '3C5AB4' => 'Google', '40B8C7' => 'Google',
            '48D2A6' => 'Google Pixel', '4CE1B3' => 'Google', '641CB0' => 'Google',
            '74A93B' => 'Google Pixel', '94ECDA' => 'Google',  'A4773F' => 'Google',
            'F43A95' => 'Google Pixel',

            // ── OnePlus ───────────────────────────────────────────────────
            '040D84' => 'OnePlus', '283438' => 'OnePlus', '4CBBF5' => 'OnePlus',
            '5CC5B0' => 'OnePlus', '7CC3A1' => 'OnePlus', 'ACB9B9' => 'OnePlus',
            'E86DC8' => 'OnePlus',

            // ── Asus ─────────────────────────────────────────────────────
            '000C6E' => 'Asus', '001167' => 'Asus', '001E8C' => 'Asus',
            '002215' => 'Asus', '002401' => 'Asus', '00265F' => 'Asus',
            '107B44' => 'Asus', '10BF48' => 'Asus', '1C1BDA' => 'Asus',
            '1CB72C' => 'Asus', '2CEFD0' => 'Asus', '50465D' => 'Asus',
            '5C514F' => 'Asus', '60A44C' => 'Asus', '74D02B' => 'Asus',
            '788CB5' => 'Asus', '7C6D62' => 'Asus', '886204' => 'Asus',
            '90E6BA' => 'Asus', 'A8F7E0' => 'Asus', 'B06EBF' => 'Asus',
            'BC9741' => 'Asus', 'C86000' => 'Asus', 'D8D9C0' => 'Asus',

            // ── LG ───────────────────────────────────────────────────────
            '0019AB' => 'LG', '001C62' => 'LG', '001E75' => 'LG',
            '001FE3' => 'LG', '002483' => 'LG', '00265D' => 'LG',
            '04D3B0' => 'LG', '10F96F' => 'LG', '1C30DA' => 'LG',
            '1CA1C9' => 'LG', '20375B' => 'LG', '34FC6F' => 'LG',
            '40B0FA' => 'LG', '4C4C05' => 'LG', '50538A' => 'LG',
            '54F201' => 'LG', '606BAD' => 'LG', '6868AC' => 'LG',
            '6CCAB3' => 'LG', '74BB0B' => 'LG', '788CDB' => 'LG',
            '7C1C4E' => 'LG', '84809C' => 'LG', '889FF0' => 'LG',
            '9086AA' => 'LG', '9C3426' => 'LG', 'A06FAA' => 'LG',
            'A8ECB3' => 'LG', 'B88AEC' => 'LG', 'C40BCB' => 'LG',
            'CC2D85' => 'LG', 'D0272D' => 'LG', 'D4901B' => 'LG',
            'EC9B5B' => 'LG', 'F80CF3' => 'LG',

            // ── Sony ─────────────────────────────────────────────────────
            '000EAB' => 'Sony', '001025' => 'Sony', '0013A9' => 'Sony',
            '00170B' => 'Sony', '001A80' => 'Sony', '001C8F' => 'Sony',
            '002125' => 'Sony', '0023DF' => 'Sony', '002566' => 'Sony',
            '0026D6' => 'Sony', '10DBBD' => 'Sony', '28E319' => 'Sony',
            '2C6F7F' => 'Sony', '34AA8B' => 'Sony', '3866F0' => 'Sony',
            '40B7C4' => 'Sony', '5CEF86' => 'Sony', '64D4DA' => 'Sony',
            '70EF00' => 'Sony', '782E00' => 'Sony', '84CF73' => 'Sony',
            '9416FE' => 'Sony', '9C4E36' => 'Sony', 'A45FC0' => 'Sony',
            'A8EB7B' => 'Sony', 'B4528A' => 'Sony', 'E04F43' => 'Sony',
            'EC3764' => 'Sony', 'FC0FE6' => 'Sony',
        ];
    }
}
