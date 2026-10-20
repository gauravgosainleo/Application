<?php
// ============================================================================
// api/app_updates.php - "what's new" feed for the Android app
//
// The app checks this in the background and shows a phone notification for
// anything society-wide that became public since its last check: notices,
// events, gallery albums/photos, polls, society ads, AOA changes, vendors,
// SPOC contacts and finance entries (see includes/app_feed.php).
//
// Since v1.8.0 the same items are also pushed instantly through Firebase once
// push is set up (includes/app_push.php); this poll then becomes the safety
// net, and the app de-duplicates the two by item id.
//
// No login: the app polls while closed, so no session exists. Instead the
// request carries the existing shared app key (?k=...). This key does not
// identify a resident, so only generic update text and sign-in links are returned.
//
//   GET api/app_updates.php?since=<unix ts>&k=<APP_NOTIFY_KEY>[&v=<versionCode>]
//   -> {"ok":true,"server_time":1727512345,
//       "items":[{id,tag,type,title,body,url,ts}],
//       "push":{project_id,app_id,api_key,sender_id,topic} | null}
//
// since=0 returns no items (the app uses it once to learn the server time).
// "push" lets an installed app switch to instant notifications in the
// background as soon as an admin sets push up - no app update needed.
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/app_push.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!defined('APP_NOTIFY_KEY') || !hash_equals(APP_NOTIFY_KEY, (string)($_GET['k'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$pdo   = db();
$now   = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
$since = (int)($_GET['since'] ?? 0);
$items = [];

if ($since > 0) {
    // Never replay more than a week, however long the phone was off.
    $items = uv_app_feed_items($pdo, max($since, $now - 7 * 86400), uv_app_enabled_feed_types(), 20);
}

// Every poll doubles as a push trigger (at most once a minute across all
// phones), so content added outside the web pages - or while a dispatch
// failed - still goes out promptly without cron.
uv_push_after_response('poll', 60);

echo json_encode([
    'ok'          => true,
    'server_time' => $now,
    'items'       => $items,
    'push'        => uv_push_client_config(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
