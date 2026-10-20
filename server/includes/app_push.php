<?php
// ============================================================================
// includes/app_push.php - instant phone notifications  (v1.8.0)
//
// When something becomes public (a notice, an event, photos, a poll ...) the
// phones are told straight away through Firebase Cloud Messaging (FCM),
// instead of waiting for their next background check, which Android delays
// by 15 minutes at best and by hours on battery-saving phones.
//
// How it fits together
// --------------------
//   - What counts as "new" comes from includes/app_feed.php, the same list the
//     background poll (api/app_updates.php) returns, so the two never disagree
//     and the app de-duplicates them by item id.
//   - Every phone subscribes to one FCM topic, so the server keeps no device
//     tokens and sends one message per item, whatever the number of phones.
//   - A dispatch runs right after any POST to a module page has been answered
//     (that is how content is published), after a phone polls (at most once a
//     minute), and from cron/push_updates.php if it is scheduled. It is
//     idempotent: each item is pushed once, whichever trigger gets there first.
//   - Until an administrator uploads the two Firebase files in Admin > Mobile
//     app, nothing here does anything and phones keep polling as before.
//
// Messages carry the same generic text as the poll feed (see app_feed.php):
// they go to every phone with the app, signed in or not.
//
// Storage: an owner-only "mobile-app" folder inside the existing private
// directory (next to master.key, outside the web root). The service account
// key is additionally sealed with AES-256-GCM under a key derived from the
// master key, like uploads and sessions.
// ============================================================================
require_once __DIR__ . '/app_feed.php';

// The Play Store package. Must match applicationId in the Android build.
if (!defined('UV_ANDROID_PACKAGE')) {
    define('UV_ANDROID_PACKAGE', 'net.learninganddevelopment.uninav');
}
// Items older than this are never pushed, however long dispatch was idle.
// Phones still pick them up from their own background check.
if (!defined('UV_PUSH_WINDOW')) {
    define('UV_PUSH_WINDOW', 6 * 3600);
}
// Google endpoints. Fixed here and never read from an uploaded file, so a
// tampered key file cannot point the server at another host.
define('UV_FCM_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('UV_FCM_SEND_URL', 'https://fcm.googleapis.com/v1/projects/%s/messages:send');
define('UV_FCM_SCOPE', 'https://www.googleapis.com/auth/firebase.messaging');

class UVPushAuthError extends RuntimeException {}

function uv_android_store_url(): string {
    return 'https://play.google.com/store/apps/details?id=' . rawurlencode(UV_ANDROID_PACKAGE);
}

/* ---------------------------------------------------------------------
 * Private storage
 * ------------------------------------------------------------------- */

function uv_app_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $path = uv_private_dir() . '/mobile-app';
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create the mobile app folder in private storage.');
    }
    if (is_link($path)) throw new RuntimeException('Invalid mobile app storage folder.');
    @chmod($path, 0700);
    return $dir = $path;
}

function uv_app_read(string $name): ?string {
    $file = uv_app_dir() . '/' . $name;
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file)) return null;
    $raw = @file_get_contents($file);
    return $raw === false ? null : $raw;
}

/** Atomic, owner-only write: a crash mid-write never leaves half a file. */
function uv_app_write(string $name, string $data): void {
    $dir = uv_app_dir();
    $tmp = $dir . '/.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write ' . $name . ' to private storage.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $dir . '/' . $name)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot save ' . $name . ' in private storage.');
    }
}

function uv_app_delete(string $name): void {
    $file = uv_app_dir() . '/' . $name;
    if (is_file($file) && !is_link($file)) @unlink($file);
}

