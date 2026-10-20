<?php
// ============================================================================
// Mobile app (admin only)  -  v1.8.0
// - Instant notifications: upload the two files from Firebase once, check the
//   connection, choose which kinds of content notify residents, and see what
//   was pushed recently. Until then phones keep their background check.
// - "Please update" prompt: automatic (default: starts once the Play Store
//   listing is live), always or off, plus optional version numbers.
// See includes/app_push.php and includes/app_update_prompt.php.
// ============================================================================
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$err = $msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'push_upload') {
            $read = function (string $field): ?string {
                $f = $_FILES[$field] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('The upload failed. Please try again.');
                if (filesize($f['tmp_name']) > 64 * 1024) throw new InvalidArgumentException('That file is too large to be a Firebase file.');
                $raw = file_get_contents($f['tmp_name']);
                return $raw === false ? null : $raw;
            };
            $googleServices = $read('google_services');
            $serviceAccount = $read('service_account');
            if ($googleServices === null && $serviceAccount === null) $err = 'Choose google-services.json, the service account key, or both.';
            else $msg = uv_push_store_config($googleServices, $serviceAccount);
        }

        if ($a === 'push_test') {
            // validate_only: Google checks the key, the project and the message,
            // but nothing is delivered to anyone's phone.
            $r = uv_push_send(uv_push_message([
                'id' => 'test:' . time(), 'type' => 'test', 'title' => 'Uni-Vishwas test', 'body' => 'Connection check',
                'url' => APP_URL . '/', 'ts' => time(),
            ], uv_push_topic()), true);
            if ($r['ok']) $msg = 'Connection OK: Google accepted a test message for this project. Nothing was sent to phones.';
            else $err = 'Connection check failed: ' . $r['error'];
        }

        if ($a === 'push_send_now') {
            $r = uv_push_dispatch('admin');
            if ($r['status'] === 'error') $err = 'Sending failed: ' . ($r['error'] ?? 'unknown error');
            elseif ($r['status'] === 'busy') $err = 'Another send is in progress. Try again in a minute.';
            else $msg = $r['sent'] . ' notification(s) sent' . ($r['deferred'] ? ', ' . $r['deferred'] . ' photo/contact/finance update(s) waiting for their short cool-down' : '') . '.';
        }

        if ($a === 'push_remove') {
            uv_push_remove_config();
            $msg = 'Firebase files removed. Phones go back to their background check (every 15 minutes or so).';
        }

        if ($a === 'push_types') {
            $all = array_keys(uv_app_feed_types());
            $on = array_intersect($all, array_map('strval', (array)($_POST['types'] ?? [])));
            uv_app_settings_save(['types_off' => array_values(array_diff($all, $on))]);
            $msg = 'Notification choices saved.';
        }

        if ($a === 'update_save') {
            $mode = in_array($_POST['update_mode'] ?? '', ['auto', 'on', 'off'], true) ? $_POST['update_mode'] : 'auto';
            $latest = max(0, (int)($_POST['latest_version_code'] ?? 0));
            $min = max(0, (int)($_POST['min_version_code'] ?? 0));
            if ($latest > 0 && $min > $latest) $err = 'The minimum version cannot be higher than the latest version.';
            else {
                uv_app_settings_save(['update_mode' => $mode, 'latest_version_code' => $latest, 'min_version_code' => $min]);
                $msg = 'Update prompt saved.';
            }
        }

        if ($a === 'play_check') {
            $s = uv_play_listing_refresh();
            $msg = $s['live'] === true ? 'The app is live on Google Play.'
                 : ($s['live'] === false ? 'Google Play does not list the app yet (HTTP ' . $s['http_status'] . ').'
                 : 'Google Play could not be reached (HTTP ' . $s['http_status'] . '). Try again later.');
        }
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Mobile app settings failed: ' . get_class($e) . ' ' . $e->getMessage());
        $err = 'That did not work: ' . $e->getMessage();
    }
}

