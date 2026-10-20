<?php
// ============================================================================
// includes/app_update_prompt.php - "please update the app"  (v1.8.0)
//
// Residents are asked to update only when the app on their phone is out of
// date, each time they open it, and the button takes them to Google Play.
//
//   - App 1.8.0 and later asks natively: it checks Google Play itself and
//     reads the "update" block of api/app_config.php (uv_update_info_for_app).
//   - Older installs have no such code, and they are the ones that most need
//     to move: they are sideloaded builds that Google Play cannot update in
//     place, and they have no instant notifications. They show these server
//     pages, so the server shows them a popup instead (uv_update_prompt_html)
//     - only inside an old app, never in a browser or in the new app.
//
// Settings live in Admin > Mobile app. In the default "automatic" mode the
// prompt starts by itself once the Play Store listing is live.
// ============================================================================
require_once __DIR__ . '/app_push.php';

/**
 * Which client sent this request.
 *   app     1.8.0+, recognised by " UninavApp/<name> (Android; build <code>)"
 *   legacy  an older Uni-Vishwas app (v1.0 marks itself "UninavAndroid/1.0")
 *   none    a browser, or some other app's in-app browser
 */
function uv_app_client(?array $server = null): array {
    $server = $server ?? $_SERVER;
    $ua  = (string)($server['HTTP_USER_AGENT'] ?? '');
    $xrw = strtolower(trim((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')));

    if (preg_match('~\bUninavApp/([0-9A-Za-z._-]{1,20}) \(Android; build (\d{1,9})\)~', $ua, $m)) {
        return ['kind' => 'app', 'version_name' => $m[1], 'version_code' => (int)$m[2]];
    }

    $package = strtolower(UV_ANDROID_PACKAGE);
    if ($xrw === $package || strpos($xrw, $package . '.') === 0 || preg_match('~\bUninav(?:Android|App)/([0-9A-Za-z._-]{0,20})~', $ua, $m)) {
        return ['kind' => 'legacy', 'version_name' => $m[1] ?? '', 'version_code' => 0];
    }

    // Builds that left the user agent alone: an Android WebView ("; wv)") that
    // no other app has claimed through X-Requested-With and that carries no
    // known in-app-browser marker. A false match only shows a dismissable
    // "get the app" popup, so this errs towards reaching every old install.
    if (strpos($ua, '; wv)') !== false && stripos($ua, 'Android') !== false
        && ($xrw === '' || $xrw === 'xmlhttprequest')
        && !preg_match('~FBAN|FBAV|FB_IAB|Instagram|LinkedInApp|Snapchat|\bLine/|Twitter|GSA/|Telegram|WhatsApp|KAKAOTALK|Pinterest|TikTok|musical_ly|BytedanceWebview|MicroMessenger|SamsungBrowser~i', $ua)) {
        return ['kind' => 'legacy', 'version_name' => '', 'version_code' => 0];
    }

    return ['kind' => 'none', 'version_name' => '', 'version_code' => 0];
}

/** Should old installs be asked to update right now? */
function uv_update_prompt_active(): bool {
    $mode = uv_app_settings()['update_mode'];
    if ($mode === 'off') return false;
    if ($mode === 'on') return true;
    return uv_play_listing_live() === true; // automatic: once the Play Store listing is live
}

/**
 * The "update" block of api/app_config.php. The app combines it with what
 * Google Play tells it: it prompts when Play has a newer version or when its
 * own versionCode is below latest_version_code, and cannot be dismissed below
 * min_version_code.
 */
function uv_update_info_for_app(): array {
    $s = uv_app_settings();
    // In automatic mode Google Play is the judge; only a listing known to be
    // gone (or never published) switches the prompt off.
    $prompt = $s['update_mode'] === 'on' || ($s['update_mode'] === 'auto' && uv_play_listing_live() !== false);
    return [
        'prompt'              => $prompt,
        'latest_version_code' => $s['latest_version_code'],
        'min_version_code'    => $s['min_version_code'],
        'store_url'           => uv_android_store_url(),
    ];
}

/**
 * The popup for old installs, or '' for everyone else. Shown once per app
 * session (sessionStorage), so it comes back the next time the app is opened
 * until the resident has moved to the new app. $preview shows it regardless,
 * for the admin page.
 */
function uv_update_prompt_html(?array $server = null, bool $preview = false): string {
    try {
        if (!$preview && (uv_app_client($server)['kind'] !== 'legacy' || !uv_update_prompt_active())) return '';
    } catch (Throwable $e) {
        return ''; // never let the prompt break a page
    }
    $store = htmlspecialchars(uv_android_store_url(), ENT_QUOTES, 'UTF-8');
    $key = $preview ? '' : 'uvAppUpdatePrompted';
    ob_start();
?>
<div class="uvup" id="uvAppUpdate" role="dialog" aria-modal="true" aria-labelledby="uvupTitle" hidden>
  <div class="uvup-card">
    <div class="uvup-badge" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M12 7v7m-3-3 3 3 3-3"/><path d="M10.5 18.5h3"/></svg>
    </div>
    <h2 id="uvupTitle">Update the Uni-Vishwas app</h2>
    <p>A new version is available on Google Play, with instant notifications for notices, photos, events and polls.</p>
    <ol>
      <li>Tap <b>Open Google Play</b>.</li>
      <li>If Google Play shows <b>Uninstall</b>, tap it first, then tap <b>Install</b>.</li>
      <li>Open the new app and sign in with your house number and password (ask the society admin if you have forgotten it).</li>
    </ol>
    <a class="uvup-go" href="<?= $store ?>">Open Google Play</a>
    <button type="button" class="uvup-later">Later</button>
  </div>
</div>
<style>
.uvup{position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(6,32,30,.62);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.uvup[hidden]{display:none}
.uvup-card{box-sizing:border-box;width:100%;max-width:380px;max-height:calc(100vh - 40px);overflow:auto;background:#fff;color:#183b38;border-radius:18px;padding:24px 22px 16px;box-shadow:0 20px 50px rgba(0,0,0,.3);text-align:center}
.uvup-badge{width:56px;height:56px;margin:0 auto 12px;border-radius:16px;background:#0B4A46;color:#d9f99d;display:flex;align-items:center;justify-content:center}
.uvup h2{margin:0 0 8px;font-size:20px;line-height:1.3;color:#0B4A46}
.uvup p{margin:0 0 12px;font-size:15px;line-height:1.5}
.uvup ol{margin:0 0 16px;padding:12px 14px 12px 32px;text-align:left;font-size:14px;line-height:1.5;background:#f3f8f7;border-radius:12px}
.uvup li+li{margin-top:4px}
.uvup-go{display:block;padding:13px 16px;border-radius:12px;background:#0B4A46;color:#fff!important;font-weight:600;font-size:16px;text-decoration:none}
.uvup-later{display:block;width:100%;margin-top:6px;padding:11px;border:0;background:none;color:#4b6b67;font-size:15px;cursor:pointer}
</style>
<script>
(function () {
  var box = document.getElementById('uvAppUpdate');
  if (!box) return;
  var key = <?= json_encode($key) ?>;
  function remember() { try { if (key) sessionStorage.setItem(key, '1'); } catch (e) {} }
  try { if (key && sessionStorage.getItem(key) === '1') return; } catch (e) {}
  box.hidden = false;
  var go = box.querySelector('.uvup-go');
  box.querySelector('.uvup-later').addEventListener('click', function () { remember(); box.hidden = true; });
  // The old apps hand Play Store links to the Play Store app and stay on this page.
  go.addEventListener('click', function () { remember(); setTimeout(function () { box.hidden = true; }, 400); });
  try { go.focus(); } catch (e) {}
})();
</script>
<?php
    return (string)ob_get_clean();
}