function uv_app_json(string $name): array {
    $raw = uv_app_read($name);
    if ($raw === null) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function uv_app_json_save(string $name, array $data): void {
    uv_app_write($name, (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function uv_app_seal(string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', uv_key_for('mobile-app-secrets'), OPENSSL_RAW_DATA, $iv, $tag, 'uv-mobile-app');
    if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Could not encrypt the key file.');
    return 'UVSEAL1:' . base64_encode($iv . $tag . $cipher);
}

function uv_app_unseal(string $sealed): ?string {
    if (strpos($sealed, 'UVSEAL1:') !== 0) return null;
    $raw = base64_decode(substr($sealed, 8), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', uv_key_for('mobile-app-secrets'), OPENSSL_RAW_DATA,
                             substr($raw, 0, 12), substr($raw, 12, 16), 'uv-mobile-app');
    return $plain === false ? null : $plain;
}

/* ---------------------------------------------------------------------
 * Settings (Admin > Mobile app)
 * ------------------------------------------------------------------- */

/**
 * types_off           kinds of content that do NOT notify (new kinds added
 *                     in later versions therefore notify by default)
 * update_mode         auto = ask old app installs to update once the Play
 *                     Store listing is live; on = always; off = never
 * latest_version_code newest versionCode on Google Play (0 = let the app ask
 *                     Google Play itself)
 * min_version_code    installs below this must update (0 = none)
 */
function uv_app_settings(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $saved = [];
    try {
        $saved = uv_app_json('settings.json');
    } catch (Throwable $e) {
        // Private storage unavailable: run on the defaults.
    }
    $all = array_keys(uv_app_feed_types());
    $off = isset($saved['types_off']) && is_array($saved['types_off']) ? array_values(array_intersect($all, $saved['types_off'])) : [];
    $mode = in_array($saved['update_mode'] ?? '', ['auto', 'on', 'off'], true) ? $saved['update_mode'] : 'auto';
    return $cache = [
        'types_off'           => $off,
        'update_mode'         => $mode,
        'latest_version_code' => max(0, (int)($saved['latest_version_code'] ?? 0)),
        'min_version_code'    => max(0, (int)($saved['min_version_code'] ?? 0)),
    ];
}

function uv_app_settings_save(array $changes): array {
    $current = uv_app_settings(true);
    uv_app_json_save('settings.json', array_merge($current, array_intersect_key($changes, $current)));
    return uv_app_settings(true);
}

function uv_app_enabled_feed_types(): array {
    return array_values(array_diff(array_keys(uv_app_feed_types()), uv_app_settings()['types_off']));
}

/* ---------------------------------------------------------------------
 * Firebase configuration
 *
 * Two files from the Firebase console, uploaded in Admin > Mobile app:
 *   google-services.json  - the Android app's public Firebase settings. The
 *                           server hands these to the app at runtime, so
 *                           setting up push needs no new app release.
 *   service account key   - lets this server send messages. Secret.
 * ------------------------------------------------------------------- */

function uv_push_valid_project_id(string $id): bool {
    return (bool)preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/D', $id);
}

/** Returns the app's Firebase settings, or throws with a message an admin can act on. */
function uv_push_parse_google_services(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['project_info'], $data['client']) || !is_array($data['client'])) {
        throw new InvalidArgumentException('That is not a google-services.json file. Download it from Firebase: Project settings > Your apps > Android app.');
    }
    $projectId = (string)($data['project_info']['project_id'] ?? '');
    $number    = (string)($data['project_info']['project_number'] ?? '');
    $found = [];
    foreach ($data['client'] as $client) {
        $package = (string)($client['client_info']['android_client_info']['package_name'] ?? '');
        if ($package === '') continue;
        $found[] = $package;
        if ($package !== UV_ANDROID_PACKAGE) continue;
        $appId = (string)($client['client_info']['mobilesdk_app_id'] ?? '');
        $apiKey = '';
        foreach ((array)($client['api_key'] ?? []) as $k) {
            if (!empty($k['current_key'])) { $apiKey = (string)$k['current_key']; break; }
        }
        if (!uv_push_valid_project_id($projectId) || !preg_match('/^\d{6,20}$/D', $number)
            || !preg_match('/^1:\d{6,20}:android:[0-9a-f]{6,64}$/D', $appId) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D', $apiKey)) {
            throw new InvalidArgumentException('The google-services.json file is incomplete. Download a fresh copy from Firebase.');
        }
        return ['project_id' => $projectId, 'project_number' => $number, 'app_id' => $appId, 'api_key' => $apiKey];
    }
    throw new InvalidArgumentException('This google-services.json has no Android app with the package name ' . UV_ANDROID_PACKAGE
        . ($found ? ' (it has ' . implode(', ', array_slice($found, 0, 3)) . ')' : '')
        . '. In Firebase, add an Android app with exactly that package name and download its file.');
}

/** Returns the parts of a service account key the server needs, or throws. */
function uv_push_parse_service_account(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data) || ($data['type'] ?? '') !== 'service_account') {
        throw new InvalidArgumentException('That is not a service account key. In Firebase: Project settings > Service accounts > Generate new private key.');
    }
    $sa = [
        'project_id'     => (string)($data['project_id'] ?? ''),
        'client_email'   => (string)($data['client_email'] ?? ''),
        'private_key'    => (string)($data['private_key'] ?? ''),
        'private_key_id' => (string)($data['private_key_id'] ?? ''),
    ];
    if (!uv_push_valid_project_id($sa['project_id'])
        || !preg_match('/^[a-z0-9-]+@[a-z0-9-]+\.iam\.gserviceaccount\.com$/D', $sa['client_email'])
        || !preg_match('/^[0-9a-f]{0,64}$/D', $sa['private_key_id'])) {
        throw new InvalidArgumentException('The service account key file is incomplete. Generate a new private key in Firebase.');
    }
    $key = @openssl_pkey_get_private($sa['private_key']);
    if ($key === false || !@openssl_sign('uni-vishwas', $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new InvalidArgumentException('The private key inside the service account file cannot be used. Generate a new private key in Firebase.');
    }
    return $sa;
}

/**
 * Validates and stores whichever of the two files were supplied. Either may
 * be null (keep the stored one). Returns a short summary for the admin.
 */
function uv_push_store_config(?string $googleServicesJson, ?string $serviceAccountJson): string {
    $app = $googleServicesJson !== null ? uv_push_parse_google_services($googleServicesJson) : uv_push_client_settings();
    $sa  = $serviceAccountJson !== null ? uv_push_parse_service_account($serviceAccountJson) : uv_push_service_account();
    if ($app && $sa && $app['project_id'] !== $sa['project_id']) {
        throw new InvalidArgumentException('The two files belong to different Firebase projects (' . $app['project_id'] . ' and '
            . $sa['project_id'] . '). Download both from the same project.');
    }
    if ($googleServicesJson !== null) uv_app_json_save('firebase-app.json', $app + ['package' => UV_ANDROID_PACKAGE, 'saved_at' => time()]);
    if ($serviceAccountJson !== null) uv_app_write('firebase-service-account.sealed', uv_app_seal((string)json_encode($sa)));
    // Start counting from now: setting up push must not replay old content,
    // but anything published from this moment on is pushed.
    uv_push_state_save(['epoch' => (int)db()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn()]);
    if ($app && $sa) return 'Push notifications are set up for Firebase project ' . $app['project_id'] . '.';
    return $app ? 'google-services.json saved. Now upload the service account key.' : 'Service account key saved. Now upload google-services.json.';
}

function uv_push_remove_config(): void {
    uv_app_delete('firebase-app.json');
    uv_app_delete('firebase-service-account.sealed');
    uv_push_state_save(['epoch' => 0]);
}

/** The Android app's Firebase settings as stored, or null. */
function uv_push_client_settings(): ?array {
    try {
        $app = uv_app_json('firebase-app.json');
    } catch (Throwable $e) {
        return null;
    }
    return isset($app['project_id'], $app['app_id'], $app['api_key'], $app['project_number']) ? $app : null;
}

function uv_push_service_account(): ?array {
    static $cache = null;
    try {
        $sealed = uv_app_read('firebase-service-account.sealed');
    } catch (Throwable $e) {
        return null;
    }
    if ($sealed === null) return $cache = null;
    if ($cache !== null && $cache[0] === $sealed) return $cache[1];
    $plain = uv_app_unseal($sealed);
    $sa = $plain !== null ? json_decode($plain, true) : null;
    $sa = is_array($sa) && isset($sa['project_id'], $sa['client_email'], $sa['private_key']) ? $sa : null;
    $cache = [$sealed, $sa];
    return $sa;
}

/** Cheap check used on every request: are both files present? */
function uv_push_configured(): bool {
    try {
        $dir = uv_app_dir();
    } catch (Throwable $e) {
        return false;
    }
    return is_file($dir . '/firebase-app.json') && is_file($dir . '/firebase-service-account.sealed');
}

/** One topic per installation; every phone with the app subscribes to it. */
function uv_push_topic(): string {
    return 'uv-' . substr(hash('sha256', 'uni-vishwas-topic|' . APP_URL), 0, 24);
}

/** What api/app_config.php and api/app_updates.php hand to the app, or null when push is off. */
function uv_push_client_config(): ?array {
    if (!uv_push_configured()) return null;
    $app = uv_push_client_settings();
    if (!$app) return null;
    return [
        'project_id' => $app['project_id'],
        'app_id'     => $app['app_id'],
        'api_key'    => $app['api_key'],
        'sender_id'  => $app['project_number'],
        'topic'      => uv_push_topic(),
    ];
}

/* ---------------------------------------------------------------------
 * HTTPS client (curl, with a stream fallback for hosts without it)
 * ------------------------------------------------------------------- */

/** Tests replace the network with a callable(method, url, headers, body): array. */
function uv_push_set_transport(?callable $transport): void {
    $GLOBALS['uv_push_transport'] = $transport;
}

function uv_push_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array {
    $transport = $GLOBALS['uv_push_transport'] ?? null;
    if (is_callable($transport)) return $transport($method, $url, $headers, $body);
    if (stripos($url, 'https://') !== 0) return ['status' => 0, 'body' => '', 'error' => 'Only HTTPS requests are allowed.'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Uni-Vishwas-Server/1.8.0',
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return ['status' => $status, 'body' => $response === false ? '' : (string)$response, 'error' => $error];
    }

    $context = stream_context_create([
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => (string)$body,
                   'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context);
    $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
    $status = 0;
    foreach ($lines as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $response === false ? '' : $response, 'error' => $response === false ? 'The request failed.' : ''];
}

/* ---------------------------------------------------------------------
 * FCM HTTP v1: OAuth token from the service account, then send
 * ------------------------------------------------------------------- */

function uv_push_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** RS256-signed assertion for Google's OAuth token endpoint. */
function uv_push_jwt(array $sa, int $now): string {
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($sa['private_key_id'])) $header['kid'] = $sa['private_key_id'];
    $claims = ['iss' => $sa['client_email'], 'scope' => UV_FCM_SCOPE, 'aud' => UV_FCM_TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600];
    $input = uv_push_b64url((string)json_encode($header)) . '.' . uv_push_b64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
    $key = @openssl_pkey_get_private((string)$sa['private_key']);
    if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new UVPushAuthError('The Firebase service account key could not sign a request.');
    }
    return $input . '.' . uv_push_b64url($signature);
}

function uv_push_access_token(array $sa, bool $fresh = false): string {
    static $cache = [];
    $id = $sa['client_email'];
    if (!$fresh && isset($cache[$id]) && $cache[$id]['expires'] > time() + 60) return $cache[$id]['token'];
    $now = time();
    $res = uv_push_http('POST', UV_FCM_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => uv_push_jwt($sa, $now),
    ]));
    $json = json_decode($res['body'], true);
    if ($res['status'] === 200 && !empty($json['access_token'])) {
        $cache[$id] = ['token' => (string)$json['access_token'], 'expires' => $now + (int)($json['expires_in'] ?? 3600)];
        return $cache[$id]['token'];
    }
    $why = is_array($json) ? (string)($json['error_description'] ?? $json['error'] ?? '') : '';
    if ($res['status'] >= 400 && $res['status'] < 500) {
        // A revoked or deleted key, or a server clock that is far off.
        throw new UVPushAuthError('Google rejected the service account key (HTTP ' . $res['status'] . ($why !== '' ? ': ' . $why : '') . ').');
    }
    throw new RuntimeException('Could not reach Google to authorise push (' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status']) . ').');
}

