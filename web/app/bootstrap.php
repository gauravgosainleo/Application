<?php
/*
 * G VI Fan Hub - application bootstrap.
 * Loaded by api/index.php, install.php and cron scripts.
 */

define('APP_DIR', __DIR__);
define('WEB_DIR', dirname(__DIR__));
define('STORAGE_DIR', APP_DIR . '/storage');
define('APP_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$__configFile = file_exists(APP_DIR . '/config.local.php') ? APP_DIR . '/config.local.php' : APP_DIR . '/config.php';
if (!is_file($__configFile)) {
    // A missing config would otherwise be a blank 500; say what to fix instead.
    $msg = 'Server setup problem: app/config.php is missing. Upload app/config.php again with your database password (see README).';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    if (strpos(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '', '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg, 'code' => 'config_missing']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}
$GLOBALS['__gvi_config'] = require $__configFile;
unset($__configFile);

require_once APP_DIR . '/lib/helpers.php';
require_once APP_DIR . '/lib/db.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/mailer.php';
require_once APP_DIR . '/lib/upload.php';
require_once APP_DIR . '/lib/game.php';
require_once APP_DIR . '/lib/views.php';
