<?php
/*
 * General helpers: config, request input, JSON output, validation,
 * settings, rate limiting and small string utilities.
 */

class ApiError extends Exception
{
    public $status;
    public $errorCode;
    public $extra;

    public function __construct($message, $status = 400, $errorCode = null, $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }
}

function cfg($path, $default = null)
{
    $node = $GLOBALS['__gvi_config'];
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function fail($message, $status = 400, $code = null, $extra = [])
{
    throw new ApiError($message, $status, $code, $extra);
}

function now()
{
    return gmdate('Y-m-d H:i:s');
}

function dt_in($seconds)
{
    return gmdate('Y-m-d H:i:s', time() + (int)$seconds);
}

/** Convert a UTC DATETIME from the database to ISO-8601 for the browser. */
function iso($dt)
{
    if (!$dt) {
        return null;
    }
    return str_replace(' ', 'T', substr($dt, 0, 19)) . 'Z';
}

/** Parse a date/time sent by the browser (ISO string, ideally with offset) into UTC DATETIME. */
function parse_dt($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        fail('Invalid date/time: ' . $value);
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function send_json($payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function json_out($payload, $status = 200)
{
    send_json($payload, $status);
    exit;
}

function log_error($message)
{
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents(STORAGE_DIR . '/error.log', $line, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------ input */

function request_data()
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $data = [];
    $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
    if (strpos($type, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    } else {
        $data = $_POST;
    }
    foreach ($_GET as $k => $v) {
        if (!array_key_exists($k, $data)) {
            $data[$k] = $v;
        }
    }
    return $data;
}

function input($key, $default = null)
{
    $data = request_data();
    return array_key_exists($key, $data) ? $data[$key] : $default;
}

function has_input($key)
{
    return array_key_exists($key, request_data());
}

/** Remove control characters; keeps new lines only when $multiline is true. */
function clean_string($value, $multiline = false)
{
    if (is_array($value) || is_object($value)) {
        return '';
    }
    $value = (string)$value;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if ($multiline) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = preg_replace("/\n{4,}/", "\n\n\n", $value);
    } else {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    }
    return trim((string)$value);
}

function str_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function str_limit($s, $max, $suffix = '…')
{
    $s = (string)$s;
    if (str_len($s) <= $max) {
        return $s;
    }
    $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max - 1, 'UTF-8') : substr($s, 0, $max - 1);
    return rtrim($cut) . $suffix;
}

function in_str($key, $max = 255, $required = false, $label = null)
{
    $label = $label ?: ucfirst(str_replace('_', ' ', $key));
    $value = clean_string(input($key, ''));
    if ($required && $value === '') {
        fail($label . ' is required.', 422);
    }
    if (str_len($value) > $max) {
        fail($label . ' is too long (max ' . $max . ' characters).', 422);
    }
    return $value;
}

function in_text($key, $max = 5000, $required = false, $label = null)
{
    $label = $label ?: ucfirst(str_replace('_', ' ', $key));
    $value = clean_string(input($key, ''), true);
    if ($required && $value === '') {
        fail($label . ' is required.', 422);
    }
    if (str_len($value) > $max) {
        fail($label . ' is too long (max ' . $max . ' characters).', 422);
    }
    return $value;
}

function in_int($key, $default = 0)
{
    $v = input($key, null);
    if ($v === null || $v === '' || is_array($v)) {
        return $default;
    }
    return (int)$v;
}

function in_bool($key, $default = false)
{
    $v = input($key, null);
    if ($v === null || $v === '') {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string)$v), ['1', 'true', 'on', 'yes'], true);
}

function in_enum($key, array $allowed, $default = null)
{
    $v = (string)input($key, '');
    if ($v === '' && $default !== null) {
        return $default;
    }
    if (!in_array($v, $allowed, true)) {
        if ($default !== null) {
            return $default;
        }
        fail('Invalid value for ' . $key . '.', 422);
    }
    return $v;
}

/** Array of positive integer ids from a JSON array or comma separated string. */
function in_ids($key)
{
    $v = input($key, []);
    if (is_string($v)) {
        $decoded = json_decode($v, true);
        $v = is_array($decoded) ? $decoded : explode(',', $v);
    }
    if (!is_array($v)) {
        return [];
    }
    $ids = [];
    foreach ($v as $item) {
        $id = (int)$item;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function require_id($key = 'id')
{
    $id = in_int($key);
    if ($id <= 0) {
        fail('Missing or invalid ' . $key . '.', 422);
    }
    return $id;
}

function valid_email($email)
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valid_url($url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function in_url($key, $max = 700, $required = false, $label = null)
{
    $label = $label ?: ucfirst(str_replace('_', ' ', $key));
    $url = in_str($key, $max, $required, $label);
    if ($url !== '' && !valid_url($url)) {
        fail($label . ' must be a valid http(s) link.', 422);
    }
    return $url;
}

function validate_password($password, $confirm)
{
    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters long.', 422);
    }
    if (strlen($password) > 72) {
        fail('Password is too long (max 72 characters).', 422);
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        fail('Password must contain at least one letter and one number.', 422);
    }
    if ($password !== $confirm) {
        fail('Password and confirm password do not match.', 422);
    }
}

/* -------------------------------------------------------- utilities */

function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

function app_secret()
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $configured = (string)cfg('app.secret', '');
    if (strlen($configured) >= 16) {
        return $secret = $configured;
    }
    $file = STORAGE_DIR . '/app.key';
    if (is_file($file)) {
        $secret = trim((string)file_get_contents($file));
    }
    if (!$secret || strlen($secret) < 32) {
        $secret = random_token(32);
        @file_put_contents($file, $secret, LOCK_EX);
    }
    return $secret;
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0.0.0.0';
}

function user_agent()
{
    return isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
}

function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

/** URL path of the web root of the app, always ending in "/" (e.g. "/" or "/gvi/"). */
function base_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (preg_match('#/(api|cron|admin)$#', $dir)) {
        $dir = substr($dir, 0, (int)strrpos($dir, '/'));
    }
    return $dir . '/';
}

/** Absolute URL of the app (used in emails). */
function app_url($path = '')
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    return (is_https() ? 'https://' : 'http://') . $host . base_path() . ltrim($path, '/');
}