/** One data-only, high-priority message for a feed item. */
function uv_push_message(array $item, string $topic): array {
    $data = [];
    foreach (['id', 'tag', 'type', 'title', 'body', 'url', 'ts'] as $key) $data[$key] = (string)($item[$key] ?? '');
    // Data-only, so the app builds the notification itself: the same
    // de-duplication and tap target as the poll path, in the foreground too.
    $android = ['priority' => 'HIGH', 'ttl' => '172800s'];
    if ($data['tag'] !== '' && $data['tag'] !== $data['id']) {
        // A phone that was offline gets only the latest photo batch per album.
        $android['collapse_key'] = substr((string)preg_replace('/[^A-Za-z0-9:_-]/', '', $data['tag']), 0, 64);
    }
    return ['message' => ['topic' => $topic, 'data' => $data, 'android' => $android]];
}

/**
 * Sends one message. Returns ['ok', 'status', 'error', 'fatal']. "fatal"
 * means the next message would fail the same way - a bad key, the FCM API
 * switched off, the wrong project, or Google/the network being unreachable
 * right now - so the dispatcher stops this run instead of failing item after
 * item. Unsent items stay pending and are retried by the next dispatch.
 */
function uv_push_send(array $message, bool $validateOnly = false): array {
    $sa = uv_push_service_account();
    if (!$sa) return ['ok' => false, 'status' => 0, 'error' => 'Push is not set up (or the key file cannot be read with this master.key).', 'fatal' => true];
    if ($validateOnly) $message['validate_only'] = true;
    $url = sprintf(UV_FCM_SEND_URL, rawurlencode($sa['project_id']));
    $body = (string)json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $token = uv_push_access_token($sa, $attempt > 1);
        } catch (UVPushAuthError $e) {
            return ['ok' => false, 'status' => 401, 'error' => $e->getMessage(), 'fatal' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'fatal' => true];
        }
        $res = uv_push_http('POST', $url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'], $body);
        if ($res['status'] === 200) return ['ok' => true, 'status' => 200, 'error' => '', 'fatal' => false];
        if ($res['status'] === 401 && $attempt === 1) continue; // token expired mid-run: fetch a new one once
        $json = json_decode($res['body'], true);
        $error = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message']
               : ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status']);
        // Only a 400 is about this one message; everything else would hit
        // the next message too.
        $fatal = $res['status'] !== 400;
        return ['ok' => false, 'status' => $res['status'], 'error' => $error, 'fatal' => $fatal];
    }
    return ['ok' => false, 'status' => 401, 'error' => 'Google keeps rejecting the access token.', 'fatal' => true];
}

