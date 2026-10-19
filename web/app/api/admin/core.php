<?php
/*
 * Admin: login, dashboard stats, site settings, map image, test email,
 * broadcast announcements and admin password.
 */

function admin_pending_counts()
{
    return [
        'news'    => (int)q_val("SELECT COUNT(*) FROM news WHERE status = 'pending'"),
        'gallery' => (int)q_val("SELECT COUNT(*) FROM gallery_items WHERE status = 'pending'"),
        'reports' => (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
    ];
}

function admin_me_payload()
{
    $a = current_admin();
    return [
        'admin'   => [
            'id'            => (int)$a['id'],
            'username'      => $a['username'],
            'display_name'  => $a['display_name'],
            'last_login_at' => iso($a['last_login_at']),
        ],
        'csrf'    => csrf_token('admin'),
        'config'  => public_config(),
        'pending' => admin_pending_counts(),
    ];
}

/** Bans a user for $days days (0 = permanent). Returns the ban end (UTC DATETIME). */
function admin_ban_user($userId, $days, $reason, $context = 'general', $ref = null)
{
    $admin = current_admin();
    $user = q_one('SELECT * FROM users WHERE id = ?', [(int)$userId]);
    if (!$user) {
        fail('User not found.', 404);
    }
    $days = (int)$days;
    if ($days < 0 || $days > 3650) {
        fail('Ban length must be between 1 and 3650 days (or 0 for permanent).', 422);
    }
    $until = $days > 0 ? dt_in($days * 86400) : '9999-12-31 23:59:59';
    q('UPDATE users SET banned_until = ?, ban_reason = ?, updated_at = ? WHERE id = ?', [$until, $reason, now(), $user['id']]);
    q('UPDATE user_bans SET lifted_at = ? WHERE user_id = ? AND lifted_at IS NULL', [now(), $user['id']]);
    q('INSERT INTO user_bans (user_id, admin_id, reason, days, context, context_ref, banned_until, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$user['id'], $admin['id'], $reason, $days > 0 ? $days : null, $context, $ref !== null ? str_limit($ref, 160) : null, $until, now()]);
    // Sessions are kept on purpose: the next request of the banned player gets a
    // 403 "banned" answer with the reason and end date, then the session is closed.

    $when = $days > 0 ? 'until ' . gmdate('d M Y, H:i', strtotime($until . ' UTC')) . ' UTC (' . $days . ' day' . ($days === 1 ? '' : 's') . ')' : 'permanently';
    Mailer::send($user['email'], 'Your ' . cfg('app.name', 'G VI Fan Hub') . ' account has been blocked',
        mail_layout('Account blocked', 'Hi ' . $user['name'] . ",\nYour account has been blocked {$when}.\nReason: {$reason}",
            null, 'If you believe this is a mistake, reply to this email.'));
    return $until;
}

function api_admin_login()
{
    $username = in_str('username', 60, true, 'Username');
    $password = (string)input('password', '');
    $key = 'admin-login-fail:' . client_ip();
    if (rate_limited($key, 10, 900)) {
        fail('Too many failed attempts. Please wait 15 minutes.', 429, 'rate_limited');
    }
    $admin = q_one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        rate_limit($key, 100000, 900);
        fail('Invalid admin username or password.', 401, 'bad_credentials');
    }
    rate_limit_clear($key);
    session_begin('admin', $admin['id'], false);
    q('UPDATE admins SET last_login_at = ? WHERE id = ?', [now(), $admin['id']]);
    unset($GLOBALS['__gvi_admin']);
    return admin_me_payload();
}

function api_admin_logout()
{
    session_end('admin');
    return ['logged_out' => true];
}

function api_admin_me()
{
    return current_admin(false) ? admin_me_payload() : ['admin' => null, 'config' => public_config()];
}

function api_admin_password()
{
    $a = current_admin();
    $row = q_one('SELECT * FROM admins WHERE id = ?', [$a['id']]);
    if (!password_verify((string)input('current_password', ''), $row['password_hash'])) {
        fail('Current password is incorrect.', 422);
    }
    $password = (string)input('password', '');
    if (strlen($password) < 6) {
        fail('The new password must be at least 6 characters.', 422);
    }
    if ($password !== (string)input('confirm_password', '')) {
        fail('Passwords do not match.', 422);
    }
    q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $a['id']]);
    sessions_revoke_all('admin', $a['id'], $a['_session_id']);
    return ['changed' => true];
}

