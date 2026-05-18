<?php
if (empty($_SESSION['mikhmon'])) {
  header('Location: ./admin.php?id=login');
  exit;
}

require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/safelink_integration.php';

$cfg = safelink_integration_load();
$notice = '';
$error = '';
$apiTest = null;
$webhookTest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_guard('Requête invalide (CSRF).');

  if (isset($_POST['save_safelink_integration'])) {
    $base = safelink_clean_url(isset($_POST['api_base_url']) ? $_POST['api_base_url'] : '');
    $key = trim(isset($_POST['api_key']) ? $_POST['api_key'] : '');
    $secret = trim(isset($_POST['webhook_secret']) ? $_POST['webhook_secret'] : '');
    $outbound = safelink_clean_url(isset($_POST['outbound_webhook_url']) ? $_POST['outbound_webhook_url'] : '');
    $enabled = isset($_POST['enabled']) ? true : false;

    if ($base === '') {
      $error = "URL API SafeLink invalide.";
    } else {
      $cfg['api_base_url'] = $base;
      $cfg['api_key'] = $key;
      $cfg['webhook_secret'] = $secret;
      $cfg['outbound_webhook_url'] = $outbound;
      $cfg['enabled'] = $enabled;
      if (safelink_integration_save($cfg)) {
        $notice = "Configuration SafeLink enregistrée.";
      } else {
        $error = "Impossible d'enregistrer la configuration.";
      }
    }
  }

  if (isset($_POST['test_safelink_api'])) {
    if (empty($cfg['api_key'])) {
      $error = "Ajoutez d'abord votre clé API.";
    } else {
      $url = rtrim($cfg['api_base_url'], '/') . '/api/apikeys';
      $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
      );
      $apiTest = safelink_http_request('GET', $url, $headers, '', 20);
      if (!$apiTest['ok']) {
        $error = "Test API échoué (HTTP " . (int)$apiTest['status'] . ").";
      } else {
        $notice = "Test API réussi.";
      }
    }
  }

  if (isset($_POST['test_outbound_webhook'])) {
    $target = !empty($cfg['outbound_webhook_url']) ? $cfg['outbound_webhook_url'] : '';
    if ($target === '') {
      $error = "Ajoutez d'abord une URL webhook de test.";
    } else {
      $payloadArr = array(
        'event' => 'mikhmon.test',
        'source' => 'mikhmon-mamp',
        'timestamp' => date('c'),
        'session' => isset($_GET['session']) ? (string)$_GET['session'] : '',
        'message' => 'Test webhook depuis Mikhmon',
      );
      $payload = json_encode($payloadArr, JSON_UNESCAPED_SLASHES);
      $headers = array('Content-Type: application/json');
      if (!empty($cfg['webhook_secret'])) {
        $headers[] = 'X-Webhook-Signature: ' . hash_hmac('sha256', $payload, $cfg['webhook_secret']);
      }
      $headers[] = 'X-Webhook-Event: mikhmon.test';
      $webhookTest = safelink_http_request('POST', $target, $headers, $payload, 20);
      if (!$webhookTest['ok']) {
        $error = "Webhook test échoué (HTTP " . (int)$webhookTest['status'] . ").";
      } else {
        $notice = "Webhook test envoyé avec succès.";
      }
    }
  }
}

$localWebhookUrl = safelink_build_local_webhook_url();
?>

<style>
.sl-card {
  background: #f5f7fb;
  border: 1px solid #2f3946;
  border-radius: 10px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18);
  margin-bottom: 14px;
  overflow: hidden;
}
.sl-card .sl-head {
  background: #37424f;
  color: #eaf2ff;
  padding: 14px 18px;
  border-bottom: 1px solid #2f3946;
  font-weight: 700;
  font-size: 17px;
}
.sl-card .sl-body {
  padding: 16px 18px;
  color: #1f2937;
}
.sl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}
.sl-label {
  display: block;
  margin-bottom: 4px;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}