$settings   = uv_app_settings(true);
$configured = uv_push_configured();
$client     = uv_push_client_settings();
$sa         = $configured ? uv_push_service_account() : null;
$state      = uv_push_state();
$play       = uv_play_listing_status();
$when = function (int $ts): string { return $ts > 0 ? date('d M Y, g:i a', $ts) : '—'; };

layout_shell_start('Mobile app', 'mobile_app', 'Instant notifications and app updates');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<h3>Instant notifications</h3>
<table class="tbl">
  <tbody>
    <tr><td style="width:38%">Status</td><td>
      <?php if ($configured && $sa): ?>
        <span class="badge ok">On</span> Firebase project <b><?= e($client['project_id'] ?? '') ?></b>
      <?php elseif ($configured): ?>
        <span class="badge warn">Key unreadable</span> Upload the service account key again.
      <?php elseif ($client): ?>
        <span class="badge warn">Half set up</span> Upload the service account key.
      <?php else: ?>
        <span class="badge muted-badge">Not set up</span> Phones check for updates in the background (every 15 minutes or so; longer on battery-saving phones).
      <?php endif; ?>
    </td></tr>
    <tr><td>Last notification sent</td><td><?= e($state['log'] ? $when((int)end($state['log'])['at']) : '—') ?></td></tr>
    <?php if (!empty($state['last_error'])): ?>
      <tr><td>Last problem</td><td><span class="badge warn">Error</span> <?= e($when((int)($state['last_error']['at'] ?? 0))) ?> · <?= e((string)($state['last_error']['message'] ?? '')) ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($configured && $sa): ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px">
    <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="push_test">
      <button class="btn primary"><i class="fa fa-plug-circle-check"></i> Check connection</button></form>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="push_send_now">
      <button class="btn ghost"><i class="fa fa-paper-plane"></i> Send anything pending now</button></form>
    <form method="post" onsubmit="return confirm('Turn instant notifications off and delete the Firebase files from this server?');">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="push_remove">
      <button class="btn ghost"><i class="fa fa-trash"></i> Remove</button></form>
  </div>
<?php endif; ?>

<details class="panel"<?= ($configured && $sa) ? '' : ' open' ?>>
  <summary><b><?= ($configured && $sa) ? 'Replace the Firebase files' : 'Set up instant notifications (about 10 minutes, once)' ?></b></summary>
  <ol style="line-height:1.7;margin:10px 0 14px 0;padding-left:20px">
    <li>Open <b>console.firebase.google.com</b>, sign in with the society's Google account and <b>create a project</b> (any name, e.g. "Uni-Vishwas"; Google Analytics is not needed).</li>
    <li>In the project, click <b>Add app → Android</b>. Package name, exactly: <code><?= e(UV_ANDROID_PACKAGE) ?></code>. Register it, <b>download google-services.json</b>, and skip the remaining steps (the app already includes Firebase).</li>
    <li>Open <b>Project settings → Service accounts → Generate new private key</b>. This downloads a key file. It is secret: upload it here and then delete it from your computer.</li>
    <li>Upload both files below, then press <b>Check connection</b>.</li>
  </ol>
  <form method="post" enctype="multipart/form-data" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="push_upload">
    <div class="grid2">
      <div><label>google-services.json <?= $client ? '<span class="badge ok">saved</span>' : '' ?></label><input type="file" name="google_services" accept=".json,application/json"></div>
      <div><label>Service account key (.json) <?= $sa ? '<span class="badge ok">saved</span>' : '' ?></label><input type="file" name="service_account" accept=".json,application/json"></div>
    </div>
    <p class="muted small">The key is stored encrypted in the server's private folder, outside the website. Phones download only the public app settings and the topic name. Nothing changes in the app: phones switch to instant notifications the next time they open the app or check in.</p>
    <button class="btn primary"><i class="fa fa-upload"></i> Save files</button>
  </form>