/* ---------------------------------------------------------------------
 * Dispatcher
 * ------------------------------------------------------------------- */

function uv_push_state(): array {
    $s = [];
    try {
        $s = uv_app_json('push-state.json');
    } catch (Throwable $e) {
        // fall through to an empty state
    }
    return [
        'epoch'      => (int)($s['epoch'] ?? 0),
        'sent'       => is_array($s['sent'] ?? null) ? $s['sent'] : [],
        'tags'       => is_array($s['tags'] ?? null) ? $s['tags'] : [],
        'failures'   => is_array($s['failures'] ?? null) ? $s['failures'] : [],
        'log'        => is_array($s['log'] ?? null) ? $s['log'] : [],
        'last_run'   => (int)($s['last_run'] ?? 0),
        'last_error' => is_array($s['last_error'] ?? null) ? $s['last_error'] : null,
    ];
}

function uv_push_state_save(array $state): void {
    uv_app_json_save('push-state.json', $state);
}

/**
 * Pushes every item that became public since the last run and has not been
 * pushed yet. $send is injectable for tests; production uses uv_push_send().
 */
function uv_push_dispatch(string $trigger = 'manual', ?callable $send = null): array {
    $result = ['status' => 'ok', 'trigger' => $trigger, 'sent' => 0, 'deferred' => 0, 'failed' => 0];
    if (!uv_push_configured()) return ['status' => 'not_configured'] + $result;
    $send = $send ?? 'uv_push_send';

    // One dispatcher at a time. Wait briefly rather than skip, so content
    // published while another run is mid-send is not left for later.
    $lock = @fopen(uv_app_dir() . '/push.lock', 'c');
    if (!$lock) return ['status' => 'error', 'error' => 'Cannot open the push lock file.'] + $result;
    $locked = false;
    for ($i = 0; $i < 20; $i++) {
        if ($locked = flock($lock, LOCK_EX | LOCK_NB)) break;
        usleep(250000);
    }
    if (!$locked) {
        fclose($lock);
        return ['status' => 'busy'] + $result;
    }

    try {
        $pdo = db();
        $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $state = uv_push_state();
        if ($state['epoch'] <= 0) {
            // First run after push was set up: start from now, never replay history.
            $state['epoch'] = $now;
            $state['last_run'] = $now;
            uv_push_state_save($state);
            return ['status' => 'initialised'] + $result;
        }

        $topic = uv_push_topic();
        $items = uv_app_feed_items($pdo, max($state['epoch'], $now - UV_PUSH_WINDOW), uv_app_enabled_feed_types(), 50);
        // Oldest first, so the newest notification ends up on top of the tray.
        usort($items, function ($a, $b) { return $a['ts'] <=> $b['ts']; });
        $inWindow = [];

        foreach ($items as $item) {
            $id = $item['id'];
            $tag = $item['tag'];
            $inWindow[$id] = true;
            if (isset($state['sent'][$id])) continue;
            $batched = $tag !== $id;
            if ($batched) {
                $last = (int)($state['tags'][$tag] ?? 0);
                if ($item['ts'] <= $last) continue; // nothing newer than the last push for this slot
                if ($now - $last < uv_app_feed_cooldown($item['type'])) { $result['deferred']++; continue; }
            }
            $failures = (int)($state['failures'][$id] ?? 0);
            if ($failures >= 5) continue;

            $res = $send(uv_push_message($item, $topic));
            if (!empty($res['ok'])) {
                $state['sent'][$id] = $now;
                if ($batched) $state['tags'][$tag] = $now;
                unset($state['failures'][$id]);
                $state['log'][] = ['at' => $now, 'type' => $item['type'], 'title' => $item['title']];
                $result['sent']++;
                continue;
            }
            $state['failures'][$id] = $failures + 1;
            $state['last_error'] = ['at' => $now, 'message' => (string)($res['error'] ?? 'Unknown error')];
            $result['failed']++;
            if (!empty($res['fatal'])) {
                $result['status'] = 'error';
                $result['error'] = $state['last_error']['message'];
                break;
            }
        }

        foreach ($state['sent'] as $id => $at) if ($at < $now - 8 * 86400) unset($state['sent'][$id]);
        foreach ($state['tags'] as $tag => $at) if ($at < $now - 2 * 86400) unset($state['tags'][$tag]);
        foreach ($state['failures'] as $id => $n) if (!isset($inWindow[$id])) unset($state['failures'][$id]);
        $state['log'] = array_slice($state['log'], -20);
        $state['last_run'] = $now;
        if ($result['status'] === 'ok' && $result['failed'] === 0 && $result['sent'] > 0) $state['last_error'] = null;
        uv_push_state_save($state);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ---------------------------------------------------------------------
 * Work after the response
 * ------------------------------------------------------------------- */

/**
 * Jobs run once the page has been sent, so whoever published something never
 * waits on Google. On hosts without fastcgi_finish_request() or
 * litespeed_finish_request() the page is still fully flushed first.
 */
final class UVAfterResponse {
    private static $jobs = [];
    private static $registered = false;

    public static function add(callable $job): void {
        self::$jobs[] = $job;
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'run']);
        }
    }

    public static function run(): void {
        if (!self::$jobs) return;
        uv_app_finish_response();
        foreach (self::$jobs as $job) {
            try {
                $job();
            } catch (Throwable $e) {
                error_log('Uni-Vishwas background job failed: ' . get_class($e) . ' ' . $e->getMessage());
            }
        }
        self::$jobs = [];
    }
}

