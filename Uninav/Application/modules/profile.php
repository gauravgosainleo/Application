<?php
require_once __DIR__ . '/../includes/layout.php';
$U = current_user();
if ($U['role'] === 'guest') redirect('dashboard.php');
$err = $msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check()) {
    $a = $_POST['action'] ?? '';
    if ($a === 'update') {
        $phone = trim($_POST['phone'] ?? '');
        $name  = trim($_POST['owner_name'] ?? '');
        $photoRel = $U['photo'];
        $newPhoto = upload_file($_FILES['photo'] ?? [], 'profiles');
        if ($newPhoto) $photoRel = $newPhoto;
        db()->prepare('UPDATE users SET owner_name=?, phone=?, photo=? WHERE id=?')
            ->execute([$name,$phone,$photoRel,$U['id']]);
        $msg = 'Profile updated.';
        $U = current_user();
    }
    if ($a === 'password') {
        $cur = $_POST['current'] ?? ''; $new=$_POST['new']??''; $c=$_POST['confirm']??'';
        if (!password_verify($cur, $U['password_hash'])) $err='Current password is wrong.';
        elseif (strlen($new)<6) $err='New password too short.';
        elseif ($new !== $c) $err='Passwords do not match.';
        else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $U['id']]);
            $msg='Password changed.';
        }
    }
    if ($a === 'request_email_change') {
        $new = trim($_POST['new_email'] ?? '');
        if (!filter_var($new,FILTER_VALIDATE_EMAIL)) $err='Invalid email.';
        else {
            $otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
            db()->prepare('INSERT INTO otp_codes (email,otp,purpose,expires_at) VALUES (?,?,?,?)')
                ->execute([$U['email'],$otp,'change_email',date('Y-m-d H:i:s', time()+600)]);
            $_SESSION['change_email_to'] = $new;
            send_mail($U['email'], 'Confirm email change',
                "<p>Hi ".e($U['owner_name']).",</p><p>An OTP has been generated to change your email to <b>".e($new)."</b>. OTP: <b>$otp</b> (valid 10 mins).</p>");
            $msg='OTP sent to your current email.';
        }
    }
    if ($a === 'confirm_email_change') {
        $otp = trim($_POST['otp'] ?? '');
        $stmt = db()->prepare('SELECT id FROM otp_codes WHERE email=? AND otp=? AND purpose="change_email" AND used=0 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([$U['email'],$otp]);
        $oid = $stmt->fetchColumn();
        if (!$oid || empty($_SESSION['change_email_to'])) $err='Invalid/expired OTP.';
        else {
            db()->prepare('UPDATE otp_codes SET used=1 WHERE id=?')->execute([$oid]);
            db()->prepare('UPDATE users SET email=? WHERE id=?')->execute([$_SESSION['change_email_to'], $U['id']]);
            unset($_SESSION['change_email_to']);
            $msg = 'Email updated successfully.';
            $U = current_user();
        }
    }
}

layout_shell_start('My Profile','profile');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<div class="grid2">
  <div class="panel">
    <h3>Profile</h3>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update">
      <div style="display:flex;gap:12px;align-items:center;">
        <?php if ($U['photo']): ?><img src="<?= e(upload_url($U['photo'])) ?>" class="avatar big"><?php else: ?><div class="avatar-initials big"><?= e(strtoupper(substr($U['owner_name'],0,1))) ?></div><?php endif; ?>
        <input type="file" name="photo" accept="image/*">
      </div>
      <label>Owner Name</label><input name="owner_name" value="<?= e($U['owner_name']) ?>">
      <label>Username</label><input value="<?= e($U['username']) ?>" disabled>
      <?php if ($U['tower']): ?><label>House</label><input value="<?= e($U['tower']) ?>-<?= e($U['house_number']) ?>" disabled><?php endif; ?>
      <label>Email</label><input value="<?= e($U['email']) ?>" disabled>
      <label>Phone</label><input name="phone" value="<?= e($U['phone']) ?>">
      <button class="btn primary">Save</button>
    </form>
  </div>

  <div class="panel">
    <h3>Change Password</h3>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="password">
      <label>Current</label><input type="password" name="current" required>
      <label>New</label><input type="password" name="new" required>
      <label>Confirm</label><input type="password" name="confirm" required>
      <button class="btn primary">Change Password</button>
    </form>

    <?php if ($U['role']==='resident'): ?>
      <hr>
      <h3>Change Email</h3>
      <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="request_email_change">
        <label>New Email</label><input type="email" name="new_email" required>
        <button class="btn ghost">Send OTP to current email</button>
      </form>
      <?php if (!empty($_SESSION['change_email_to'])): ?>
        <form method="post" class="form" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="confirm_email_change">
          <label>OTP (to change to <?= e($_SESSION['change_email_to']) ?>)</label>
          <input name="otp" required>
          <button class="btn primary">Confirm</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php layout_shell_end(); ?>
