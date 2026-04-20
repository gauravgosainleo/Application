<?php
require_once __DIR__ . '/../includes/helpers.php';
if (is_logged_in()) redirect('dashboard.php');

$err = '';
$old = ['tower'=>'', 'house_number'=>'', 'owner_name'=>'', 'email'=>'', 'username'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $err = 'Invalid session.'; }
    else {
        $tower    = $_POST['tower'] ?? '';
        $house    = trim($_POST['house_number'] ?? '');
        $owner    = trim($_POST['owner_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $cpass    = $_POST['confirm_password'] ?? '';
        $old = compact('tower','house','owner','email','username');

        if (!in_array($tower, range('A','G'), true))   $err = 'Choose a valid tower.';
        elseif ($house === '' || $owner === '')        $err = 'House number and owner name are required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Invalid email.';
        elseif ($username === '' || strlen($username) < 4)  $err = 'Username must be at least 4 chars.';
        elseif (strcasecmp($username, ADMIN_USERNAME)===0 || strcasecmp($username, GUEST_USERNAME)===0)
            $err = 'This username is reserved.';
        elseif (strlen($pass) < 6)                     $err = 'Password too short (min 6).';
        elseif ($pass !== $cpass)                      $err = 'Passwords do not match.';
        else {
            $stmt = db()->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) $err = 'Username or email already used.';
        }

        if (!$err) {
            // create user with email_verified=0
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            db()->prepare("INSERT INTO users (username,tower,house_number,owner_name,email,password_hash,role,email_verified,status) VALUES (?,?,?,?,?,?,?,0,'pending')")
                ->execute([$username,$tower,$house,$owner,$email,$hash,'resident']);

            // generate OTP
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            db()->prepare('INSERT INTO otp_codes (email,otp,purpose,expires_at) VALUES (?,?,?,?)')
                ->execute([$email,$otp,'register',date('Y-m-d H:i:s', time()+600)]);

            send_mail($email, 'Verify your Uninav Society account',
                "<p>Hi ".e($owner).",</p><p>Your OTP is <b>$otp</b>. It expires in 10 minutes.</p>");

            $_SESSION['pending_email'] = $email;
            redirect('auth/verify_otp.php');
        }
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"></head>
<body class="auth-body">
<div class="auth-wrap">
<div class="auth-card">
  <div class="brand"><div class="logo">UN</div><h1>Create Account</h1>
  <p class="muted">Register as a resident</p></div>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="grid2">
      <div>
        <label>Tower</label>
        <select name="tower" required>
          <option value="">Select</option>
          <?php foreach (range('A','G') as $t): ?>
            <option value="<?= $t ?>" <?= $old['tower']===$t?'selected':'' ?>>Tower <?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>House Number</label>
        <input type="text" name="house_number" value="<?= e($old['house']) ?>" required>
      </div>
    </div>
    <label>Owner Name</label>
    <input type="text" name="owner_name" value="<?= e($old['owner']) ?>" required>
    <label>Email</label>
    <input type="email" name="email" value="<?= e($old['email']) ?>" required>
    <label>Username</label>
    <input type="text" name="username" value="<?= e($old['username']) ?>" required>
    <div class="grid2">
      <div><label>Password</label><input type="password" name="password" required></div>
      <div><label>Confirm Password</label><input type="password" name="confirm_password" required></div>
    </div>
    <button type="submit" class="btn primary">Send OTP & Continue</button>
  </form>
  <div class="auth-links"><a href="login.php">Back to login</a></div>
</div>
</div>
</body></html>
