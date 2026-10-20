<?php
// Mobile app notifications and update prompt (v1.8.0).
// Writes test rows, so it only runs against an isolated database named in
// the environment - never the defaults in config/config.php:
//   UV_TEST_TEMP=/tmp/uvtest UV_PRIVATE_DIR=/tmp/uvtest/private UV_DB_HOST=127.0.0.1 \
//   UV_DB_NAME=uv_test UV_DB_USER=... UV_DB_PASS=... php tests/app_push_test.php
// The database needs sql/schema.sql imported first. No network is used: the
// Google endpoints are replaced by uv_push_set_transport().
$temporary = getenv('UV_TEST_TEMP'); if (!$temporary) throw new RuntimeException('UV_TEST_TEMP is required.');
if (!getenv('UV_DB_NAME') || !getenv('UV_PRIVATE_DIR')) throw new RuntimeException('UV_DB_NAME and UV_PRIVATE_DIR must name an isolated test database and folder.');
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/app_update_prompt.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; }
function must_fail(callable $fn, string $label): void { $failed = false; try { $fn(); } catch (Throwable $e) { $failed = true; } check($failed, $label); }

$pdo = db();
ensure_gallery_schema();
ensure_aoa_changes_schema();
$pdo->exec("CREATE TABLE IF NOT EXISTS society_spocs (id INT AUTO_INCREMENT PRIMARY KEY, department VARCHAR(40) NOT NULL, name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL, phone VARCHAR(30) NULL, photo VARCHAR(255) NULL, notes VARCHAR(250) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS finance_incomes (id INT AUTO_INCREMENT PRIMARY KEY, month_year VARCHAR(7) NOT NULL, income_date DATE NOT NULL,
    description VARCHAR(250) NOT NULL, amount DECIMAL(12,2) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['notices','events','gallery_media','gallery_albums','poll_options','polls','advertisements','aoa_changes','vendors','society_spocs','finance_incomes','finance_expenses','finance_months'] as $t) $pdo->exec("DELETE FROM `$t`");
foreach (['settings.json','push-state.json','play-listing.json','firebase-app.json','firebase-service-account.sealed','push-dispatch.last','play-listing.last'] as $f) uv_app_delete($f);
uv_app_settings(true);

$now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
$at = function (int $offset): string { global $now; return date('Y-m-d H:i:s', $now + $offset); };
$userId = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
if (!$userId) { $pdo->exec("INSERT INTO users (username, role, owner_name) VALUES ('feedtest','resident','Feed Test')"); $userId = (int)$pdo->lastInsertId(); }

// ---------------------------------------------------------------- feed
$ins = function (string $sql, array $args) use ($pdo): int { $pdo->prepare($sql)->execute($args); return (int)$pdo->lastInsertId(); };
$notice = $ins('INSERT INTO notices (title, body, posted_on, created_at) VALUES (?,?,CURDATE(),?)', ['Water cut Tuesday', 'Secret body', $at(-300)]);
$ins('INSERT INTO notices (title, body, posted_on, created_at) VALUES (?,?,CURDATE(),?)', ['Old notice', 'x', $at(-90000)]);
$event = $ins('INSERT INTO events (title, event_date, created_at) VALUES (?,CURDATE(),?)', ['Diwali party', $at(-250)]);
$album = $ins('INSERT INTO gallery_albums (name, created_at) VALUES (?,?)', ['Diwali 2026 private name', $at(-240)]);
foreach ([['image', -230], ['image', -220], ['image', -210], ['video', -200]] as [$type, $off]) {
    $ins('INSERT INTO gallery_media (album_id, user_id, file, media_type, created_at) VALUES (?,?,?,?,?)', [$album, $userId, 'gallery/x.jpg', $type, $at($off)]);
}
$ins('INSERT INTO polls (question, active, created_at) VALUES (?,0,?)', ['Closed poll', $at(-190)]);
$poll = $ins('INSERT INTO polls (question, active, created_at) VALUES (?,1,?)', ['Open poll', $at(-180)]);
$ins("INSERT INTO advertisements (user_id, title, description, contact_name, contact_phone, status, created_at) VALUES (?,?,?,?,?,'hidden',?)", [$userId, 'Hidden ad', 'd', 'n', '1', $at(-170)]);
$ad = $ins("INSERT INTO advertisements (user_id, title, description, contact_name, contact_phone, status, created_at) VALUES (?,?,?,?,?,'active',?)", [$userId, 'Sofa for sale', 'd', 'Mr Private', '99999', $at(-160)]);
$aoa = $ins('INSERT INTO aoa_changes (title, description, work_date, created_at) VALUES (?,?,CURDATE(),?)', ['Lift work', 'd', $at(-150)]);
$vendor = $ins("INSERT INTO vendors (category, name, created_at) VALUES ('maid',?,?)", ['Private Vendor Name', $at(-140)]);
$ins('INSERT INTO society_spocs (department, name, created_at, updated_at) VALUES (?,?,?,?)', ['Security', 'Private SPOC', $at(-130), $at(-130)]);
$ins('INSERT INTO finance_incomes (month_year, income_date, description, amount, created_at) VALUES (?,CURDATE(),?,?,?)', [date('Y-m'), 'Maintenance', 100, $at(-120)]);

