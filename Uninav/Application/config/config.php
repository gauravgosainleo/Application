<?php
// =====================================================================
// Uninav Society Management - Global Config
// =====================================================================

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'u694536902_uninav');
define('DB_USER', 'u694536902_Gauravuninav');
define('DB_PASS', 'Gauravgosain@1991');
define('DB_CHARSET', 'utf8mb4');

// Application
define('APP_NAME', 'Uninav Society');
define('APP_URL',  'http://learninganddevelopment.net/Uninav/Application');
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

// Mail
define('MAIL_FROM',       'no-reply@learninganddevelopment.net');
define('MAIL_FROM_NAME',  'Uninav Society');
define('COMPLAINTS_INBOX','gauravgosain@ymail.com');

// Admin / Guest credentials (seeded at install time)
define('ADMIN_USERNAME',  'Uniadmin');
define('ADMIN_PASSWORD',  '99114212');
define('GUEST_USERNAME',  'Guest');
define('GUEST_PASSWORD',  'Guest');

// Sessions
if (session_status() === PHP_SESSION_NONE) {
    session_name('UNINAVSESS');
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error reporting (turn off on production)
ini_set('display_errors', '0');
error_reporting(E_ALL);
