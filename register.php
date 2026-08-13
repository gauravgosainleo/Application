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
<title>Register — <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body class="auth-body">
<div class="auth-card">
  <h1 class="auth-title">Create your account</h1>
  <p class="auth-sub">Join the AI community</p>
  <div id="msg" class="msg"></div>

  <form id="regForm">
    <label>Name</label>
    <input name="name" required>

    <label>Email</label>
    <div class="row">
      <input name="email" type="email" required>
      <button type="button" class="btn-secondary" id="btnSendOtp">Send OTP</button>
    </div>

    <label>Verify Email with OTP</label>
    <div class="row">
      <input name="otp" placeholder="6-digit code" maxlength="6">
      <button type="button" class="btn-secondary" id="btnVerifyOtp">Verify</button>
    </div>
    <div id="verifyStatus" class="hint"></div>

    <label>Password</label>
    <input name="password" type="password" required minlength="6">

    <label>Confirm Password</label>
    <input name="confirm_password" type="password" required minlength="6">

    <button class="btn-primary" type="submit">Register</button>
  </form>
  <div class="auth-links">
    <a href="login.php">Back to login</a>
  </div>
</div>

<script>
let verifiedEmail = null;
const msg = document.getElementById('msg');
const status = document.getElementById('verifyStatus');

async function api(action, body) {
  const r = await fetch('api/auth.php?action=' + action, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  return r.json();
}

document.getElementById('btnSendOtp').onclick = async () => {
  msg.textContent = ''; status.textContent = '';
  const email = document.forms.regForm.email.value.trim();
  const name  = document.forms.regForm.name.value.trim() || 'User';
  if (!email) { msg.className='msg err'; msg.textContent='Enter your email first'; return; }
  msg.className='msg'; msg.textContent='Sending OTP...';
  const j = await api('send_register_otp', { email, name });
  msg.className = j.success ? 'msg ok' : 'msg err';
  msg.textContent = j.message;
};

document.getElementById('btnVerifyOtp').onclick = async () => {
  const email = document.forms.regForm.email.value.trim();
  const code  = document.forms.regForm.otp.value.trim();
  if (!email || !code) { msg.className='msg err'; msg.textContent='Email and OTP required'; return; }
  const j = await api('verify_otp', { email, code });
  if (j.success) {
    verifiedEmail = email;
    status.textContent = '✓ Email verified';
    status.style.color = 'green';
  } else {
    status.textContent = j.message || 'Invalid OTP';
    status.style.color = 'red';
  }
};

document.getElementById('regForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const body = {
    name: f.name.value.trim(),
    email: f.email.value.trim(),
    password: f.password.value,
    confirm_password: f.confirm_password.value,
  };
  if (body.email !== verifiedEmail) {
    msg.className='msg err'; msg.textContent = 'Please verify your email with OTP first';
    return;
  }
  const j = await api('register', body);
  msg.className = j.success ? 'msg ok' : 'msg err';
  msg.textContent = j.message;
  if (j.success) setTimeout(() => window.location.href = 'login.php', 1200);
});
</script>
</body>
</html>
