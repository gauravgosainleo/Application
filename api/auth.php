<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = read_json_input();

switch ($action) {
    case 'send_register_otp': send_register_otp($input); break;
    case 'verify_otp':        verify_register_otp($input); break;
    case 'register':          register_user($input); break;
    case 'login':             login($input); break;
    case 'logout':            logout(); break;
    case 'forgot':            forgot_password($input); break;
    case 'reset':             reset_password($input); break;
    case 'me':                me(); break;
    default: json_response(['success' => false, 'message' => 'Unknown action'], 400);
}

function send_register_otp(array $d): void {
    $email = strtolower(trim($d['email'] ?? ''));
    $name  = sanitize($d['name'] ?? 'User');
    if (!is_valid_email($email)) json_response(['success'=>false,'message'=>'Invalid email'],400);
    $exists = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        json_response(['success'=>false,'message'=>'Email already registered. Please login.'],400);
    }
    $code = generate_otp();
    $exp  = date('Y-m-d H:i:s', time() + OTP_LIFETIME);
    db()->prepare('INSERT INTO otp_codes (email, code, purpose, expires_at) VALUES (?, ?, "register", ?)')
        ->execute([$email, $code, $exp]);
    $sent = send_mail($email, 'Your AI Platform Verification Code',
        otp_email_html($name, $code, 'verification'));
    log_event(null, $email, 'register_otp_sent', $sent ? 'success' : 'failed');
    json_response([
        'success' => $sent,
        'message' => $sent ? 'OTP sent to your email.' : 'Could not send email. Check SMTP config.',
    ]);
}

function verify_register_otp(array $d): void {
    $email = strtolower(trim($d['email'] ?? ''));
    $code  = trim($d['code'] ?? '');
    if (!$email || !$code) json_response(['success'=>false,'message'=>'Email and code required'],400);
    $stmt = db()->prepare(
        'SELECT id FROM otp_codes WHERE email = ? AND code = ? AND purpose = "register"
         AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch();
    if (!$row) json_response(['success'=>false,'message'=>'Invalid or expired code'],400);
    db()->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
    $_SESSION['otp_verified_email'] = $email;
    log_event(null, $email, 'register_otp_verified', 'success');
    json_response(['success'=>true,'message'=>'Email verified. Complete registration.']);
}

function register_user(array $d): void {
    $name     = sanitize($d['name'] ?? '');
    $email    = strtolower(trim($d['email'] ?? ''));
    $pass     = $d['password'] ?? '';
    $confirm  = $d['confirm_password'] ?? '';

    if (!$name || strlen($name) < 2) json_response(['success'=>false,'message'=>'Name required'],400);
    if (!is_valid_email($email))     json_response(['success'=>false,'message'=>'Invalid email'],400);
    if (strlen($pass) < 6)           json_response(['success'=>false,'message'=>'Password must be at least 6 chars'],400);
    if ($pass !== $confirm)          json_response(['success'=>false,'message'=>'Passwords do not match'],400);
    if (($_SESSION['otp_verified_email'] ?? '') !== $email) {
        json_response(['success'=>false,'message'=>'Please verify your email with OTP first'],400);
    }

    $exists = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) json_response(['success'=>false,'message'=>'Email already registered'],400);

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,role,is_verified) VALUES (?,?,?, "user", 1)');
    $stmt->execute([$name, $email, $hash]);
    $uid = (int) db()->lastInsertId();

    unset($_SESSION['otp_verified_email']);
    log_event($uid, $email, 'register', 'success');
    json_response(['success'=>true,'message'=>'Registered. You can now log in.']);
}

