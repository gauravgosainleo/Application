<?php
/*
 * =====================================================================
 *  G VI Fan Hub - configuration
 * =====================================================================
 *  1. Put your MySQL password in 'pass' below (Hostinger hPanel ->
 *     Databases -> Management shows the database and user names).
 *  2. Fill in the 'mail' section so OTP emails can be sent
 *     (create a mailbox such as noreply@learninganddevelopment.net in
 *     hPanel -> Emails, then use that address and its password here).
 *  3. Upload the site and open  https://<your-domain>/install.php  once.
 *
 *  Tip: for local testing you can create config.local.php next to this
 *  file (same format). If it exists it is used instead of this file.
 * =====================================================================
 */
return [

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'u694536902_gta6',
        'user'    => 'u694536902_gta6',
        'pass'    => 'PUT_YOUR_DB_PASSWORD_HERE',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'     => 'G VI Fan Hub',
        // Long random string used to sign tokens. Leave empty and one is
        // generated automatically on first run (stored in app/storage/).
        'secret'   => '',
        // Show detailed PHP errors in API responses. Keep false in production.
        'debug'    => false,
        // Secret key for calling cron/fetch-news.php over HTTP
        // (e.g. https://<domain>/cron/fetch-news.php?key=THIS_VALUE).
        // Leave empty to allow only command-line cron (recommended).
        'cron_key' => '',
    ],

    'mail' => [
        // 'smtp' (recommended on Hostinger), 'mail' (PHP mail()) or
        // 'log' (development: writes emails to app/storage/mail.log)
        'transport'  => 'smtp',
        'host'       => 'smtp.hostinger.com',
        'port'       => 465,
        'encryption' => 'ssl',            // 'ssl' for port 465, 'tls' for port 587
        'username'   => 'noreply@learninganddevelopment.net',
        'password'   => 'PUT_YOUR_EMAIL_PASSWORD_HERE',
        'from_email' => 'noreply@learninganddevelopment.net',
        'from_name'  => 'G VI Fan Hub',
    ],

    'uploads' => [
        'max_image_mb' => 8,
        'max_video_mb' => 40,
    ],
];
