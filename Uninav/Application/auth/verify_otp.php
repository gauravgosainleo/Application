<?php
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['pending_email']) && empty($_SESSION['reset_email'])) redirect('auth/login.php');

$purpose = !empty($_SESSION['reset_email']) ? 'reset' : 'register';
$email   = $_SESSION['reset_email'] ?? $_SESSION['pending_email'];

$err = $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) $err = 'Invalid session.';
    else {
        $otp = trim($_POST['otp'] ?? '');
        if ($otp === 'resend') {
            $new = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
            db()->prepare('INSERT INTO otp_codes (email,otp,purpose,expires_at) VALUES (?,?,?,?)')
                ->execute([$email,$new,$purpose,date('Y-m-d H:i:s', time()+600)]);
            send_mail($email, 'Your Uninav OTP', "<p>Your OTP is <b>$new</b>. Expires in 10 minutes.</p>");
            $msg = 'A new OTP was sent to your email.';
        } else {
            $stmt = db()->prepare('SELECT * FROM otp_codes WHERE email=? AND otp=? AND purpose=? AND used=0 AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
            $stmt->execute([$email,$otp,$purpose]);
            $row = $stmt->fetch();
            if (!$row) $err = 'Invalid or expired OTP.';
            else {
                db()->prepare('UPDATE otp_codes SET used=1 WHERE id=?')->execute([$row['id']]);
                if ($purpose === 'register') {
                    db()->prepare('UPDATE users SET email_verified=1 WHERE email=?')->execute([$email]);
                    // Notify admin of new pending registration
                    $u = db()->prepare('SELECT owner_name, tower, house_number, username FROM users WHERE email=?');
                    $u->execute([$email]); $info = $u->fetch();
                    if ($info) send_mail(COMPLAINTS_INBOX, 'New resident pending approval',
                        "<p>A new resident has verified their email and is awaiting approval:</p>
                         <ul><li>Name: ".e($info['owner_name'])."</li>
                         <li>House: ".e($info['tower']).'-'.e($info['house_number'])."</li>
                         <li>Username: ".e($info['username'])."</li></ul>
                         <p>Approve in admin Settings → Pending Approvals.</p>");
                    unset($_SESSION['pending_email']);
                    flash('msg', 'Email verified. Your account is now awaiting admin approval.');
                    redirect('auth/login.php');
                } else {
                    $_SESSION['reset_verified'] = true;
                    redirect('auth/reset_password.php');
                }
            }
        }
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify OTP - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"></head>
<body class="auth-body">
<div class="auth-wrap"><div class="auth-card">
<div class="brand"><div class="logo">UN</div><h1>Verify OTP</h1>
<p class="muted">We sent a 6-digit code to <b><?= e($email) ?></b></p></div>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <label>Enter OTP</label>
  <input type="text" name="otp" maxlength="6" required autofocus>
  <button class="btn primary">Verify</button>
</form>
<form method="post" class="form" style="margin-top:8px;">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="otp" value="resend">
  <button class="btn ghost">Resend OTP</button>
</form>
<div class="auth-links"><a href="login.php">Back to login</a></div>
</div></div>
</body></html>