function api_admin_stats()
{
    current_admin();
    require_once APP_DIR . '/lib/news_fetcher.php';
    NewsFetcher::scheduleIfDue();
    $now = now();
    $week = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    $online = gmdate('Y-m-d H:i:s', time() - ONLINE_WINDOW);
    $recent = array_map(function ($u) {
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => $u['email'],
            'avatar' => $u['avatar'], 'created_at' => iso($u['created_at'])];
    }, q_all('SELECT id, name, username, email, avatar, created_at FROM users ORDER BY id DESC LIMIT 6'));
    return [
        'users'           => (int)q_val('SELECT COUNT(*) FROM users'),
        'users_week'      => (int)q_val('SELECT COUNT(*) FROM users WHERE created_at > ?', [$week]),
        'online'          => (int)q_val("SELECT COUNT(*) FROM users WHERE last_seen_at > ? AND presence <> 'invisible'", [$online]),
        'banned'          => (int)q_val('SELECT COUNT(*) FROM users WHERE banned_until > ?', [$now]),
        'discussions'     => (int)q_val('SELECT COUNT(*) FROM discussions'),
        'comments'        => (int)q_val('SELECT COUNT(*) FROM discussion_comments'),
        'news_published'  => (int)q_val("SELECT COUNT(*) FROM news WHERE status = 'published'"),
        'markers'         => (int)q_val('SELECT COUNT(*) FROM map_markers'),
        'messages_today'  => (int)q_val('SELECT COUNT(*) FROM messages WHERE created_at > ?', [gmdate('Y-m-d 00:00:00')]),
        'polls_open'      => (int)q_val("SELECT COUNT(*) FROM polls WHERE status = 'open' AND (ends_at IS NULL OR ends_at > ?)", [$now]),
        'events_upcoming' => (int)q_val('SELECT COUNT(*) FROM events WHERE is_published = 1 AND (ends_at IS NULL OR ends_at > ?)', [$now]),
        'catalogue_items' => (int)q_val('SELECT COUNT(*) FROM catalogue_items'),
        'pending'         => admin_pending_counts(),
        'recent_users'    => $recent,
        'news_auto'       => [
            'enabled'     => setting('news_auto_enabled', '0') === '1',
            'interval'    => NewsFetcher::intervalHours(),
            'last_run_at' => iso(setting('news_last_run_at')),
            'next_run_at' => iso(NewsFetcher::nextRunAt()),
        ],
        'release_at'      => setting('release_at', '2026-11-19T00:00:00'),
    ];
}

function api_admin_settings()
{
    current_admin();
    $checks = [
        'php_version'   => PHP_VERSION,
        'pdo_mysql'     => extension_loaded('pdo_mysql'),
        'gd'            => function_exists('imagecreatetruecolor'),
        'curl'          => function_exists('curl_init'),
        'simplexml'     => function_exists('simplexml_load_string'),
        'openssl'       => extension_loaded('openssl'),
        'uploads_write' => is_writable(WEB_DIR . '/uploads'),
        'storage_write' => is_writable(STORAGE_DIR),
        'upload_max'    => ini_get('upload_max_filesize'),
        'post_max'      => ini_get('post_max_size'),
    ];
    return [
        'release_at'                => setting('release_at', '2026-11-19T00:00:00'),
        'release_title'             => setting('release_title', 'Grand Theft Auto VI'),
        'release_platforms'         => setting('release_platforms', 'PlayStation 5 · Xbox Series X|S'),
        'announcement'              => (string)setting('announcement', ''),
        'login_otp_mode'            => setting('login_otp_mode', 'optional'),
        'gallery_requires_approval' => setting('gallery_requires_approval', '1') === '1',
        'map'                       => public_config()['map'],
        'mail'                      => [
            'transport' => cfg('mail.transport'),
            'host'      => cfg('mail.host'),
            'port'      => cfg('mail.port'),
            'from'      => cfg('mail.from_email'),
            'configured'=> cfg('mail.transport') !== 'smtp' || (cfg('mail.password') && cfg('mail.password') !== 'PUT_YOUR_EMAIL_PASSWORD_HERE'),
        ],
        'app_url'                   => app_url(),
        'cron_command'              => '/usr/bin/php ' . WEB_DIR . '/cron/fetch-news.php',
        'cron_url'                  => cfg('app.cron_key') ? app_url('cron/fetch-news.php?key=' . rawurlencode(cfg('app.cron_key'))) : null,
        'checks'                    => $checks,
    ];
}

