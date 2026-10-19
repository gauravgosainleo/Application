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
$GLOBALS['__gvi_config'] = require $__configFile;
unset($__configFile);

require_once APP_DIR . '/lib/helpers.php';
require_once APP_DIR . '/lib/db.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/mailer.php';
require_once APP_DIR . '/lib/upload.php';
require_once APP_DIR . '/lib/game.php';
require_once APP_DIR . '/lib/views.php';