function login(array $d): void {
    $email = strtolower(trim($d['email'] ?? ''));
    $pass  = $d['password'] ?? '';

    if ($email === strtolower(ADMIN_USERNAME) && $pass === ADMIN_PASSWORD) {
        $_SESSION['user_id']  = 0;
        $_SESSION['is_admin'] = true;
        $_SESSION['name']     = 'Administrator';
        $_SESSION['email']    = 'admin@aiplatform.fun';
        log_event(null, ADMIN_USERNAME, 'admin_login', 'success');
        json_response([
            'success' => true, 'is_admin' => true,
            'user' => ['name' => 'Administrator', 'email' => 'admin@aiplatform.fun'],
        ]);
    }

    if (!is_valid_email($email) || !$pass) {
        log_event(null, $email, 'login', 'failed', 'missing credentials');
        json_response(['success'=>false,'message'=>'Invalid credentials'],400);
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($pass, $user['password_hash'])) {
        log_event($user['id'] ?? null, $email, 'login', 'failed', 'bad password');
        json_response(['success'=>false,'message'=>'Invalid email or password'],400);
    }

    $_SESSION['user_id']  = (int) $user['id'];
    $_SESSION['is_admin'] = ($user['role'] === 'admin');
    $_SESSION['name']     = $user['name'];
    $_SESSION['email']    = $user['email'];
    log_event($user['id'], $email, 'login', 'success');
    json_response([
        'success' => true, 'is_admin' => (bool)$_SESSION['is_admin'],
        'user' => [
            'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'],
            'profile_picture' => $user['profile_picture'],
        ],
    ]);
}

function logout(): void {
    $uid = $_SESSION['user_id'] ?? null;
    $em  = $_SESSION['email']   ?? '';
    session_destroy();
    log_event($uid, $em, 'logout', 'success');
    json_response(['success'=>true]);
}

function forgot_password(array $d): void {
    $email = strtolower(trim($d['email'] ?? ''));
    if (!is_valid_email($email)) json_response(['success'=>false,'message'=>'Invalid email'],400);

    $stmt = db()->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) json_response(['success'=>true,'message'=>'If the email exists, an OTP has been sent.']);

    $code = generate_otp();
    $exp  = date('Y-m-d H:i:s', time() + OTP_LIFETIME);
    db()->prepare('INSERT INTO otp_codes (email, code, purpose, expires_at) VALUES (?, ?, "reset", ?)')
        ->execute([$email, $code, $exp]);
    send_mail($email, 'Reset your AI Platform password', otp_email_html($user['name'], $code, 'password reset'));
    log_event($user['id'], $email, 'forgot_otp_sent', 'success');
    json_response(['success'=>true,'message'=>'Password reset OTP sent.']);
}

function reset_password(array $d): void {
    $email   = strtolower(trim($d['email'] ?? ''));
    $code    = trim($d['code'] ?? '');
    $newpass = $d['new_password'] ?? '';
    $confirm = $d['confirm_password'] ?? '';
    if (!$email || !$code)     json_response(['success'=>false,'message'=>'Email and code required'],400);
    if (strlen($newpass) < 6)  json_response(['success'=>false,'message'=>'Password too short'],400);
    if ($newpass !== $confirm) json_response(['success'=>false,'message'=>'Passwords do not match'],400);

    $stmt = db()->prepare(
        'SELECT id FROM otp_codes WHERE email = ? AND code = ? AND purpose = "reset"
         AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch();
    if (!$row) json_response(['success'=>false,'message'=>'Invalid or expired code'],400);

    $hash = password_hash($newpass, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hash, $email]);
    db()->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
    log_event(null, $email, 'password_reset', 'success');
    json_response(['success'=>true,'message'=>'Password updated. Please log in.']);
}

function me(): void {
    if (!empty($_SESSION['is_admin'])) {
        json_response(['success'=>true,'is_admin'=>true,'user'=>[
            'id' => 0, 'name' => 'Administrator', 'email' => 'admin@aiplatform.fun',
        ]]);
    }
    if (empty($_SESSION['user_id'])) json_response(['success'=>false,'message'=>'Not logged in'],401);
    $stmt = db()->prepare('SELECT id, name, email, phone, profile_picture, bio, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) json_response(['success'=>false,'message'=>'User not found'],404);
    json_response(['success'=>true,'is_admin'=>false,'user'=>$user]);
}