function api_admin_settings_save()
{
    current_admin();
    if (has_input('release_at')) {
        $release = in_str('release_at', 40, true, 'Release date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+\-]\d{2}:\d{2})?$/', $release)) {
            fail('Release date must look like 2026-11-19T00:00 (optionally followed by Z or +05:30).', 422);
        }
        set_setting('release_at', strlen($release) === 16 ? $release . ':00' : $release);
    }
    if (has_input('release_title')) {
        set_setting('release_title', in_str('release_title', 80, true, 'Release title'));
    }
    if (has_input('release_platforms')) {
        set_setting('release_platforms', in_str('release_platforms', 120));
    }
    if (has_input('announcement')) {
        set_setting('announcement', in_text('announcement', 500));
    }
    if (has_input('login_otp_mode')) {
        set_setting('login_otp_mode', in_enum('login_otp_mode', ['off', 'optional', 'always']));
    }
    if (has_input('gallery_requires_approval')) {
        set_setting('gallery_requires_approval', in_bool('gallery_requires_approval') ? '1' : '0');
    }
    return api_admin_settings();
}

function api_admin_map_upload()
{
    current_admin();
    $path = Upload::image('file', 'map', ['max' => 6000]);
    $size = @getimagesize(WEB_DIR . '/' . $path);
    if (!$size) {
        Upload::delete($path);
        fail('Could not read the map image.', 422);
    }
    $old = setting('map_image', '');
    if ($old) {
        Upload::delete($old);
    }
    set_setting('map_image', $path);
    set_setting('map_width', (string)$size[0]);
    set_setting('map_height', (string)$size[1]);
    return public_config()['map'];
}

function api_admin_map_reset()
{
    current_admin();
    $old = setting('map_image', '');
    if ($old) {
        Upload::delete($old);
    }
    set_setting('map_image', '');
    set_setting('map_width', '2000');
    set_setting('map_height', '2600');
    return public_config()['map'];
}

function api_admin_test_email()
{
    current_admin();
    $to = strtolower(in_str('to', 190, true, 'Email'));
    if (!valid_email($to)) {
        fail('Enter a valid email address.', 422);
    }
    $ok = Mailer::send($to, 'Test email from ' . cfg('app.name', 'G VI Fan Hub'),
        mail_layout('Email is working!', "This is a test message from your admin panel.\nOTP emails for registration and password reset will be delivered like this one.", '123456'));
    if (!$ok) {
        fail('Sending failed: ' . Mailer::$lastError, 502, 'mail_failed');
    }
    return ['sent' => true, 'transport' => cfg('mail.transport')];
}

function api_admin_broadcast()
{
    current_admin();
    $title = in_str('title', 160, true, 'Title');
    $body = in_str('body', 300);
    $link = in_str('link', 255);
    if ($link !== '' && $link[0] !== '#' && !valid_url($link)) {
        fail('Link must be an app route like #/events or a full https:// link.', 422);
    }
    notify_all('announcement', $title, $body === '' ? null : $body, $link === '' ? null : $link);
    if (in_bool('banner')) {
        set_setting('announcement', $title . ($body !== '' ? ' — ' . $body : ''));
    }
    return ['sent_to' => (int)q_val('SELECT COUNT(*) FROM users WHERE banned_until IS NULL OR banned_until < ?', [now()])];
}

return [
    'login'         => ['POST', 'public', 'api_admin_login'],
    'logout'        => ['POST', 'public', 'api_admin_logout'],
    'me'            => ['GET', 'public', 'api_admin_me'],
    'password'      => ['POST', 'admin', 'api_admin_password'],
    'stats'         => ['GET', 'admin', 'api_admin_stats'],
    'settings'      => ['GET', 'admin', 'api_admin_settings'],
    'settings-save' => ['POST', 'admin', 'api_admin_settings_save'],
    'map-upload'    => ['POST', 'admin', 'api_admin_map_upload'],
    'map-reset'     => ['POST', 'admin', 'api_admin_map_reset'],
    'test-email'    => ['POST', 'admin', 'api_admin_test_email'],
    'broadcast'     => ['POST', 'admin', 'api_admin_broadcast'],
];
