<?php
require_once __DIR__ . '/../includes/helpers.php';
if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_verified'])) redirect('auth/login.php');

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_check()) $err = 'Invalid session.';
    else {
        $p = $_POST['password'] ?? '';
        $c = $_POST['confirm_password'] ?? '';
        if (strlen($p) < 6) $err = 'Password too short.';
        elseif ($p !== $c)   $err = 'Passwords do not match.';
        else {
            $hash = password_hash($p, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash=? WHERE email=?')
                ->execute([$hash, $_SESSION['reset_email']]);
            unset($_SESSION['reset_email'], $_SESSION['reset_verified']);
            flash('msg','Password updated. Please login.');
            redirect('auth/login.php');
        }
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"></head>
<body class="auth-body"><div class="auth-wrap"><div class="auth-card">
<div class="brand"><div class="logo">UN</div><h1>Set New Password</h1></div>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <label>New Password</label>
  <input type="password" name="password" required>
  <label>Confirm Password</label>
  <input type="password" name="confirm_password" required>
  <button class="btn primary">Update</button>
</form>
</div></div></body></html>
