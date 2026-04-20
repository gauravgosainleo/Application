<?php
// Layout helpers - header, sidebar, footer
require_once __DIR__ . '/helpers.php';
require_login();

$U    = current_user();
$nav  = [
    'calendar'  => ['Event Calendar',    'calendar'],
    'notices'   => ['Society Notices',   'notice'],
    'complaints'=> ['Complaints',        'complaint'],
    'finance'   => ['Finance & Expenses','finance'],
    'vendors'   => ['Third-party Vendors','vendor'],
    'homemates' => ['My Home Mates',     'homemates'],
    'ads'       => ['Society Ads',       'ad'],
    'polls'     => ['Society Polls',     'poll'],
];
$current = $current ?? 'calendar';

function layout_head($title) {
    global $U;
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head><body class="app-body">
<?php }

function layout_shell_start($title, $currentKey) {
    global $U, $nav;
    layout_head($title);
?>
<div class="app">
  <aside class="sidebar">
    <div class="sb-brand"><div class="logo">UN</div><div><b>Uninav</b><div class="muted small">Society Portal</div></div></div>
    <nav class="sb-nav">
      <?php foreach ($nav as $key => [$label, $icon]): ?>
        <a href="<?= APP_URL ?>/modules/<?= $key ?>.php" class="<?= $currentKey===$key?'active':'' ?>">
          <i class="fa fa-<?= $icon==='calendar'?'calendar-days':($icon==='notice'?'bullhorn':($icon==='complaint'?'circle-exclamation':($icon==='finance'?'indian-rupee-sign':($icon==='vendor'?'people-carry-box':($icon==='homemates'?'house-user':($icon==='ad'?'rectangle-ad':'square-poll-vertical')))))) ?>"></i>
          <span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($U['role']==='admin'): ?>
        <a href="<?= APP_URL ?>/modules/settings.php" class="<?= $currentKey==='settings'?'active':'' ?>">
          <i class="fa fa-gear"></i><span>Settings</span>
        </a>
      <?php endif; ?>
    </nav>
    <div class="sb-footer muted small">v1.0</div>
  </aside>
  <main class="main">
    <header class="topbar">
      <div class="topbar-title"><?= e($title) ?></div>
      <div class="profile" onclick="this.classList.toggle('open')">
        <?php $photo = $U['photo'] ?? null; ?>
        <?php if ($photo): ?>
          <img src="<?= e(upload_url($photo)) ?>" alt="" class="avatar">
        <?php else: ?>
          <div class="avatar-initials"><?= e(strtoupper(substr($U['owner_name'] ?: $U['username'],0,1))) ?></div>
        <?php endif; ?>
        <div class="who">
          <b><?= e($U['owner_name'] ?: $U['username']) ?></b>
          <div class="muted small"><?= e(ucfirst($U['role'])) ?><?= $U['tower'] ? ' · '.e($U['tower']).'-'.e($U['house_number']) : '' ?></div>
        </div>
        <i class="fa fa-chevron-down"></i>
        <div class="dropdown">
          <?php if ($U['role']!=='guest'): ?>
          <a href="<?= APP_URL ?>/modules/profile.php"><i class="fa fa-user"></i> My Profile</a>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/auth/logout.php"><i class="fa fa-sign-out"></i> Logout</a>
        </div>
      </div>
    </header>
    <section class="content">
<?php }

function layout_shell_end() {
?>
    </section>
  </main>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body></html>
<?php }
