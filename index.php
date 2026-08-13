<?php
require_once __DIR__ . '/includes/config.php';
if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
    header('Location: login.php'); exit;
}
$isAdmin = !empty($_SESSION['is_admin']);
$displayName = htmlspecialchars($_SESSION['name'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=1">
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
<div id="app">
  <header class="topbar">
    <div class="brand">AI Platform</div>
    <div class="top-actions">
      <span class="hello">Hi, <?= $displayName ?></span>
      <button class="icon-btn" id="btnProfile" title="Profile">
        <img id="topbarAvatar" class="avatar-sm" src="assets/css/default-avatar.svg" alt="me">
      </button>
      <?php if ($isAdmin): ?>
        <button class="pill danger" id="btnSettings">Settings</button>
      <?php endif; ?>
      <button class="pill outline" id="btnLogout">Logout</button>
    </div>
  </header>

  <main id="main"></main>

  <nav class="bottom-nav">
    <button data-view="news"    class="nav-btn active"><span>🗞️</span> AI News</button>
    <button data-view="forums"  class="nav-btn">       <span>💬</span> Forums</button>
    <button data-view="shop"    class="nav-btn">       <span>🛍️</span> AI Shop</button>
    <button data-view="events"  class="nav-btn">       <span>🎓</span> Trainings</button>
    <button data-view="friends" class="nav-btn">       <span>👥</span> Friends</button>
    <button data-view="messages" class="nav-btn">      <span>✉️</span> Messages</button>
  </nav>
</div>

<div id="modal-root"></div>

<script>
window.APP = {
  isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
  razorpayKey: '<?= RAZORPAY_KEY_ID ?>',
  currentUser: <?= json_encode([
      'id' => $_SESSION['user_id'] ?? 0,
      'name' => $_SESSION['name'] ?? '',
      'email' => $_SESSION['email'] ?? '',
  ]) ?>
};
</script>
<script src="assets/js/app.js?v=1"></script>
</body>
</html>