function slugify($text)
{
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, 60) : 'item';
}

/** Extract an 11 character YouTube video id from any common YouTube URL (or a bare id). */
function youtube_id($url)
{
    $url = trim((string)$url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    $patterns = [
        '#youtu\.be/([A-Za-z0-9_-]{11})#',
        '#youtube(?:-nocookie)?\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})#',
        '#youtube\.com/.*[?&]v=([A-Za-z0-9_-]{11})#',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) {
            return $m[1];
        }
    }
    return null;
}

/* --------------------------------------------------------- settings */

function settings_all()
{
    if (!isset($GLOBALS['__gvi_settings'])) {
        $GLOBALS['__gvi_settings'] = [];
        try {
            foreach (q_all('SELECT `key`, `value` FROM settings') as $row) {
                $GLOBALS['__gvi_settings'][$row['key']] = $row['value'];
            }
        } catch (Exception $e) {
            // table might not exist yet (before install)
        }
    }
    return $GLOBALS['__gvi_settings'];
}

function setting($key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
}

function set_setting($key, $value)
{
    q('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)', [$key, (string)$value, now()]);
    settings_all();
    $GLOBALS['__gvi_settings'][$key] = (string)$value;
}

/* ----------------------------------------------------- rate limiting */

/**
 * Fixed-window rate limiter stored in MySQL.
 * Returns true when the action is allowed (and counts it).
 */
function rate_limit($key, $max, $windowSeconds)
{
    $key = substr($key, 0, 190);
    $row = q_one('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
    $now = time();
    if (!$row || strtotime($row['window_start'] . ' UTC') + $windowSeconds <= $now) {
        q('REPLACE INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, ?)', [$key, now()]);
        return true;
    }
    if ((int)$row['hits'] >= $max) {
        return false;
    }
    q('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
    return true;
}

function rate_limit_or_fail($key, $max, $windowSeconds, $message = 'Too many attempts. Please wait a few minutes and try again.')
{
    if (!rate_limit($key, $max, $windowSeconds)) {
        fail($message, 429, 'rate_limited');
    }
}

function rate_limit_clear($key)
{
    q('DELETE FROM rate_limits WHERE rl_key = ?', [substr($key, 0, 190)]);
}

/** Checks a limiter without counting a hit. */
function rate_limited($key, $max, $windowSeconds)
{
    $row = q_one('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [substr($key, 0, 190)]);
    if (!$row || strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
        return false;
    }
    return (int)$row['hits'] >= $max;
}

/* ----------------------------------------------------- deferred jobs */

/** Queue work to run after the JSON response has been sent (e.g. news auto-fetch). */
function defer_job(callable $fn)
{
    $GLOBALS['__gvi_deferred'][] = $fn;
}

function run_deferred_jobs()
{
    if (empty($GLOBALS['__gvi_deferred'])) {
        return;
    }
    ignore_user_abort(true);
    @set_time_limit(180);
    finish_request_early();
    foreach ($GLOBALS['__gvi_deferred'] as $fn) {
        try {
            $fn();
        } catch (Throwable $e) {
            log_error('Deferred job failed: ' . $e->getMessage());
        }
    }
    $GLOBALS['__gvi_deferred'] = [];
}

/** Lets a long job continue after the HTTP response has been sent (FPM / LiteSpeed). */
function finish_request_early()
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return true;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return true;
    }
    return false;
}
