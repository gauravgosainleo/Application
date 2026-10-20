<?php
// Optional safety net for instant notifications. Pushes are already sent right
// after content is published and whenever a phone polls; scheduling this with
// PHP CLI once a minute also covers content added directly in the database.
//   * * * * * php /path/to/Application/cron/push_updates.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/app_push.php';
try {
    $result = uv_push_dispatch('cron');
    echo date('c') . ' Push: ' . json_encode($result) . "\n";
    if (($result['status'] ?? '') === 'error') {
        fwrite(STDERR, 'Push failed: ' . ($result['error'] ?? 'unknown error') . "\n");
        exit(1);
    }
} catch (Throwable $e) {
    error_log('Push dispatch failed: ' . $e->getMessage());
    fwrite(STDERR, "Push dispatch failed. Check the PHP error log.\n");
    exit(1);
}
