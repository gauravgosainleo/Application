<?php
/*
 * Authentication: DB-backed session tokens in HttpOnly cookies,
 * CSRF tokens, bans and email OTP codes.
 */

const USER_COOKIE = 'gvi_session';
const ADMIN_COOKIE = 'gvi_admin';
const REMEMBER_TTL = 5184000;   // 60 days
const USER_TTL = 86400;         // 1 day (sliding) when "keep me signed in" is off
const ADMIN_TTL = 43200;        // 12 hours (sliding)
const OTP_TTL = 600;            // OTP codes are valid for 10 minutes
const OTP_MAX_ATTEMPTS = 5;

function auth_set_cookie($name, $value, $expires)
{
    if (headers_sent()) {
        return;
    }
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

function auth_clear_cookie($name)
{
    auth_set_cookie($name, '', time() - 3600);
    unset($_COOKIE[$name]);
}

function session_cookie_name($type)
{
    return $type === 'admin' ? ADMIN_COOKIE : USER_COOKIE;
}

function session_ttl($type, $remember)
{
    if ($remember) {
        return REMEMBER_TTL;
    }
    return $type === 'admin' ? ADMIN_TTL : USER_TTL;
}

/** Creates a session row and sets the cookie. Returns the raw token. */
function session_begin($type, $subjectId, $remember)
{
    $token = random_token(32);
    $ttl = session_ttl($type, $remember);
    q('INSERT INTO auth_sessions (subject_type, subject_id, token_hash, remember, user_agent, ip, created_at, last_used_at, expires_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $type, (int)$subjectId, hash('sha256', $token), $remember ? 1 : 0,
        user_agent(), client_ip(), now(), now(), dt_in($ttl),
    ]);
    auth_set_cookie(session_cookie_name($type), $token, $remember ? time() + $ttl : 0);
    unset($GLOBALS['__gvi_sess_' . $type]);
    if (mt_rand(1, 40) === 1) {
        q('DELETE FROM auth_sessions WHERE expires_at < ?', [now()]);
        q('DELETE FROM email_otps WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }
    return $token;
}

function session_current($type)
{
    $cacheKey = '__gvi_sess_' . $type;
    if (array_key_exists($cacheKey, $GLOBALS)) {
        return $GLOBALS[$cacheKey];
    }
    $GLOBALS[$cacheKey] = null;
    $name = session_cookie_name($type);
    $token = isset($_COOKIE[$name]) ? (string)$_COOKIE[$name] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $row = q_one('SELECT * FROM auth_sessions WHERE token_hash = ? AND subject_type = ? AND expires_at > ?',
        [hash('sha256', $token), $type, now()]);
    if (!$row) {
        auth_clear_cookie($name);
        return null;
    }
    if (strtotime($row['last_used_at'] . ' UTC') < time() - 600) {
        $ttl = session_ttl($type, (int)$row['remember'] === 1);
        q('UPDATE auth_sessions SET last_used_at = ?, expires_at = ? WHERE id = ?', [now(), dt_in($ttl), $row['id']]);
        if ((int)$row['remember'] === 1) {
            auth_set_cookie($name, $token, time() + $ttl);
        }
    }
    $row['raw_token'] = $token;
    return $GLOBALS[$cacheKey] = $row;
}

function session_end($type)
{
    $sess = session_current($type);
    if ($sess) {
        q('DELETE FROM auth_sessions WHERE id = ?', [$sess['id']]);
    }
    auth_clear_cookie(session_cookie_name($type));
    $GLOBALS['__gvi_sess_' . $type] = null;
}

function sessions_revoke_all($type, $subjectId, $exceptSessionId = null)
{
    if ($exceptSessionId) {
        return q_exec('DELETE FROM auth_sessions WHERE subject_type = ? AND subject_id = ? AND id <> ?', [$type, (int)$subjectId, (int)$exceptSessionId]);
    }
    return q_exec('DELETE FROM auth_sessions WHERE subject_type = ? AND subject_id = ?', [$type, (int)$subjectId]);
}

/* ------------------------------------------------------------- CSRF */

function csrf_token($type)
{
    $sess = session_current($type);
    if (!$sess) {
        return null;
    }
    return substr(hash_hmac('sha256', 'csrf|' . $type . '|' . $sess['raw_token'], app_secret()), 0, 40);
}

function verify_csrf($type)
{
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    $expected = csrf_token($type);
    if (!$expected || !hash_equals($expected, $sent)) {
        fail('Your session token is out of date. Please reload the page.', 419, 'csrf');
    }
}

/** Public POST endpoints only need proof the request came from our JavaScript (custom header => CORS preflight). */
function verify_ajax_header()
{
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        fail('Bad request.', 400, 'bad_request');
    }
}

/* ------------------------------------------------------------ users */

function user_is_banned($user)
{
    return !empty($user['banned_until']) && strtotime($user['banned_until'] . ' UTC') > time();
}

function ban_info($user)
{
    $permanent = !empty($user['banned_until']) && (int)substr($user['banned_until'], 0, 4) >= 9000;
    return [
        'until'     => $permanent ? null : iso($user['banned_until']),
        'permanent' => $permanent,
        'reason'    => $user['ban_reason'] ?: 'Violation of the community rules.',
    ];
}

function ban_message($user)
{
    $info = ban_info($user);
    if ($info['permanent']) {
        return 'Your account has been permanently blocked. Reason: ' . $info['reason'];
    }
    return 'Your account is blocked until ' . gmdate('d M Y, H:i', strtotime($user['banned_until'] . ' UTC')) . ' UTC. Reason: ' . $info['reason'];
}

function current_user($required = true)
{
    if (!array_key_exists('__gvi_user', $GLOBALS)) {
        $GLOBALS['__gvi_user'] = null;
        $sess = session_current('user');
        if ($sess) {
            $user = q_one('SELECT * FROM users WHERE id = ?', [$sess['subject_id']]);
            if (!$user) {
                session_end('user');
            } elseif (user_is_banned($user)) {
                session_end('user');
                fail(ban_message($user), 403, 'banned', ['ban' => ban_info($user)]);
            } else {
                $user['_session_id'] = (int)$sess['id'];
                $GLOBALS['__gvi_user'] = $user;
                touch_user_activity($user);
            }
        }
    }
    $user = $GLOBALS['__gvi_user'];
    if (!$user && $required) {
        fail('Please log in to continue.', 401, 'unauthenticated');
    }
    return $user;
}

function uid()
{
    $u = current_user();
    return (int)$u['id'];
}

/** Updates last seen (throttled) and grants the daily login XP / streak. */
function touch_user_activity(array &$user)
{
    $now = time();
    $today = gmdate('Y-m-d');
    if (empty($user['last_seen_at']) || strtotime($user['last_seen_at'] . ' UTC') < $now - 45) {
        q('UPDATE users SET last_seen_at = ? WHERE id = ?', [now(), $user['id']]);
        $user['last_seen_at'] = now();
    }
    if ($user['last_streak_date'] !== $today) {
        $yesterday = gmdate('Y-m-d', $now - 86400);
        $streak = $user['last_streak_date'] === $yesterday ? (int)$user['login_streak'] + 1 : 1;
        q('UPDATE users SET login_streak = ?, last_streak_date = ? WHERE id = ?', [$streak, $today, $user['id']]);
        $user['login_streak'] = $streak;
        $user['last_streak_date'] = $today;
        $bonus = $streak >= 7 ? 15 : ($streak >= 3 ? 5 : 0);
        award_xp((int)$user['id'], 10 + $bonus, 'daily_login', $today);
    }
}

function current_admin($required = true)
{
    if (!array_key_exists('__gvi_admin', $GLOBALS)) {
        $GLOBALS['__gvi_admin'] = null;
        $sess = session_current('admin');
        if ($sess) {
            $admin = q_one('SELECT id, username, display_name, last_login_at, created_at FROM admins WHERE id = ?', [$sess['subject_id']]);
            if ($admin) {
                $admin['_session_id'] = (int)$sess['id'];
                $GLOBALS['__gvi_admin'] = $admin;
            } else {
                session_end('admin');
            }
        }
    }
    $admin = $GLOBALS['__gvi_admin'];
    if (!$admin && $required) {
        fail('Admin login required.', 401, 'admin_unauthenticated');
    }
    return $admin;
}

/* -------------------------------------------------------------- OTP */

function otp_issue($email, $purpose, $userId = null, $refTokenHash = null)
{
    $email = strtolower($email);
    $last = q_one('SELECT created_at FROM email_otps WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$email, $purpose]);
    if ($last) {
        $wait = strtotime($last['created_at'] . ' UTC') + 60 - time();
        if ($wait > 0) {
            fail('Please wait ' . $wait . ' seconds before requesting another code.', 429, 'otp_cooldown', ['retry_after' => $wait]);
        }
    }
    rate_limit_or_fail('otp:' . $purpose . ':' . $email, 6, 3600, 'Too many codes requested for this email. Please try again in an hour.');
    rate_limit_or_fail('otp-ip:' . client_ip(), 25, 3600, 'Too many codes requested from this network. Please try again later.');

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE email_otps SET consumed_at = ? WHERE email = ? AND purpose = ? AND consumed_at IS NULL', [now(), $email, $purpose]);
    q('INSERT INTO email_otps (email, purpose, code_hash, ref_token, user_id, attempts, ip, created_at, expires_at)
       VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)', [
        $email, $purpose, password_hash($code, PASSWORD_DEFAULT), $refTokenHash, $userId, client_ip(), now(), dt_in(OTP_TTL),
    ]);
    return $code;
}

/** Validates and consumes an OTP. Throws ApiError on failure, returns the OTP row on success. */
function otp_check($email, $purpose, $code, $refTokenHash = null)
{
    $email = strtolower($email);
    $row = q_one('SELECT * FROM email_otps WHERE email = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1', [$email, $purpose]);
    if (!$row) {
        fail('No active verification code. Please request a new OTP.', 422, 'otp_missing');
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        fail('This OTP has expired. Please request a new one.', 422, 'otp_expired');
    }
    if ($refTokenHash !== null && !hash_equals((string)$row['ref_token'], (string)$refTokenHash)) {
        fail('This login request has expired. Please sign in again.', 422, 'otp_mismatch');
    }
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        fail('Too many incorrect attempts. Please request a new OTP.', 429, 'otp_locked');
    }
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6 || !password_verify($code, $row['code_hash'])) {
        q('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        $left = OTP_MAX_ATTEMPTS - 1 - (int)$row['attempts'];
        fail('Incorrect OTP.' . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : ' Please request a new code.'), 422, 'otp_invalid');
    }
    q('UPDATE email_otps SET consumed_at = ? WHERE id = ?', [now(), $row['id']]);
    return $row;
}
