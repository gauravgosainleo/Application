<?php
require_once __DIR__ . '/includes/config.php';
if (!empty($_SESSION['user_id']) || !empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password — <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body class="auth-body">
<div class="auth-card">
  <h1 class="auth-title">Reset Password</h1>
  <p class="auth-sub">Enter your email and we'll send you an OTP</p>
  <div id="msg" class="msg"></div>

  <form id="forgotForm">
    <label>Email</label>
    <div class="row">
      <input name="email" type="email" required>
      <button type="button" class="btn-secondary" id="btnOtp">Send OTP</button>
    </div>

    <label>OTP Code</label>
    <input name="code" maxlength="6" required>

    <label>New Password</label>
    <input name="new_password" type="password" minlength="6" required>

    <label>Confirm New Password</label>
    <input name="confirm_password" type="password" minlength="6" required>

    <button class="btn-primary" type="submit">Reset Password</button>
  </form>
  <div class="auth-links"><a href="login.php">Back to login</a></div>
</div>

<script>
const msg = document.getElementById('msg');
document.getElementById('btnOtp').onclick = async () => {
  const email = document.forms.forgotForm.email.value.trim();
  if (!email) { msg.className='msg err'; msg.textContent='Enter your email'; return; }
  msg.className='msg'; msg.textContent='Sending...';
  const r = await fetch('api/auth.php?action=forgot', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({email})
  });
  const j = await r.json();
  msg.className = j.success ? 'msg ok' : 'msg err';
  msg.textContent = j.message;
};
document.getElementById('forgotForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const body = {
    email: f.email.value.trim(),
    code: f.code.value.trim(),
    new_password: f.new_password.value,
    confirm_password: f.confirm_password.value,
  };
  const r = await fetch('api/auth.php?action=reset', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  const j = await r.json();
  msg.className = j.success ? 'msg ok' : 'msg err';
  msg.textContent = j.message;
  if (j.success) setTimeout(() => window.location.href = 'login.php', 1500);
});
</script>
</body>
</html>
