<?php
// ============================================================================
// api/app_config.php - settings the Android app reads when it opens  (v1.8.0)
//
//   GET api/app_config.php?k=<APP_NOTIFY_KEY>&v=<versionCode>
//   -> {"ok":true,"server_time":1760000000,
//       "update":{"prompt":true,"latest_version_code":0,"min_version_code":0,
//                 "store_url":"https://play.google.com/store/apps/details?id=..."},
//       "push":{project_id,app_id,api_key,sender_id,topic} | null}
//
// "update" drives the in-app "please update" dialog (includes/app_update_prompt.php).
// "push" is the Android app's public Firebase configuration plus the topic to
// subscribe to; null until an admin sets push up in Admin > Mobile app. These
// values ship inside every Firebase app and are not secret; the server's
// service account key never leaves the server.
//
// Same shared key as api/app_updates.php, and the same rule: nothing here
// identifies a resident or reveals society content.
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/app_update_prompt.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!defined('APP_NOTIFY_KEY') || !hash_equals(APP_NOTIFY_KEY, (string)($_GET['k'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

echo json_encode([
    'ok'          => true,
    'server_time' => time(),
    'update'      => uv_update_info_for_app(),
    'push'        => uv_push_client_config(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