</details>

<h3>What residents are notified about</h3>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="push_types">
  <div class="grid2">
    <?php foreach (uv_app_feed_types() as $type => $info): ?>
      <label style="display:flex;gap:8px;align-items:center;font-weight:normal">
        <input type="checkbox" name="types[]" value="<?= e($type) ?>" <?= in_array($type, $settings['types_off'], true) ? '' : 'checked' ?> style="width:auto">
        <?= e($info['label']) ?>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Notifications say only what kind of update it is ("New society notice", "5 new photos in the gallery") and open the right page when tapped. Titles, names and photos stay behind sign-in, because a phone shows them on the lock screen. Photo uploads, SPOC edits and finance entries are grouped, so a bulk upload is one notification.</p>
  <button class="btn primary"><i class="fa fa-save"></i> Save</button>
</form>

<?php if ($state['log']): ?>
  <h3>Recently sent</h3>
  <table class="tbl">
    <thead><tr><th>When</th><th>Notification</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($state['log']) as $row): ?>
      <tr><td class="muted small"><?= e($when((int)($row['at'] ?? 0))) ?></td><td><?= e((string)($row['title'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h3>App update prompt</h3>
<p class="muted small" style="margin-top:0">When a resident opens an app that is out of date, a popup asks them to update and opens Google Play. Up-to-date apps never see it.</p>
<table class="tbl">
  <tbody>
    <tr><td style="width:38%">Google Play listing</td><td>
      <?php if ($play['live'] === true): ?><span class="badge ok">Live</span>
      <?php elseif ($play['live'] === false): ?><span class="badge warn">Not live yet</span>
      <?php else: ?><span class="badge muted-badge">Not checked yet</span><?php endif; ?>
      <span class="muted small">checked <?= e($when($play['checked_at'])) ?></span>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="play_check">
        <button class="btn ghost" style="margin-left:6px"><i class="fa fa-rotate"></i> Check now</button></form>
    </td></tr>
    <tr><td>Store page</td><td><a href="<?= e(uv_android_store_url()) ?>" target="_blank" rel="noopener"><?= e(uv_android_store_url()) ?></a></td></tr>
  </tbody>
</table>
<form method="post" class="form" style="margin-top:12px">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="update_save">
  <label>Ask residents to update</label>
  <select name="update_mode">
    <option value="auto" <?= $settings['update_mode'] === 'auto' ? 'selected' : '' ?>>Automatically, once the app is live on Google Play (recommended)</option>
    <option value="on" <?= $settings['update_mode'] === 'on' ? 'selected' : '' ?>>Always (even if the listing check fails)</option>
    <option value="off" <?= $settings['update_mode'] === 'off' ? 'selected' : '' ?>>Never</option>
  </select>
  <div class="grid2">
    <div><label>Latest version code on Google Play <span class="muted small">(optional)</span></label>
      <input type="number" name="latest_version_code" min="0" step="1" value="<?= (int)$settings['latest_version_code'] ?>"></div>
    <div><label>Must-update below version code <span class="muted small">(optional)</span></label>
      <input type="number" name="min_version_code" min="0" step="1" value="<?= (int)$settings['min_version_code'] ?>"></div>
  </div>
  <p class="muted small">Leave both at 0 normally. The app asks Google Play itself whether a newer version exists, and older apps (before 1.8.0) are always asked to move to the Play Store app. Only enter the latest version code after that release is live for everyone; a resident below the "must-update" code cannot dismiss the prompt. This release is version 1.8.0, code 180.</p>
  <button class="btn primary"><i class="fa fa-save"></i> Save</button>
  <a class="btn ghost" href="<?= e(uv_link('modules/mobile_app.php?preview_prompt=1')) ?>"><i class="fa fa-eye"></i> Preview the popup</a>
</form>
<?php
if (isset($_GET['preview_prompt'])) echo uv_update_prompt_html(null, true);
layout_shell_end();
