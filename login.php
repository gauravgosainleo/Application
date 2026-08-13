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
<title>Login — <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body class="auth-body">
<div class="auth-card">
  <h1 class="auth-title">AI Platform</h1>
  <p class="auth-sub">Sign in to continue</p>
  <div id="msg" class="msg"></div>
  <form id="loginForm">
    <label>Email</label>
    <input type="text" name="email" required autocomplete="username">
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button class="btn-primary" type="submit">Login</button>
  </form>
  <div class="auth-links">
    <a href="forgot.php">Forgot password?</a>
    <span>•</span>
    <a href="register.php">Create account</a>
  </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('msg');
  msg.textContent = '';
  const fd = new FormData(e.target);
  const body = { email: fd.get('email'), password: fd.get('password') };
  try {
    const r = await fetch('api/auth.php?action=login', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body)
    });
    const j = await r.json();
    if (j.success) { window.location.href = 'index.php'; }
    else { msg.textContent = j.message || 'Login failed'; msg.className = 'msg err'; }
  } catch (err) {
    msg.textContent = 'Network error: ' + err.message; msg.className = 'msg err';
  }
});
</script>
</body>
</html>
