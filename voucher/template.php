<?php
// Traitement du prix (identique à l'original)
$priceClean = preg_replace('/[^0-9]/', '', $price);
$priceRaw = intval($priceClean);
$priceValue = intval($priceRaw);

// Choix de la classe de couleur (couleurs vives, sans dégradé)
$colorClass = "bg-default";
switch ($priceValue) {
    case 100:  $colorClass = "bg-100";  break;
    case 200:  $colorClass = "bg-200";  break;
    case 300:  $colorClass = "bg-300";  break;
    case 500:  $colorClass = "bg-500";  break;
    case 700:  $colorClass = "bg-700";  break;
    case 1000: $colorClass = "bg-1000"; break;
    case 1500: $colorClass = "bg-1500"; break;
    case 2500: $colorClass = "bg-2500"; break;
    case 3000: $colorClass = "bg-3000"; break;
    default:   $colorClass = "bg-default";
}

// Affichage de la validité (tel quel, déjà formaté)
$validityDisplay = "";
if (!empty($validity)) {
    $validityLower = strtolower(trim($validity));
    if (preg_match('/^(\\d+)h$/', $validityLower, $matches)) {
        $validityDisplay = sprintf("%02d-HEURES", intval($matches[1]));
    } elseif (preg_match('/^(\\d+)d$/', $validityLower, $matches)) {
        $days = intval($matches[1]);
        switch ($days) {
            case 1:  $validityDisplay = "01-JOUR"; break;
            case 3:  $validityDisplay = "03-JOURS"; break;
            case 5:  $validityDisplay = "05-JOURS"; break;
            case 7:  $validityDisplay = "01-SEMAINE"; break;
            case 10: $validityDisplay = "10-JOURS"; break;
            case 14: $validityDisplay = "02-SEMAINES"; break;
            case 30: $validityDisplay = "01-MOIS"; break;
            case 60: $validityDisplay = "02-MOIS"; break;
            case 90: $validityDisplay = "03-MOIS"; break;
            default: $validityDisplay = sprintf("%02d-JOURS", $days);
        }
    } else {
        $validityDisplay = strtoupper($validity);
    }
}

// Affichage du prix (espaces tous les 3 chiffres si >= 1000)
$displayPrice = "";
if ($priceValue > 0) {
    $displayPrice = $priceValue >= 1000
        ? number_format($priceValue, 0, '', ' ')
        : strval($priceValue);
}
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@500;700;800&display=swap');

    /* --- Couleurs unies et vives (pas de dégradé) --- */
    .bg-100  { background: #FF4136; }  /* rouge vif */
    .bg-200  { background: #FF851B; }  /* orange */
    .bg-300  { background: #FFDC00; }  /* jaune */
    .bg-500  { background: #2ECC40; }  /* vert vif */
    .bg-700  { background: #39CCCC; }  /* cyan */
    .bg-1000 { background: #0074D9; }  /* bleu profond */
    .bg-1500 { background: #B10DC9; }  /* violet */
    .bg-2500 { background: #F012BE; }  /* magenta */
    .bg-3000 { background: #85144b; }  /* bordeaux */
    .bg-default { background: #AAAAAA; } /* gris moyen */

    @page {
        size: A4;
        margin: 5mm;
    }

    @media print {
        body { margin: 0; padding: 0; }
        .voucher-mini {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            page-break-inside: avoid;
        }
    }

    .voucher-mini {
        display: inline-flex;
        flex-direction: column;
        width: 142px;
        height: 82px;
        margin: 2px;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        overflow: hidden;
        box-sizing: border-box;
        font-family: 'Outfit', sans-serif;
        vertical-align: top;
        float: left;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .voucher-header {
        color: white;
        padding: 2px 4px;
        text-align: center;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .hotspot-name {
        font-size: 8px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 14px;
        width: 100%;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }

    .voucher-body {
        padding: 2px 2px 1px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: center;
        background: white;
    }

    .code-box {
        background: #f8fafc;
        border: 1px dashed #cbd5e0;
        border-radius: 4px;
        padding: 2px 5px;
        margin: 1px 0;
        width: 92%;
        text-align: center;
    }

    .code-text {
        font-size: 16px;
        font-weight: 800;
        color: #1e293b;
        word-break: break-all;
        line-height: 1.1;
        letter-spacing: 0.5px;
    }

    .voucher-label {
        font-size: 6px;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0;
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    .up-container {
        width: 100%;
        padding: 0 3px;
    }

    .up-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        padding: 1px 4px;
        box-sizing: border-box;
    }

    .up-row:first-child {
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 2px;
        margin-bottom: 1px;
    }

    .up-key {
        color: #64748b;
        font-size: 7px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .up-val {
        font-size: 10px;
        font-weight: 800;
        color: #1e293b;
        word-break: break-all;
        max-width: 70%;
        text-align: right;
    }

    .footer-meta {
        display: flex;
        gap: 3px;
        margin-top: 2px;
        flex-wrap: wrap;
        justify-content: center;
        align-items: center;
    }

    .meta-badge {
        font-size: 8px;
        background: #f1f5f9;
        padding: 1px 4px;
        border-radius: 3px;
        color: #334155;
        font-weight: 700;
        white-space: nowrap;
        border: 1px solid #e2e8f0;
        letter-spacing: 0.2px;
    }

    .meta-badge.price {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }

    .meta-badge.validity {
        background: #dbeafe;
        color: #1e40af;
        border-color: #bfdbfe;
    }

    .seller-line {
        margin-top: 1px;
        max-width: 94%;
        font-size: 6px;
        line-height: 1;
        color: #64748b;
        font-weight: 700;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .seller-line strong {
        color: #334155;
        font-weight: 800;
    }
</style>

<div class="voucher-mini">
    <div class="voucher-header <?= $colorClass; ?>">
        <div class="hotspot-name">
            <?= htmlspecialchars($hotspotname, ENT_QUOTES, 'UTF-8'); ?>
            <span style="font-weight:400; opacity:0.85;">[<?= htmlspecialchars($num, ENT_QUOTES, 'UTF-8'); ?>]</span>
        </div>
    </div>

    <div class="voucher-body">
        <?php if ($usermode === "up") { ?>
            <div class="voucher-label">Identifiants Membre</div>
            <div class="up-container">
                <div class="up-row">
                    <span class="up-key">User</span>
                    <span class="up-val"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="up-row">
                    <span class="up-key">Pass</span>
                    <span class="up-val"><?= htmlspecialchars($password, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } else { ?>
            <div class="voucher-label">Code Ticket</div>
            <div class="code-box">
                <div class="code-text"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php } ?>

        <div class="footer-meta">
            <?php if (!empty($validityDisplay)) { ?>
                <span class="meta-badge validity"><?= htmlspecialchars($validityDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php } ?>
            <?php if (!empty($displayPrice)) { ?>
                <span class="meta-badge price"><?= htmlspecialchars($displayPrice, ENT_QUOTES, 'UTF-8'); ?> F</span>
            <?php } ?>
        </div>

        <?php if (!empty($voucherSellerName)) { ?>
            <div class="seller-line">
                <strong><?= isset($_sold_by) ? $_sold_by : 'Sold by'; ?>:</strong>
                <?= htmlspecialchars($voucherSellerName, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php } ?>
    </div>
</div>
