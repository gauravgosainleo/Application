<?php
/*
 * G VI Fan Hub - scheduled job.
 *
 * Hostinger hPanel -> Advanced -> Cron Jobs -> "Custom" -> every hour:
 *     /usr/bin/php /home/<user>/domains/<domain>/public_html/cron/fetch-news.php
 *
 * It checks the "auto mode" setting chosen in the admin panel and only
 * fetches news when the selected interval (6, 12 or 24 hours) has passed.
 * New stories are saved as "pending" and wait for admin approval.
 * Add --force to fetch right now regardless of the interval.
 *
 * It can also be called over HTTP when app.cron_key is set in config.php:
 *     https://<domain>/cron/fetch-news.php?key=<cron_key>[&force=1]
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_DIR . '/lib/news_fetcher.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)cfg('app.cron_key', '');
    $given = isset($_GET['key']) ? (string)$_GET['key'] : '';
    if ($key === '' || !hash_equals($key, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!is_file(STORAGE_DIR . '/installed.lock')) {
    echo "Not installed yet.\n";
    exit(1);
}

$force = $isCli ? in_array('--force', isset($argv) ? $argv : [], true) : !empty($_GET['force']);

try {
    maintenance();
} catch (Exception $e) {
    echo 'Maintenance error: ' . $e->getMessage() . "\n";
}

if (!$force && setting('news_auto_enabled', '0') !== '1') {
    echo "News auto mode is OFF (enable it in Admin -> News -> Auto mode).\n";
    exit(0);
}
if (!$force && !NewsFetcher::isDue()) {
    echo 'Not due yet. Next run after ' . NewsFetcher::nextRunAt() . " UTC.\n";
    exit(0);
}

$result = NewsFetcher::run($isCli ? 'cron' : 'cron-url');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