function uv_app_finish_response(): void {
    if (PHP_SAPI === 'cli') return;
    @ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) break;
    }
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
    else @flush();
    @set_time_limit(60);
}

/** True at most once per $seconds across all requests (marker file mtime). */
function uv_app_interval_due(string $name, int $seconds): bool {
    $file = uv_app_dir() . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.last';
    clearstatcache(true, $file);
    if (is_file($file) && time() - (int)filemtime($file) < $seconds) return false;
    @touch($file);
    @chmod($file, 0600);
    return true;
}

/** Queue a dispatch for after the response. No-op until push is set up. */
function uv_push_after_response(string $trigger, int $minInterval = 0): void {
    static $queued = false;
    if ($queued || PHP_SAPI === 'cli') return;
    if (!uv_push_configured()) return;
    $queued = true;
    UVAfterResponse::add(function () use ($trigger, $minInterval) {
        if ($minInterval > 0 && !uv_app_interval_due('push-dispatch', $minInterval)) return;
        $r = uv_push_dispatch($trigger);
        if (($r['status'] ?? '') === 'error') error_log('Uni-Vishwas push failed: ' . ($r['error'] ?? 'unknown error'));
    });
}

/* ---------------------------------------------------------------------
 * Is the app live on Google Play? (drives the "update" prompt for old
 * installs in automatic mode - see includes/app_update_prompt.php)
 * ------------------------------------------------------------------- */

