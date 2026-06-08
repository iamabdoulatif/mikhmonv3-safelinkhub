<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  Login page – Admin / Gérant / Vendeur unified portal
 */
include_once('./include/csrf.php');
$loginHotspotName = isset($hotspotname) && $hotspotname !== '' ? ' ' . $hotspotname : '';
?>
<!DOCTYPE html>
<html>
  <head>
    <title>MIKHMON<?= htmlspecialchars($loginHotspotName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta charset="utf-8">
    <meta http-equiv="cache-control" content="private" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= htmlspecialchars($themecolor, ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="stylesheet" type="text/css" href="css/font-awesome/css/font-awesome.min.css" />
    <link rel="stylesheet" href="css/mikhmon-ui.<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>.min.css">
    <link rel="stylesheet" href="css/mikhmon-portal.css">
    <link rel="stylesheet" href="css/mikhmon-responsive.css">
    <link rel="icon" href="./img/favicon.png" />
    <script src="js/jquery.min.js"></script>
    <link href="css/pace.<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>.css" rel="stylesheet" />
    <script src="js/pace.min.js"></script>
    <style>
    /* ═══════════════════════════════════════════════════════════════
       SafeLink Africa – Login Page Redesign
       Palette :
         Corail  #F06030  (couleur primaire logo)
         Ambre   #F5A020  (chaleur africaine, accent)
         Teal    #1ABC9C  (complémentaire moderne)
         Nuit    #0F1923  (fond premium)
    ═══════════════════════════════════════════════════════════════ */

    /* ── FOND ───────────────────────────────────────────────────── */
    .auth-screen {
      background: linear-gradient(145deg, #0F1923 0%, #162030 45%, #0D1620 100%) !important;
      min-height: 100vh;
      position: relative;
      overflow: hidden;
    }

    /* Halos lumineux décoratifs inspirés du logo */
    .auth-screen::before {
      content: '';
      position: fixed;
      top: -10%;
      right: -5%;
      width: 420px;
      height: 420px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(240,96,48,.10) 0%, transparent 70%);
      pointer-events: none;
    }
    .auth-screen::after {
      content: '';
      position: fixed;
      bottom: -10%;
      left: -5%;
      width: 380px;
      height: 380px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(245,160,32,.07) 0%, transparent 70%);
      pointer-events: none;
    }

    /* ── WRAPPER ─────────────────────────────────────────────────── */
    .auth-screen .portal-auth-wrap {
      padding: 5vh 16px 32px !important;
      align-items: center !important;
      min-height: 100vh;
    }

    /* ── CARTE ───────────────────────────────────────────────────── */
    .auth-screen .login-card.card {
      background: #FFFFFF !important;
      border: none !important;
      border-radius: 18px !important;
      box-shadow:
        0 32px 64px rgba(0,0,0,.45),
        0 0 0 1px rgba(240,96,48,.08),
        0 0 120px rgba(240,96,48,.04) !important;
      overflow: hidden;
    }

    /* ── EN-TÊTE CARTE ────────────────────────────────────────────── */
    .auth-screen .login-card .card-header {
      background: linear-gradient(135deg, #F06030 0%, #F5A020 100%) !important;
      border: none !important;
      border-radius: 0 !important;
      padding: 14px 20px 12px !important;
    }
    .auth-screen .login-card .card-header h3 {
      color: #fff !important;
      font-size: 14px !important;
      font-weight: 600 !important;
      letter-spacing: .04em;
      text-transform: uppercase;
      opacity: .95;
    }

    /* ── CORPS CARTE ─────────────────────────────────────────────── */
    .auth-screen .login-card-body {
      padding: 22px 24px 14px !important;
      background: #FFFFFF;
    }

    /* ── ZONE LOGO ───────────────────────────────────────────────── */
    .auth-screen .login-logo {
      text-align: center;
      margin-bottom: 18px;
      padding-bottom: 16px;
      border-bottom: 1px solid rgba(240,96,48,.10);
    }
    .auth-screen .login-logo img {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(240,96,48,.15);
    }
    .auth-screen .login-logo span {
      display: block;
      font-size: 22px !important;
      font-weight: 800 !important;
      color: #1C2833 !important;
      margin-top: 10px !important;
      letter-spacing: -.01em;
    }
    .auth-screen .login-logo-subtitle {
      font-size: 11px !important;
      font-weight: 600 !important;
      color: #F06030 !important;
      opacity: 1 !important;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .auth-screen .login-logo-contact {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      margin-top: 6px;
      font-size: 12px;
      font-weight: 600;
      color: #7F8C8D !important;
      letter-spacing: .03em;
    }
    .auth-screen .login-logo-contact::before {
      content: '\f095';  /* fa-phone */
      font-family: FontAwesome;
      font-size: 10px;
      color: #1ABC9C;
    }

    /* ── ONGLETS ─────────────────────────────────────────────────── */
    .auth-screen .login-tabs {
      border-bottom: 2px solid #F2F4F5 !important;
      margin-bottom: 16px !important;
      background: #FAFBFC;
      border-radius: 8px 8px 0 0;
    }
    .auth-screen .login-tab-btn {
      font-size: 12px !important;
      font-weight: 700 !important;
      padding: 10px 4px !important;
      border-radius: 0 !important;
      color: #BDC3C7 !important;
      letter-spacing: .02em;
      transition: color .2s, border-color .2s !important;
    }
    .auth-screen .login-tab-btn i { font-size: 15px !important; }

    .auth-screen .login-tab-btn.tab-admin {
      color: #F06030 !important;
      border-bottom-color: #F06030 !important;
    }
    .auth-screen .login-tab-btn.tab-manager {
      color: #F5A020 !important;
      border-bottom-color: #F5A020 !important;
    }
    .auth-screen .login-tab-btn.tab-vendor {
      color: #1ABC9C !important;
      border-bottom-color: #1ABC9C !important;
    }
    .auth-screen .login-tab-btn.inactive {
      color: #C8D0D8 !important;
      border-bottom-color: transparent !important;
    }

    /* ── BADGES RÔLE ─────────────────────────────────────────────── */
    .auth-screen .badge-admin   { background: #FEF0EB !important; color: #F06030 !important; border: 1px solid rgba(240,96,48,.15) !important; }
    .auth-screen .badge-manager { background: #FEF9E7 !important; color: #D98A00 !important; border: 1px solid rgba(245,160,32,.20) !important; }
    .auth-screen .badge-vendor  { background: #E8F8F5 !important; color: #16A085 !important; border: 1px solid rgba(26,188,156,.20) !important; }

    /* ── CHAMPS DE SAISIE ────────────────────────────────────────── */
    .auth-screen .login-field {
      height: 46px !important;
      font-size: 15px !important;
      padding: 0 14px !important;
      border: 1.5px solid #E8ECF0 !important;
      border-radius: 10px !important;
      background: #F8FAFB !important;
      color: #1C2833 !important;
      transition: border-color .2s, box-shadow .2s, background .2s !important;
      margin-bottom: 10px !important;
    }
    .auth-screen .login-field:focus {
      border-color: #F06030 !important;
      background: #FFFFFF !important;
      box-shadow: 0 0 0 3px rgba(240,96,48,.12) !important;
      outline: none;
    }
    .auth-screen .login-field::placeholder { color: #B2BEC3 !important; }

    /* ── BOUTONS SUBMIT ──────────────────────────────────────────── */
    .auth-screen .login-submit {
      height: 48px !important;
      font-size: 15px !important;
      font-weight: 700 !important;
      letter-spacing: .04em;
      border-radius: 10px !important;
      margin-top: 14px !important;
      border: none !important;
      transition: transform .15s ease, box-shadow .15s ease, opacity .2s !important;
    }
    .auth-screen .login-submit:hover {
      opacity: 1 !important;
      transform: translateY(-2px);
    }
    .auth-screen .login-submit:active { transform: translateY(0); }

    .auth-screen .btn-admin {
      background: linear-gradient(135deg, #F06030 0%, #F07A35 100%) !important;
      color: #fff !important;
      box-shadow: 0 5px 15px rgba(240,96,48,.30);
    }
    .auth-screen .btn-admin:hover {
      box-shadow: 0 8px 24px rgba(240,96,48,.45) !important;
    }
    .auth-screen .btn-manager {
      background: linear-gradient(135deg, #F5A020 0%, #F5C030 100%) !important;
      color: #fff !important;
      box-shadow: 0 5px 15px rgba(245,160,32,.28);
    }
    .auth-screen .btn-manager:hover {
      box-shadow: 0 8px 24px rgba(245,160,32,.40) !important;
    }
    .auth-screen .btn-vendor {
      background: linear-gradient(135deg, #1ABC9C 0%, #16A085 100%) !important;
      color: #fff !important;
      box-shadow: 0 5px 15px rgba(26,188,156,.28);
    }
    .auth-screen .btn-vendor:hover {
      box-shadow: 0 8px 24px rgba(26,188,156,.40) !important;
    }

    /* ── MESSAGE D'ERREUR ────────────────────────────────────────── */
    .auth-screen .login-error {
      border-radius: 8px !important;
      font-size: 13px !important;
      padding: 10px 14px !important;
      margin-top: 10px !important;
    }

    /* ── PIED CARTE (logo SafeLink) ──────────────────────────────── */
    .auth-screen .login-footer {
      padding: 14px 20px 16px !important;
      background: #FAFBFC !important;
      border-top: 1px solid #F0F3F4 !important;
      text-align: center;
    }
    .auth-screen .login-footer img {
      height: 38px !important;
      opacity: 1 !important;
      filter: none !important;
    }
    .auth-screen .login-footer::before {
      content: 'Powered by';
      display: block;
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #AAB3BB;
      margin-bottom: 6px;
    }

    /* ── MOBILE ──────────────────────────────────────────────────── */
    @media (max-width: 480px) {
      .auth-screen .portal-auth-wrap {
        padding: 16px 12px 24px !important;
      }
      .auth-screen .login-card-body {
        padding: 18px 16px 12px !important;
      }
      .auth-screen .login-card.card {
        border-radius: 14px !important;
      }
    }
    </style>
  </head>
  <body class="auth-screen">
    <div class="portal-auth-wrap login-wrap-sm">
      <div class="login-card card portal-auth-card portal-auth-card-sm login-card-sm">
        <div class="card-header text-center">
          <h3><?= isset($_please_login) ? $_please_login : 'Please Login' ?></h3>
        </div>
        <div class="card-body login-card-body">
          <div class="login-logo">
            <img src="img/favicon.png" alt="MIKHMON Logo">
            <span>MIKHMON <small class="login-logo-subtitle">BY SafeLink Africa</small></span>
            <div class="login-logo-contact">+2250709100552</div>
          </div>

          <div class="login-tabs">
            <button id="tab-admin"
                    class="login-tab-btn tab-admin"
                    onclick="switchLoginTab('admin')" type="button">
              <i class="fa fa-shield"></i>
              <?= isset($_admin_tab) ? $_admin_tab : 'Admin' ?>
            </button>
            <button id="tab-manager"
                    class="login-tab-btn inactive"
                    onclick="switchLoginTab('manager')" type="button">
              <i class="fa fa-briefcase"></i>
              <?= isset($_manager_tab) ? $_manager_tab : 'Manager' ?>
            </button>
            <button id="tab-vendor"
                    class="login-tab-btn inactive"
                    onclick="switchLoginTab('vendor')" type="button">
              <i class="fa fa-ticket"></i>
              <?= isset($_vendor_tab) ? $_vendor_tab : 'Vendor' ?>
            </button>
          </div>

          <div id="form-admin">
            <div class="text-center login-role-row">
              <span class="role-badge badge-admin">
                <i class="fa fa-shield"></i> <?= isset($_admin) ? $_admin : 'Admin' ?>
              </span>
            </div>
            <form autocomplete="off" action="" method="post">
              <?= csrf_field() ?>
              <input class="login-field form-control" type="text"
                     name="user" placeholder="<?= isset($_seller_id) ? $_seller_id : 'Username' ?>" required autofocus>
              <input class="login-field form-control" type="password"
                     name="pass" placeholder="<?= isset($_password) ? $_password : 'Password' ?>" required>
              <input class="login-submit btn-admin"
                     type="submit" name="login" value="Login">
              <?php if (!empty($error)): ?>
                <div class="login-error bg-danger"><?= $error ?></div>
              <?php endif; ?>
            </form>
          </div>

          <div id="form-manager" style="display:none;">
            <div class="text-center login-role-row">
              <span class="role-badge badge-manager">
                <i class="fa fa-briefcase"></i> <?= isset($_manager) ? $_manager : 'Manager' ?>
              </span>
            </div>
            <form autocomplete="off" action="" method="post">
              <?= csrf_field() ?>
              <input class="login-field form-control" type="text"
                     name="manager_user"
                     placeholder="<?= isset($_seller_id) ? $_seller_id : 'Identifier' ?>"
                     required>
              <input class="login-field form-control" type="password"
                     name="manager_pass" placeholder="<?= isset($_password) ? $_password : 'Password' ?>" required>
              <input class="login-submit btn-manager"
                     type="submit" name="manager_login"
                     value="<?= isset($_manager_login_title) ? $_manager_login_title : 'Manager Login' ?>">
              <?php if (!empty($error_manager)): ?>
                <div class="login-error bg-danger"><?= $error_manager ?></div>
              <?php endif; ?>
            </form>
          </div>

          <div id="form-vendor" style="display:none;">
            <div class="text-center login-role-row">
              <span class="role-badge badge-vendor">
                <i class="fa fa-ticket"></i> <?= isset($_seller) ? $_seller : 'Vendor' ?>
              </span>
            </div>
            <form autocomplete="off" action="" method="post">
              <?= csrf_field() ?>
              <input class="login-field form-control" type="text"
                     name="seller_user"
                     placeholder="<?= isset($_seller_id) ? $_seller_id : 'Identifier' ?>"
                     required>
              <input class="login-field form-control" type="password"
                     name="seller_pass" placeholder="<?= isset($_password) ? $_password : 'Password' ?>" required>
              <input class="login-submit btn-vendor"
                     type="submit" name="seller_login"
                     value="<?= isset($_seller_login_title) ? $_seller_login_title : 'Vendor Login' ?>">
              <?php if (!empty($error_seller)): ?>
                <div class="login-error bg-danger"><?= $error_seller ?></div>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <div class="card-footer login-footer">
          <img src="img/safelink-africa.png" alt="SafeLink Africa">
        </div>
      </div>
    </div>

    <script>
    function switchLoginTab(tab) {
      var forms = {admin:'form-admin', manager:'form-manager', vendor:'form-vendor'};
      var btns  = {admin:'tab-admin', manager:'tab-manager', vendor:'tab-vendor'};
      var classes = {admin:'tab-admin', manager:'tab-manager', vendor:'tab-vendor'};
      Object.keys(forms).forEach(function(t) {
        document.getElementById(forms[t]).style.display = (t === tab) ? '' : 'none';
        var btn = document.getElementById(btns[t]);
        btn.className = 'login-tab-btn ' + (t === tab ? classes[t] : 'inactive');
      });
    }
    <?php
    if (!empty($error_manager)) {
      echo "switchLoginTab('manager');";
    } elseif (!empty($error_seller)) {
      echo "switchLoginTab('vendor');";
    }
    ?>
    </script>
  </body>
</html>