$items = uv_app_feed_items($pdo, $now - 3600);
$byId = []; foreach ($items as $i) $byId[$i['id']] = $i;
check(isset($byId["notice:$notice"], $byId["event:$event"], $byId["album:$album"], $byId["poll:$poll"], $byId["ad:$ad"], $byId["aoa:$aoa"], $byId["vendor:$vendor"]), 'every kind of new public content is in the feed');
check(count(array_filter($items, function ($i) { return $i['type'] === 'notice'; })) === 1, 'content older than since is not in the feed');
check(array_values(array_map(function ($i) { return $i['id']; }, array_filter($items, function ($i) { return $i['type'] === 'poll'; }))) === ["poll:$poll"], 'inactive polls are not announced');
check(count(array_filter($items, function ($i) { return $i['type'] === 'ad'; })) === 1, 'hidden ads are not announced');
$photos = array_values(array_filter($items, function ($i) { return strpos($i['id'], 'photos:') === 0; }));
check(count($photos) === 1 && $photos[0]['title'] === '4 new photos and videos in the gallery' && $photos[0]['tag'] === "gallery:$album", 'uploads are batched per album with a shared tag');
check(strpos($photos[0]['url'], "modules/gallery.php?album=$album") !== false, 'photo notification opens the album');
check(isset($byId["notice:$notice"]) && $byId["notice:$notice"]['tag'] === "notice:$notice", 'single items use their id as tag');
$spoc = array_values(array_filter($items, function ($i) { return $i['type'] === 'spoc'; }));
$fin = array_values(array_filter($items, function ($i) { return $i['type'] === 'finance'; }));
check(count($spoc) === 1 && $spoc[0]['tag'] === 'spocs' && count($fin) === 1 && $fin[0]['tag'] === 'finance', 'SPOC and finance changes are batched');
$json = json_encode($items);
foreach (['Water cut', 'Secret body', 'Diwali', 'Sofa', 'Mr Private', '99999', 'Private Vendor', 'Private SPOC', 'Lift work', 'Maintenance'] as $secret) {
    check(strpos($json, $secret) === false, 'feed never contains society content: ' . $secret);
}
$sorted = $items; usort($sorted, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
check($sorted === $items, 'feed is newest first');
check(count(uv_app_feed_items($pdo, $now - 3600, null, 3)) === 3, 'feed honours its limit');
$onlyNotices = uv_app_feed_items($pdo, $now - 3600, ['notice']);
check(count($onlyNotices) === 1 && $onlyNotices[0]['type'] === 'notice', 'feed honours the enabled types');
foreach ($items as $i) check(strpos($i['url'], APP_URL . '/modules/') === 0, 'item links stay on this site: ' . $i['id']);

// ---------------------------------------------------------------- settings + sealing
check(uv_app_enabled_feed_types() === array_keys(uv_app_feed_types()), 'all kinds notify by default');
uv_app_settings_save(['types_off' => ['ad', 'nonsense']]);
check(!in_array('ad', uv_app_enabled_feed_types(), true) && uv_app_settings()['types_off'] === ['ad'], 'a kind can be switched off; unknown kinds are ignored');
uv_app_settings_save(['types_off' => []]);
check((fileperms(uv_app_dir()) & 0077) === 0 && (fileperms(uv_app_dir() . '/settings.json') & 0077) === 0, 'mobile app storage is owner-only');
$sealed = uv_app_seal('{"private":"value"}');
check(strpos($sealed, 'private') === false && uv_app_unseal($sealed) === '{"private":"value"}', 'sealed secrets round-trip and are not readable');
$tampered = substr($sealed, 0, -3) . (substr($sealed, -3, 1) === 'A' ? 'B' : 'A') . substr($sealed, -2);
check(uv_app_unseal($tampered) === null && uv_app_unseal('plain') === null, 'tampered or foreign data is rejected');

// ---------------------------------------------------------------- Firebase files
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$publicPem = openssl_pkey_get_details($key)['key'];
$saJson = json_encode(['type' => 'service_account', 'project_id' => 'uni-vishwas-test', 'private_key_id' => str_repeat('ab', 20), 'private_key' => $pem,
    'client_email' => 'firebase-adminsdk-x1@uni-vishwas-test.iam.gserviceaccount.com', 'token_uri' => 'https://evil.example/token']);
$gsJson = json_encode(['project_info' => ['project_number' => '123456789012', 'project_id' => 'uni-vishwas-test'],
    'client' => [
        ['client_info' => ['mobilesdk_app_id' => '1:123456789012:android:aaaa1111', 'android_client_info' => ['package_name' => 'com.other.app']], 'api_key' => [['current_key' => 'AIzaOTHEROTHEROTHEROTHEROTHEROTHER12345']]],
        ['client_info' => ['mobilesdk_app_id' => '1:123456789012:android:bbbb2222', 'android_client_info' => ['package_name' => UV_ANDROID_PACKAGE]], 'api_key' => [['current_key' => 'AIzaSyTESTTESTTESTTESTTESTTESTTEST12345']]],
    ]]);
$app = uv_push_parse_google_services($gsJson);
check($app['app_id'] === '1:123456789012:android:bbbb2222' && $app['api_key'] === 'AIzaSyTESTTESTTESTTESTTESTTESTTEST12345' && $app['project_number'] === '123456789012', 'google-services.json: our package is picked');
must_fail(function () { uv_push_parse_google_services('{"project_info":{"project_id":"uni-vishwas-test","project_number":"123456789012"},"client":[{"client_info":{"mobilesdk_app_id":"1:1:android:aa","android_client_info":{"package_name":"com.other"}}}]}'); }, 'google-services.json for another package is refused');
must_fail(function () use ($saJson) { uv_push_parse_google_services($saJson); }, 'a service account file is not accepted as google-services.json');
$sa = uv_push_parse_service_account($saJson);
check($sa['client_email'] === 'firebase-adminsdk-x1@uni-vishwas-test.iam.gserviceaccount.com' && !isset($sa['token_uri']), 'service account: only the needed fields are kept (never its token_uri)');
must_fail(function () use ($gsJson) { uv_push_parse_service_account($gsJson); }, 'google-services.json is not accepted as a key');
must_fail(function () { uv_push_parse_service_account(json_encode(['type' => 'service_account', 'project_id' => 'uni-vishwas-test', 'private_key' => 'not a key', 'client_email' => 'a@uni-vishwas-test.iam.gserviceaccount.com'])); }, 'an unusable private key is refused');
must_fail(function () { uv_push_store_config('{}', null); }, 'an empty file is refused');
uv_push_remove_config();
check(!uv_push_configured() && uv_push_client_config() === null, 'push is off without files');
check(strpos(uv_push_store_config($gsJson, null), 'service account') !== false && !uv_push_configured(), 'half set up after one file');
must_fail(function () use ($pem) { uv_push_store_config(null, json_encode(['type' => 'service_account', 'project_id' => 'another-project', 'private_key' => $pem, 'client_email' => 'a@another-project.iam.gserviceaccount.com'])); }, 'files from two different projects are refused');
check(strpos(uv_push_store_config(null, $saJson), 'uni-vishwas-test') !== false && uv_push_configured(), 'push is on with both files');
check(strpos((string)uv_app_read('firebase-service-account.sealed'), 'PRIVATE KEY') === false, 'the stored key is encrypted at rest');
$cfg = uv_push_client_config();
check($cfg === ['project_id' => 'uni-vishwas-test', 'app_id' => '1:123456789012:android:bbbb2222', 'api_key' => 'AIzaSyTESTTESTTESTTESTTESTTESTTEST12345', 'sender_id' => '123456789012', 'topic' => uv_push_topic()], 'apps receive only the public config and the topic');
check(preg_match('/^uv-[0-9a-f]{24}$/', uv_push_topic()) === 1, 'topic name is FCM-safe');
check(uv_push_state()['epoch'] >= $now, 'setting push up starts the clock now');

// ---------------------------------------------------------------- OAuth + FCM over a fake network
$jwt = uv_push_jwt(uv_push_service_account(), 1700000000);
[$h, $c, $s] = explode('.', $jwt);
$b64 = function ($v) { return base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4)); };
check(openssl_verify("$h.$c", $b64($s), $publicPem, OPENSSL_ALGO_SHA256) === 1, 'JWT is signed with the service account key');
$claims = json_decode($b64($c), true);
check($claims['aud'] === 'https://oauth2.googleapis.com/token' && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging' && $claims['exp'] - $claims['iat'] === 3600, 'JWT claims are what Google expects');
check(json_decode($b64($h), true)['kid'] === str_repeat('ab', 20), 'JWT names the key id');

$calls = [];
$fcmStatus = [200];
uv_push_set_transport(function ($method, $url, $headers, $body) use (&$calls, &$fcmStatus) {
    $calls[] = [$method, $url, $headers, $body];
    if ($url === 'https://oauth2.googleapis.com/token') return ['status' => 200, 'body' => json_encode(['access_token' => 'tok-' . count($calls), 'expires_in' => 3600]), 'error' => ''];
    $status = array_shift($fcmStatus) ?? 200;
    return ['status' => $status, 'body' => $status === 200 ? '{"name":"projects/x/messages/1"}' : json_encode(['error' => ['message' => 'fake ' . $status]]), 'error' => ''];
});
$msg = uv_push_message(['id' => 'notice:1', 'tag' => 'notice:1', 'type' => 'notice', 'title' => 'New society notice', 'body' => 'b', 'url' => APP_URL . '/x', 'ts' => 5], 'uv-topic');
check($msg['message']['topic'] === 'uv-topic' && $msg['message']['android']['priority'] === 'HIGH' && !isset($msg['message']['notification']) && !isset($msg['message']['android']['collapse_key']), 'data-only high priority message, no collapse key for single items');
check($msg['message']['data']['ts'] === '5' && count(array_filter($msg['message']['data'], 'is_string')) === 7, 'every data value is a string');
check(uv_push_message(['id' => 'photos:3:9', 'tag' => 'gallery:3', 'type' => 'gallery', 'ts' => 9], 't')['message']['android']['collapse_key'] === 'gallery:3', 'photo batches collapse while a phone is offline');
$r = uv_push_send($msg);
check($r['ok'] && count($calls) === 2 && $calls[1][1] === 'https://fcm.googleapis.com/v1/projects/uni-vishwas-test/messages:send', 'send: token, then FCM v1 for the project');
check(in_array('Authorization: Bearer tok-1', $calls[1][2], true), 'send: bearer token from the OAuth exchange');
parse_str($calls[0][3], $form);
check($form['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($form['assertion'], '.') === 2, 'OAuth: JWT bearer grant');
$calls = []; uv_push_send($msg);
check(count($calls) === 1, 'access token is reused within a run');
$calls = []; $fcmStatus = [401, 200];
check(uv_push_send($msg)['ok'] && count($calls) === 3, 'an expired token is refreshed once');
$fcmStatus = [403]; $r = uv_push_send($msg);
check(!$r['ok'] && $r['fatal'] && $r['error'] === 'fake 403', 'permission problems stop the run');
$fcmStatus = [400]; $r = uv_push_send($msg);
check(!$r['ok'] && !$r['fatal'], 'a rejected message does not stop the run');
$fcmStatus = [503]; check(uv_push_send($msg)['fatal'], 'an unavailable Google stops the run (retried later)');
$calls = []; $fcmStatus = [];
check(uv_push_send($msg, true)['ok'] && json_decode($calls[0][3], true)['validate_only'] === true, 'connection check uses validate_only');

// ---------------------------------------------------------------- dispatcher
$sent = [];
$ok = function ($m) use (&$sent) { $sent[] = $m['message']; return ['ok' => true]; };
$state = uv_push_state(); $state['epoch'] = $now - 100; uv_push_state_save($state); // only content from the last 100 s
$r = uv_push_dispatch('test', $ok);
$sentIds = array_map(function ($m) { return $m['data']['id']; }, $sent);
check($r['status'] === 'ok' && !in_array("notice:$notice", $sentIds, true) && !in_array("album:$album", $sentIds, true), 'content from before push was set up is never pushed');
check(in_array('spocs:' . ($now - 130), $sentIds, true) === false && in_array('finance:' . ($now - 120), $sentIds, true) === false, 'batches older than the epoch are skipped too');
$state = uv_push_state(); $state['epoch'] = $now - 3600; $state['sent'] = []; $state['tags'] = []; uv_push_state_save($state);
$sent = []; $r = uv_push_dispatch('test', $ok);
$sentIds = array_map(function ($m) { return $m['data']['id']; }, $sent);
check($r['sent'] === count($items) && count($sent) === count($items), 'every new item is pushed once');
check($sent[0]['data']['ts'] <= end($sent)['data']['ts'], 'oldest is sent first so the newest is on top');
check(uv_push_dispatch('test', $ok)['sent'] === 0, 'a second run pushes nothing again');
$ins('INSERT INTO gallery_media (album_id, user_id, file, media_type, created_at) VALUES (?,?,?,?,?)', [$album, $userId, 'gallery/y.jpg', 'image', $at(5)]);
$sent = []; $r = uv_push_dispatch('test', $ok);
check($r['sent'] === 0 && $r['deferred'] === 1, 'more photos within ten minutes wait for the cool-down');
$state = uv_push_state(); $state['tags']["gallery:$album"] -= 601; uv_push_state_save($state);
$sent = []; $r = uv_push_dispatch('test', $ok);
check($r['sent'] === 1 && $sent[0]['data']['title'] === '5 new photos and videos in the gallery' && $sent[0]['data']['tag'] === "gallery:$album", 'after the cool-down one updated batch is pushed');
check(uv_push_dispatch('test', $ok)['sent'] === 0, 'the batch is not repeated');
$pdo->exec("DELETE FROM gallery_media WHERE file='gallery/y.jpg'");
check(uv_push_dispatch('test', $ok)['sent'] === 0, 'deleting a photo does not re-announce the older batch');

uv_app_settings_save(['types_off' => ['notice']]);
$n2 = $ins('INSERT INTO notices (title, body, posted_on, created_at) VALUES (?,?,CURDATE(),?)', ['T', 'B', $at(10)]);
check(uv_push_dispatch('test', $ok)['sent'] === 0, 'switched-off kinds are not pushed');
uv_app_settings_save(['types_off' => []]);
$fail = function ($m) { return ['ok' => false, 'error' => 'Google said no', 'fatal' => true]; };
$r = uv_push_dispatch('test', $fail);
check($r['status'] === 'error' && $r['error'] === 'Google said no' && uv_push_state()['failures']["notice:$n2"] === 1 && uv_push_state()['last_error']['message'] === 'Google said no', 'a failed send is recorded for the admin');
$n3 = $ins('INSERT INTO notices (title, body, posted_on, created_at) VALUES (?,?,CURDATE(),?)', ['T3', 'B', $at(11)]);
$sent = []; $r = uv_push_dispatch('test', $ok);
check($r['sent'] === 2 && uv_push_state()['last_error'] === null && !isset(uv_push_state()['failures']["notice:$n2"]), 'pending items are retried and the error clears');
$reject = function ($m) use (&$sent) { $sent[] = $m['message']; return ['ok' => false, 'error' => 'bad message', 'fatal' => false]; };
$ins('INSERT INTO notices (title, body, posted_on, created_at) VALUES (?,?,CURDATE(),?)', ['T4', 'B', $at(12)]);
$ins('INSERT INTO events (title, event_date, created_at) VALUES (?,CURDATE(),?)', ['E4', $at(13)]);
$sent = []; $r = uv_push_dispatch('test', $reject);
check($r['failed'] === 2 && count($sent) === 2, 'a rejected message does not block the next one');
for ($i = 0; $i < 6; $i++) uv_push_dispatch('test', $reject);
$sent = []; uv_push_dispatch('test', $ok);
check(count($sent) === 0, 'a message rejected five times is given up');
check(count(uv_push_state()['log']) <= 20, 'the admin log is bounded');
uv_push_remove_config();
check(uv_push_dispatch('test', $ok)['status'] === 'not_configured', 'no dispatch without Firebase files');
uv_push_store_config($gsJson, $saJson);

// ---------------------------------------------------------------- triggers
uv_app_delete('push-dispatch.last');
check(uv_app_interval_due('push-dispatch', 60) && !uv_app_interval_due('push-dispatch', 60), 'poll-triggered dispatch is throttled');

// ---------------------------------------------------------------- update prompt
$ua = [
    'app'       => 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0 Mobile Safari/537.36 UninavApp/1.8.0 (Android; build 180)',
    'v10'       => 'Mozilla/5.0 (Linux; Android 13; SM-A515F Build/TP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0 Mobile Safari/537.36 UninavAndroid/1.0',
    'plainwv'   => 'Mozilla/5.0 (Linux; Android 12; RMX3085 Build/SP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/118.0 Mobile Safari/537.36',
    'facebook'  => 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/460.0.0.48.109;]',
    'chrome'    => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    'desktop'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
];
$client = function (string $k, string $xrw = '') use ($ua) { return uv_app_client(['HTTP_USER_AGENT' => $ua[$k], 'HTTP_X_REQUESTED_WITH' => $xrw]); };
check($client('app') === ['kind' => 'app', 'version_name' => '1.8.0', 'version_code' => 180], 'new app is recognised with its version code');
check($client('v10')['kind'] === 'legacy' && $client('v10')['version_name'] === '1.0', 'v1.0 app is recognised');
check($client('plainwv')['kind'] === 'legacy', 'an unmarked WebView counts as an old install');
check($client('plainwv', UV_ANDROID_PACKAGE)['kind'] === 'legacy' && $client('chrome', UV_ANDROID_PACKAGE . '.debug')['kind'] === 'legacy', 'our package in X-Requested-With counts as an old install');
check($client('plainwv', 'com.whatsapp')['kind'] === 'none', 'another app\'s WebView is not ours');
check($client('facebook')['kind'] === 'none' && $client('chrome')['kind'] === 'none' && $client('desktop')['kind'] === 'none', 'browsers and in-app browsers never get the popup');
$html = function (string $k) use ($ua) { return uv_update_prompt_html(['HTTP_USER_AGENT' => $ua[$k]]); };
uv_app_json_save('play-listing.json', ['live' => false, 'checked_at' => time(), 'http_status' => 404]);
uv_app_settings_save(['update_mode' => 'auto']);
check($html('v10') === '' && !uv_update_prompt_active(), 'automatic: no popup before the Play listing is live');
check(uv_update_info_for_app()['prompt'] === false, 'automatic: the new app does not prompt for a listing that is not live');
uv_app_json_save('play-listing.json', ['live' => true, 'checked_at' => time(), 'http_status' => 200]);
check(strpos($html('v10'), 'id="uvAppUpdate"') !== false && strpos($html('v10'), 'https://play.google.com/store/apps/details?id=' . UV_ANDROID_PACKAGE) !== false, 'automatic: old installs get the popup once the listing is live');
check(strpos($html('plainwv'), 'uvAppUpdatePrompted') !== false, 'popup is remembered per app session');
check($html('app') === '' && $html('chrome') === '' && $html('facebook') === '', 'up-to-date app and browsers never get the web popup');
uv_app_settings_save(['update_mode' => 'off']);
check($html('v10') === '' && uv_update_info_for_app()['prompt'] === false, 'off: nobody is prompted');
uv_app_settings_save(['update_mode' => 'on', 'latest_version_code' => 181, 'min_version_code' => 175]);
uv_app_json_save('play-listing.json', ['live' => false, 'checked_at' => time(), 'http_status' => 404]);
check($html('v10') !== '' && uv_update_info_for_app() === ['prompt' => true, 'latest_version_code' => 181, 'min_version_code' => 175, 'store_url' => uv_android_store_url()], 'on: prompts regardless, with the version numbers for the app');
check(strpos(uv_update_prompt_html(['HTTP_USER_AGENT' => $ua['desktop']], true), 'id="uvAppUpdate"') !== false, 'admin preview always renders');
uv_app_settings_save(['update_mode' => 'auto', 'latest_version_code' => 0, 'min_version_code' => 0]);

// Play listing check over the fake network
$playStatus = 200;
uv_push_set_transport(function ($method, $url) use (&$playStatus) { return ['status' => strpos($url, 'play.google.com') !== false ? $playStatus : 0, 'body' => '', 'error' => '']; });
check(uv_play_listing_refresh()['live'] === true, 'HTTP 200 from Google Play means live');
$playStatus = 503; check(uv_play_listing_refresh()['live'] === true, 'a Play outage keeps the last answer');
$playStatus = 404; check(uv_play_listing_refresh()['live'] === false, 'HTTP 404 means not live');
uv_push_set_transport(null);

echo json_encode(['suite' => 'app-push-and-update-prompt', 'passed' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