function uv_play_listing_status(): array {
    $s = [];
    try {
        $s = uv_app_json('play-listing.json');
    } catch (Throwable $e) {
        // unknown
    }
    return [
        'live'        => array_key_exists('live', $s) && $s['live'] !== null ? (bool)$s['live'] : null,
        'checked_at'  => (int)($s['checked_at'] ?? 0),
        'http_status' => (int)($s['http_status'] ?? 0),
    ];
}

/** Asks Google Play whether the listing exists: 200 = live, 404 = not (yet). */
function uv_play_listing_refresh(): array {
    $previous = uv_play_listing_status();
    $res = uv_push_http('GET', uv_android_store_url() . '&hl=en', ['Accept: text/html'], null, 8);
    if ($res['status'] === 200)     $live = true;
    elseif ($res['status'] === 404) $live = false;
    else                            $live = $previous['live']; // network trouble: keep what we knew
    $status = ['live' => $live, 'checked_at' => time(), 'http_status' => $res['status']];
    uv_app_json_save('play-listing.json', $status);
    return $status;
}

/**
 * Cached answer (null = not known yet). A stale answer is refreshed after the
 * response, so no page ever waits on Google Play: every 6 hours until the
 * listing is live, then daily.
 */
function uv_play_listing_live(): ?bool {
    $status = uv_play_listing_status();
    $maxAge = $status['live'] ? 86400 : 6 * 3600;
    if (time() - $status['checked_at'] > $maxAge && PHP_SAPI !== 'cli') {
        UVAfterResponse::add(function () {
            if (uv_app_interval_due('play-listing', 300)) uv_play_listing_refresh();
        });
    }
    return $status['live'];
}