.sl-input, .sl-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #c9d4e2;
  border-radius: 7px;
  font-size: 14px;
  background: #ffffff;
  color: #1f2937;
}
.sl-input[readonly] {
  background: #edf2f7;
}
.sl-actions {
  margin-top: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sl-note {
  background: #edf3fb;
  border: 1px solid #c3d7f1;
  color: #2a4365;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13px;
  line-height: 1.45;
}
.sl-code {
  margin-top: 8px;
  background: #1f2733;
  color: #9ec8ff;
  padding: 10px 12px;
  border-radius: 8px;
  font-family: monospace;
  font-size: 12px;
  overflow-x: auto;
}
.sl-btn-primary {
  background: #4fa8dc;
  color: #fff;
  border: 1px solid #4698c7;
}
.sl-btn-primary:hover { background: #4297c8; color: #fff; }
.sl-btn-dark {
  background: #3f4754;
  color: #fff;
  border: 1px solid #333b46;
}
.sl-btn-dark:hover { background: #37404c; color: #fff; }
.sl-btn-accent {
  background: #e8c547;
  color: #1f2937;
  border: 1px solid #d8b53e;
}
.sl-btn-accent:hover { background: #ddba40; color: #111827; }
.sl-help-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  border-radius: 7px;
  text-decoration: none;
  font-weight: 600;
  font-size: 13px;
  color: #334155;
  border: 1px solid #cbd5e1;
  background: #fff;
}
.sl-help-link:hover {
  background: #f1f5f9;
  color: #0f172a;
}
</style>

<div class="content content-margin">
  <?php if ($notice !== ''): ?>
    <div class="box bg-success pd-10 mb-10"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="box bg-danger pd-10 mb-10"><i class="fa fa-ban"></i> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="sl-card">
    <div class="sl-head"><i class="fa fa-plug"></i> Intégration SafeLinkHub (API + Webhook)</div>
    <div class="sl-body">
      <div class="sl-note">
        1) Collez la clé API générée sur SafeLinkHub. 2) Définissez votre secret webhook.
        3) Copiez l'URL webhook locale ci-dessous vers SafeLinkHub.
      </div>
      <div class="sl-grid mt-10">
        <div>
          <label class="sl-label">URL webhook locale (à mettre dans SafeLinkHub)</label>
          <input class="sl-input" type="text" readonly value="<?= htmlspecialchars($localWebhookUrl) ?>">
        </div>
        <div>
          <label class="sl-label">Clé API enregistrée (masquée)</label>
          <input class="sl-input" type="text" readonly value="<?= htmlspecialchars(safelink_mask_key($cfg['api_key'])) ?>">
        </div>
      </div>
      <div class="sl-actions">
        <a class="sl-help-link" href="./docs/SAFELINKHUB_MAMP_GUIDE.md" target="_blank" rel="noopener">
          <i class="fa fa-file-text-o"></i> Ouvrir la documentation (.md)
        </a>
      </div>
    </div>
  </div>

  <form method="post" class="sl-card">
    <?= csrf_field() ?>
    <div class="sl-head"><i class="fa fa-key"></i> Configuration API / Webhook</div>
    <div class="sl-body">
      <div class="sl-grid">
        <div>
          <label class="sl-label">Base API SafeLink</label>
          <input class="sl-input" name="api_base_url" value="<?= htmlspecialchars($cfg['api_base_url']) ?>" placeholder="https://safelinkhub.io" required>
        </div>
        <div>
          <label class="sl-label">Clé API (Bearer sla_...)</label>
          <input class="sl-input" name="api_key" value="<?= htmlspecialchars($cfg['api_key']) ?>" placeholder="sla_xxxxx">
        </div>
        <div>
          <label class="sl-label">Secret Webhook (même secret que SafeLink)</label>
          <input class="sl-input" name="webhook_secret" value="<?= htmlspecialchars($cfg['webhook_secret']) ?>" placeholder="secret partagé">
        </div>
        <div>
          <label class="sl-label">URL webhook de test (optionnel)</label>
          <input class="sl-input" name="outbound_webhook_url" value="<?= htmlspecialchars($cfg['outbound_webhook_url']) ?>" placeholder="https://webhook.site/xxxx">
        </div>
      </div>
      <div class="mt-10">
        <label>
          <input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
          Activer l'intégration SafeLink
        </label>
      </div>
      <div class="sl-actions">
        <button class="btn sl-btn-primary" name="save_safelink_integration" value="1"><i class="fa fa-save"></i> Enregistrer</button>
      </div>
    </div>
  </form>

  <div class="sl-card">
    <div class="sl-head"><i class="fa fa-flask"></i> Tests rapides</div>
    <div class="sl-body">
      <form method="post" class="mb-10" style="display:inline-block;">
        <?= csrf_field() ?>
        <button class="btn sl-btn-dark" name="test_safelink_api" value="1"><i class="fa fa-bolt"></i> Tester la clé API</button>
      </form>
      <form method="post" style="display:inline-block;">
        <?= csrf_field() ?>
        <button class="btn sl-btn-accent" name="test_outbound_webhook" value="1"><i class="fa fa-paper-plane"></i> Envoyer un webhook test</button>
      </form>

      <?php if (is_array($apiTest)): ?>
        <div class="mt-10 sl-note">
          <b>Résultat test API:</b> HTTP <?= (int)$apiTest['status'] ?>
          <?php if (!empty($apiTest['error'])): ?> — <?= htmlspecialchars($apiTest['error']) ?><?php endif; ?>
          <div class="sl-code"><?= htmlspecialchars(substr((string)$apiTest['body'], 0, 1000)) ?></div>
        </div>
      <?php endif; ?>

      <?php if (is_array($webhookTest)): ?>
        <div class="mt-10 sl-note">
          <b>Résultat test Webhook:</b> HTTP <?= (int)$webhookTest['status'] ?>
          <?php if (!empty($webhookTest['error'])): ?> — <?= htmlspecialchars($webhookTest['error']) ?><?php endif; ?>
          <div class="sl-code"><?= htmlspecialchars(substr((string)$webhookTest['body'], 0, 1000)) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sl-card">
    <div class="sl-head"><i class="fa fa-code"></i> Exemple d'utilisation API</div>
    <div class="sl-body">
      <div class="sl-code">curl -H "Authorization: Bearer <?= htmlspecialchars($cfg['api_key'] !== '' ? $cfg['api_key'] : 'sla_xxxxx') ?>" <?= htmlspecialchars(rtrim($cfg['api_base_url'], '/') . '/api/apikeys') ?></div>
    </div>
  </div>
</div>
