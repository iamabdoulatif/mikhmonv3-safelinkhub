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
    <link rel="icon" href="./img/favicon.png" />
    <script src="js/jquery.min.js"></script>
    <link href="css/pace.<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>.css" rel="stylesheet" />
    <script src="js/pace.min.js"></script>
  </head>
  <body class="auth-screen">
    <div class="portal-auth-wrap" style="max-width:400px;margin:0 auto;padding:5% 0 32px;min-height:auto;">
      <div class="login-card card portal-auth-card portal-auth-card-sm" style="width:100%;max-width:400px;margin:0 auto;">
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
                     name="user" placeholder="Username" required autofocus>
              <input class="login-field form-control" type="password"
                     name="pass" placeholder="Password" required>
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
                     name="manager_pass" placeholder="Password" required>
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
                     name="seller_pass" placeholder="Password" required>
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
