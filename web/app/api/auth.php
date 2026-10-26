<?php
/*
 * Registration (with email OTP), login (optional 2-step OTP),
 * forgot/reset password, logout and session info.
 */

function api_auth_register_otp()
{
    $name = in_str('name', 80, true, 'Name');
    $email = strtolower(in_str('email', 190, true, 'Email'));
    if (!valid_email($email)) {
        fail('Please enter a valid email address.', 422);
    }
    if (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        fail('An account with this email already exists. Please log in instead.', 409, 'email_taken');
    }
    $code = otp_issue($email, 'register');
    send_otp_email($email, $name, $code, 'register');
    return ['sent' => true, 'email' => $email, 'expires_in' => OTP_TTL, 'resend_in' => 60];
}

function api_auth_register()
{
    $name = in_str('name', 80, true, 'Name');
    if (str_len($name) < 2) {
        fail('Name must be at least 2 characters long.', 422);
    }
    $email = strtolower(in_str('email', 190, true, 'Email'));
    if (!valid_email($email)) {
        fail('Please enter a valid email address.', 422);
    }
    $password = (string)input('password', '');
    validate_password($password, (string)input('confirm_password', ''));
    $otp = in_str('otp', 12, true, 'OTP');
    if (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        fail('An account with this email already exists. Please log in instead.', 409, 'email_taken');
    }
    rate_limit_or_fail('register-ip:' . client_ip(), 15, 3600);

    otp_check($email, 'register', $otp);

    $id = q_insert('INSERT INTO users (name, username, email, password_hash, email_verified_at, presence, xp, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)', [
        $name, unique_username($name), $email, password_hash($password, PASSWORD_DEFAULT), now(), 'online', now(), now(),
    ]);
    award_xp($id, 50, 'welcome', 'signup');
    notify($id, 'welcome', 'Welcome to G VI Fan Hub!',
        'Set up your profile, add friends and drop your first pin on the Leonida map. +50 XP for joining!', '#/profile');
    return ['registered' => true, 'email' => $email];
}

function auth_complete_login(array $user, $remember)
{
    session_begin('user', $user['id'], $remember);
    q('UPDATE users SET last_login_at = ?, last_seen_at = ? WHERE id = ?', [now(), now(), $user['id']]);
    unset($GLOBALS['__gvi_user']);
    return ['logged_in' => true] + me_payload();
}

function api_auth_login()
{
    $login = strtolower(in_str('email', 190, true, 'Email'));
    $password = (string)input('password', '');
    $remember = in_bool('remember', true);
    if ($password === '') {
        fail('Password is required.', 422);
    }
    $accountKey = 'login-fail:' . $login;
    $ipKey = 'login-fail-ip:' . client_ip();
    if (rate_limited($accountKey, 8, 900) || rate_limited($ipKey, 40, 900)) {
        fail('Too many failed attempts. Please wait 15 minutes or use "Forgot password".', 429, 'rate_limited');
    }
    $user = q_one('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1', [$login, $login]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        rate_limit($accountKey, 100000, 900);
        rate_limit($ipKey, 100000, 900);
        fail('Incorrect email or password.', 401, 'bad_credentials');
    }
    if (user_is_banned($user)) {
        fail(ban_message($user), 403, 'banned', ['ban' => ban_info($user)]);
    }
    rate_limit_clear($accountKey);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    $mode = setting('login_otp_mode', 'optional');
    if ($mode === 'always' || ($mode === 'optional' && (int)$user['login_otp_enabled'] === 1)) {
        $pending = random_token(24);
        $code = otp_issue($user['email'], 'login', (int)$user['id'], hash('sha256', $pending));
        send_otp_email($user['email'], $user['name'], $code, 'login');
        return [
            'otp_required' => true,
            'otp_token'    => $pending,
            'email_hint'   => mask_email($user['email']),
            'resend_in'    => 60,
        ];
    }
    return auth_complete_login($user, $remember);
}

function api_auth_login_otp()
{
    $token = in_str('otp_token', 64, true, 'Login token');
    $otp = in_str('otp', 12, true, 'OTP');
    $remember = in_bool('remember', true);
    $hash = hash('sha256', $token);
    $row = q_one("SELECT email, user_id FROM email_otps WHERE ref_token = ? AND purpose = 'login' ORDER BY id DESC LIMIT 1", [$hash]);
    if (!$row) {
        fail('This login request has expired. Please sign in again.', 422, 'otp_expired');
    }
    otp_check($row['email'], 'login', $otp, $hash);
    $user = q_one('SELECT * FROM users WHERE id = ?', [(int)$row['user_id']]);
    if (!$user) {
        fail('Account not found.', 404);
    }
    if (user_is_banned($user)) {
        fail(ban_message($user), 403, 'banned', ['ban' => ban_info($user)]);
    }
    return auth_complete_login($user, $remember);
}

