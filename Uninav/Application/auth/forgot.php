<?php
require_once __DIR__ . '/../includes/helpers.php';

$err = $msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_check()) $err = 'Invalid session.';
    else {
        $email = trim($_POST['email'] ?? '');
        $stmt = db()->prepare('SELECT id, owner_name FROM users WHERE email=? AND role="resident" LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) $err = 'No resident account with that email.';
        else {
            $otp = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
            db()->prepare('INSERT INTO otp_codes (email,otp,purpose,expires_at) VALUES (?,?,?,?)')
                ->execute([$email, $otp, 'reset', date('Y-m-d H:i:s', time()+600)]);
            send_mail($email, 'Uninav Password Reset OTP', "<p>Hi ".e($u['owner_name']).",</p><p>Your password reset OTP is <b>$otp</b>. Expires in 10 minutes.</p>");
            $_SESSION['reset_email'] = $email;
            redirect('auth/verify_otp.php');
        }
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"></head>
<body class="auth-body"><div class="auth-wrap"><div class="auth-card">
<div class="brand"><div class="logo">UN</div><h1>Forgot Password</h1>
<p class="muted">We'll email a reset OTP.</p></div>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <label>Registered Email</label>
  <input type="email" name="email" required>
  <button class="btn primary">Send OTP</button>
</form>
<div class="auth-links"><a href="login.php">Back to login</a></div>
</div></div></body></html>
