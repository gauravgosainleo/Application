<?php
require_once __DIR__ . '/../includes/helpers.php';
if (is_logged_in()) redirect('dashboard.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) $err = 'Invalid session, please try again.';
    else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Guest shortcut
        if ($username === GUEST_USERNAME && $password === GUEST_PASSWORD) {
            session_regenerate_id(true);
            $stmt = db()->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([GUEST_USERNAME]);
            $_SESSION['user_id']  = $stmt->fetchColumn();
            $_SESSION['is_guest'] = true;
            redirect('dashboard.php');
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password_hash'])) {
            if ($u['role'] === 'resident' && !$u['email_verified']) {
                $err = 'Email not verified. Check your inbox for OTP.';
            } elseif ($u['role'] === 'resident' && $u['status'] === 'pending') {
                $err = 'Your account is awaiting admin approval. You\'ll be notified once approved.';
            } elseif ($u['status'] === 'deleted') {
                $err = 'This account has been removed by the admin. Please register again.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $u['id'];
                redirect('dashboard.php');
            }
        } else {
            $err = 'Invalid credentials.';
        }
    }
}
$msg = flash('msg');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand">
      <div class="logo">UN</div>
      <h1>Uninav Society</h1>
      <p class="muted">Residential community portal</p>
    </div>
    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <label>Username</label>
      <input type="text" name="username" autocomplete="username" required>
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" required>
      <button class="btn primary" type="submit">Login</button>
    </form>
    <div class="auth-links">
      <a href="register.php">Register</a>
      <a href="forgot.php">Forgot password?</a>
    </div>
  </div>
</div>
</body>
</html>