function api_auth_resend_login_otp()
{
    $token = in_str('otp_token', 64, true, 'Login token');
    $hash = hash('sha256', $token);
    $row = q_one("SELECT email, user_id, created_at FROM email_otps WHERE ref_token = ? AND purpose = 'login' ORDER BY id DESC LIMIT 1", [$hash]);
    if (!$row || strtotime($row['created_at'] . ' UTC') < time() - 1800) {
        fail('This login request has expired. Please sign in again.', 422, 'otp_expired');
    }
    $user = q_one('SELECT id, name, email FROM users WHERE id = ?', [(int)$row['user_id']]);
    if (!$user) {
        fail('Account not found.', 404);
    }
    $code = otp_issue($user['email'], 'login', (int)$user['id'], $hash);
    send_otp_email($user['email'], $user['name'], $code, 'login');
    return ['sent' => true, 'resend_in' => 60];
}

function api_auth_forgot()
{
    $email = strtolower(in_str('email', 190, true, 'Email'));
    if (!valid_email($email)) {
        fail('Please enter a valid email address.', 422);
    }
    rate_limit_or_fail('forgot-ip:' . client_ip(), 20, 3600);
    $user = q_one('SELECT id, name, email FROM users WHERE email = ?', [$email]);
    if ($user) {
        $code = otp_issue($email, 'reset', (int)$user['id']);
        send_otp_email($email, $user['name'], $code, 'reset');
    }
    // Same answer whether or not the account exists (prevents email discovery).
    return ['sent' => true, 'email' => $email, 'resend_in' => 60];
}

function api_auth_reset()
{
    $email = strtolower(in_str('email', 190, true, 'Email'));
    $otp = in_str('otp', 12, true, 'OTP');
    $password = (string)input('password', '');
    validate_password($password, (string)input('confirm_password', ''));
    $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user) {
        fail('Incorrect or expired OTP.', 422, 'otp_invalid');
    }
    otp_check($email, 'reset', $otp);
    q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), now(), $user['id']]);
    sessions_revoke_all('user', $user['id']);
    rate_limit_clear('login-fail:' . $email);
    notify($user['id'], 'security', 'Your password was reset',
        'Your password was changed using an email code. If this was not you, reset it again immediately.', '#/profile?tab=security');
    return ['reset' => true];
}

/* ----------------------------------------- account deletion request (no password needed) */

/** Step 1 of delete-account.php: emails a one-time code that proves the account is yours. */
function api_auth_delete_request()
{
    $email = strtolower(in_str('email', 190, true, 'Email'));
    if (!valid_email($email)) {
        fail('Please enter a valid email address.', 422);
    }
    rate_limit_or_fail('delete-ip:' . client_ip(), 20, 3600);
    $user = q_one('SELECT id, name, email FROM users WHERE email = ?', [$email]);
    if ($user) {
        $code = otp_issue($email, 'delete', (int)$user['id']);
        send_otp_email($email, $user['name'], $code, 'delete');
    }
    // Same answer whether or not the account exists (prevents email discovery).
    return ['sent' => true, 'email' => $email, 'resend_in' => 60];
}

/** Step 2: checks the code and permanently deletes the account and its data. */
function api_auth_delete_confirm()
{
    $email = strtolower(in_str('email', 190, true, 'Email'));
    $otp = in_str('otp', 12, true, 'Code');
    if (!in_bool('confirm')) {
        fail('Please confirm that you want to permanently delete your account.', 422, 'confirm_required');
    }
    $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user) {
        fail('Incorrect or expired code.', 422, 'otp_invalid');
    }
    otp_check($email, 'delete', $otp);
    // Checked through the session row so banned players can delete their data too.
    $sess = session_current('user');
    delete_user_account($user);
    if ($sess && (int)$sess['subject_id'] === (int)$user['id']) {
        auth_clear_cookie(USER_COOKIE);
    }
    $app = cfg('app.name', 'G VI Fan Hub');
    Mailer::send($email, 'Your ' . $app . ' account was deleted', mail_layout('Account deleted',
        'Hi ' . ($user['name'] ?: 'there') . ",
Your " . $app . ' account and its data have been permanently deleted, as you requested.', null,
        "If you didn't ask for this, reply to this email. You can create a new account at any time."));
    return ['deleted' => true];
}

function api_auth_logout()
{
    session_end('user');
    return ['logged_out' => true];
}

function api_auth_me()
{
    try {
        $user = current_user(false);
    } catch (ApiError $e) {
        if ($e->errorCode === 'banned') {
            return ['user' => null, 'ban' => $e->extra['ban'], 'ban_message' => $e->getMessage(), 'config' => public_config()];
        }
        throw $e;
    }
    if (!$user) {
        return ['user' => null, 'config' => public_config()];
    }
    return me_payload();
}

return [
    'register-otp'     => ['POST', 'public', 'api_auth_register_otp'],
    'register'         => ['POST', 'public', 'api_auth_register'],
    'login'            => ['POST', 'public', 'api_auth_login'],
    'login-otp'        => ['POST', 'public', 'api_auth_login_otp'],
    'resend-login-otp' => ['POST', 'public', 'api_auth_resend_login_otp'],
    'forgot'           => ['POST', 'public', 'api_auth_forgot'],
    'reset'            => ['POST', 'public', 'api_auth_reset'],
    'logout'           => ['POST', 'public', 'api_auth_logout'],
    'delete-request'   => ['POST', 'public', 'api_auth_delete_request'],
    'delete-confirm'   => ['POST', 'public', 'api_auth_delete_confirm'],
    'me'               => ['GET', 'public', 'api_auth_me'],
];
