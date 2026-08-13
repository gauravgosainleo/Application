<?php
/**
 * Global configuration for AI Social Media Platform.
 * Deployed at: http://aiplatform.fun/Ai%20social%20media%20platform/Application/
 */

// -------- Database (Hostinger / phpMyAdmin) --------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u694536902_AIsocialmedia');
define('DB_USER', 'u694536902_AIsocialmedia');
define('DB_PASS', 'Gauravgosain@1991');
define('DB_CHARSET', 'utf8mb4');

// -------- Application base URL --------
define('APP_URL', 'http://aiplatform.fun/Ai%20social%20media%20platform/Application');
define('APP_NAME', 'AI Social Media Platform');

// -------- Admin hardcoded credentials (NOT exposed in UI) --------
define('ADMIN_USERNAME', 'AIadmin');
define('ADMIN_PASSWORD', '9911421242');

// -------- Razorpay (LIVE keys) --------
define('RAZORPAY_KEY_ID', 'rzp_live_SbqAvPFQbkcQMd');
define('RAZORPAY_KEY_SECRET', 'NG5d1Yb371D65xT98xG4ffjf');
define('AI_SHOP_LISTING_FEE', 499); // INR

// -------- Mailer (SMTP via Hostinger) --------
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', 'no-reply@aiplatform.fun');
define('SMTP_PASS', 'CHANGE_ME_IN_HOSTINGER');
define('SMTP_FROM_NAME', 'AI Platform');
define('SMTP_FROM_EMAIL', 'no-reply@aiplatform.fun');

// -------- Session / security --------
define('SESSION_LIFETIME', 60 * 60 * 6);
define('OTP_LIFETIME', 10 * 60);
define('RESET_LIFETIME', 30 * 60);

// -------- Paths --------
define('BASE_PATH', realpath(__DIR__ . '/..'));
define('UPLOAD_PATH', BASE_PATH . '/assets/uploads');
define('UPLOAD_URL', APP_URL . '/assets/uploads');

// -------- Error reporting --------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/error.log');
error_reporting(E_ALL);

// -------- Session start --------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
