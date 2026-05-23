<?php

if (!function_exists('mikhmon_update_cache_file')) {
  function mikhmon_update_cache_file()
  {
    return dirname(__DIR__) . '/logs/app_update_status.json';
  }

  function mikhmon_update_local_build_stamp()
  {
    $root = dirname(__DIR__);
    $gitHead = $root . '/.git/HEAD';
    if (is_file($gitHead)) {
      $head = trim((string) @file_get_contents($gitHead));
      $hash = '';
      if (strpos($head, 'ref: ') === 0) {
        $refFile = $root . '/.git/' . trim(substr($head, 5));
        if (is_file($refFile)) {
          $hash = trim((string) @file_get_contents($refFile));
        }
      } else {
        $hash = $head;
      }

      if (preg_match('/^[a-f0-9]{7,40}$/i', $hash)) {
        return 'local-' . substr($hash, 0, 7);
      }
    }

    $fallbackFile = $root . '/include/app_update.php';
    $mtime = is_file($fallbackFile) ? filemtime($fallbackFile) : time();
    return 'local-' . gmdate('YmdHis', $mtime);
  }

  function mikhmon_update_build_info()
  {
    $info = array(
      'version' => getenv('MIKHMON_BUILD_VERSION') ?: '',
      'stamp' => getenv('MIKHMON_BUILD_STAMP') ?: '',
      'image' => getenv('MIKHMON_IMAGE_NAME') ?: 'latif225/mikhmonv3-safelinkhub',
    );

    $buildFile = dirname(__DIR__) . '/build-info.json';
    if (is_file($buildFile)) {
      $decoded = json_decode((string) file_get_contents($buildFile), true);
      if (is_array($decoded)) {
        foreach (array('version', 'stamp', 'image') as $key) {
          if ($info[$key] === '' && !empty($decoded[$key])) {
            $info[$key] = (string) $decoded[$key];
          }
        }
      }
    }

    if ($info['version'] === '') {
      $info['version'] = 'local';
    }
    if ($info['stamp'] === '') {
      $info['stamp'] = mikhmon_update_local_build_stamp();
    }

    return $info;
  }

  function mikhmon_update_fetch_latest_tag($image)
  {
    $parts = explode('/', $image, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      return array('ok' => false, 'error' => 'invalid_image');
    }

    $url = getenv('MIKHMON_UPDATE_URL');
    if (!$url) {
      $url = 'https://hub.docker.com/v2/repositories/' . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]) . '/tags/latest';
    }

    $context = stream_context_create(array(
      'http' => array(
        'timeout' => 2,
        'header' => "User-Agent: Mikhmon-Update-Check\r\n",
      ),
    ));
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || trim($raw) === '') {
      return array('ok' => false, 'error' => 'remote_unreachable');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return array('ok' => false, 'error' => 'invalid_remote_json');
    }

    return array(
      'ok' => true,
      'tag' => isset($decoded['name']) ? (string) $decoded['name'] : 'latest',
      'last_updated' => isset($decoded['last_updated']) ? (string) $decoded['last_updated'] : '',
      'url' => $url,
    );
  }

  function mikhmon_update_status($force = false)
  {
    if (getenv('MIKHMON_UPDATE_CHECK') === '0') {
      return array('available' => false, 'disabled' => true);
    }

    $cacheFile = mikhmon_update_cache_file();
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
      $cached = json_decode((string) file_get_contents($cacheFile), true);
      if (is_array($cached)) {
        $cachedStamp = isset($cached['current_stamp']) ? (string) $cached['current_stamp'] : '';
        if ($cachedStamp !== '' && $cachedStamp !== 'unknown') {
          return $cached;
        }
      }
    }

    $build = mikhmon_update_build_info();
    $latest = mikhmon_update_fetch_latest_tag($build['image']);
    $status = array(
      'available' => false,
      'current_version' => $build['version'],
      'current_stamp' => $build['stamp'],
      'image' => $build['image'],
      'latest_tag' => 'latest',
      'latest_updated' => '',
      'install_hint' => '/container stop mikhmon; /container remove mikhmon; /container add remote-image=' . $build['image'] . ':latest name=mikhmon',
    );

    if (!empty($latest['ok'])) {
      $status['latest_tag'] = $latest['tag'];
      $status['latest_updated'] = $latest['last_updated'];
      $remoteTime = $latest['last_updated'] !== '' ? strtotime($latest['last_updated']) : false;
      $localTime = preg_match('/^\d{14}$/', $build['stamp'])
        ? strtotime(substr($build['stamp'], 0, 4) . '-' . substr($build['stamp'], 4, 2) . '-' . substr($build['stamp'], 6, 2) . ' ' . substr($build['stamp'], 8, 2) . ':' . substr($build['stamp'], 10, 2) . ':' . substr($build['stamp'], 12, 2) . ' UTC')
        : false;
      $status['available'] = ($remoteTime !== false && $localTime !== false && ($remoteTime - $localTime) > 600);
    } else {
      $status['error'] = isset($latest['error']) ? $latest['error'] : 'unknown_error';
    }

    $dir = dirname($cacheFile);
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
      @file_put_contents($cacheFile, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $status;
  }

  function mikhmon_render_update_panel()
  {
    $status = mikhmon_update_status();
    $image = htmlspecialchars($status['image'], ENT_QUOTES, 'UTF-8');
    $current = htmlspecialchars($status['current_version'], ENT_QUOTES, 'UTF-8');
    $stamp = htmlspecialchars($status['current_stamp'], ENT_QUOTES, 'UTF-8');
    $updated = $status['latest_updated'] !== ''
      ? htmlspecialchars($status['latest_updated'], ENT_QUOTES, 'UTF-8')
      : 'non disponible';
    $hint = htmlspecialchars($status['install_hint'], ENT_QUOTES, 'UTF-8');
    $stateClass = !empty($status['available']) ? 'update-available' : 'update-current';
    $stateIcon = !empty($status['available']) ? 'fa-cloud-download' : 'fa-check-circle';
    $stateLabel = !empty($status['available']) ? 'Mise a jour disponible' : 'Application a jour';

    return '<div class="mikhmon-update-panel ' . $stateClass . '">'
      . '<div class="mikhmon-update-status">'
      . '<span><i class="fa ' . $stateIcon . '"></i> ' . $stateLabel . '</span>'
      . '<small>Image: <code>' . $image . ':latest</code></small>'
      . '</div>'
      . '<div class="mikhmon-update-meta">'
      . '<div><b>Version locale</b><span>' . $current . '</span></div>'
      . '<div><b>Build local</b><span>' . $stamp . '</span></div>'
      . '<div><b>Derniere image DockerHub</b><span>' . $updated . '</span></div>'
      . '</div>'
      . '<div class="mikhmon-update-command">'
      . '<b>Commande MikroTik RouterOS</b>'
      . '<code>' . $hint . '</code>'
      . '</div>'
      . '</div>';
  }

  function mikhmon_render_update_banner()
  {
    return mikhmon_render_update_panel();
  }
}
