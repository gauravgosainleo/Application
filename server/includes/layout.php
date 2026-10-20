<?php
// ============================================================================
// Layout helpers - app shell for every logged-in page
//
//  Desktop : white icon rail on the left that expands on hover, page topbar
//  Mobile  : five fixed bottom tabs (Home, Notices, Complaints, Community,
//            Profile) and a "More" sheet that holds every other module,
//            so nothing is more than two taps away.
//  Roles   : residents/family get the resident shell; security gets a dark
//            three-item shell (Scan, Attendance, Gym); admin gets the dark
//            grouped console rail (Overview, Content, People, Money, Ops).
// ============================================================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app_update_prompt.php';
require_login();

// Every module page includes this file before handling its form, and content
// goes public through those forms (notices, events, albums, photo uploads,
// polls, ads, AOA, vendors, SPOCs, finance). After the response has been sent,
// push whatever became public to the phones. Does nothing until push is set up.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') uv_push_after_response('post');

$U    = current_user();
ensure_auth_schema();
ensure_vehicles_schema();
ensure_gym_workflow_schema();
gym_rollover();
$houseLocked   = resident_needs_house_setup($U);
$currentScript = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
if ($houseLocked && $currentScript !== 'profile.php') {
    flash('msg', 'Please select your tower and house number from Profile. Other options will unlock after admin approval.');
    redirect('modules/profile.php');
}
$ROLE = $U['role'] ?? 'resident';

// Kept for older modules that read $nav: flat list of resident modules.
$nav = [
    'calendar'         => ['Event Calendar',     'calendar-days'],
    'notices'          => ['Society Notices',    'bullhorn'],
    'gallery'          => ['Photo Gallery',      'images'],
    'aoa_changes'      => ['AOA Changes & Enhancements', 'screwdriver-wrench'],
    'complaints'       => ['Complaints',         'circle-exclamation'],
    'finance'          => ['Finance & Expenses', 'indian-rupee-sign'],
    'vendors'          => ['Third-party Vendors','people-carry-box'],
    'spocs'            => ['Society SPOCs',      'address-book'],
    'ads'              => ['Society Ads',        'rectangle-ad'],
    'polls'            => ['Society Polls',      'square-poll-vertical'],
    'family_members'   => ['Family Members',     'users'],
    'parking_vehicles' => ['Parking & Vehicles', 'car'],
];

/* ---------------------------------------------------------------------
 * Navigation model. Every entry is [key, label, icon, href].
 * ------------------------------------------------------------------- */
function uv_link(string $path): string { return APP_URL . '/' . ltrim($path, '/'); }
function uv_tone(string $key): string {
    if (strpos($key, 'complaint') !== false || $key === 'ads' || $key === 'settings_ads') return 'rose';
    if (strpos($key, 'gym') !== false || $key === 'scan_vehicle') return 'lime';
    if (strpos($key, 'poll') !== false || strpos($key, 'password') !== false || $key === 'gallery') return 'violet';
    if (strpos($key, 'notice') !== false || strpos($key, 'finance') !== false) return 'amber';
    if (strpos($key, 'calendar') !== false || strpos($key, 'event') !== false || strpos($key, 'parking') !== false || strpos($key, 'spoc') !== false) return 'sky';
    return 'teal';
}

if ($ROLE === 'security') {
    // Guard shell: three destinations, used one-handed at the gate.
    $RAIL = [
        ['group', 'On duty'],
        ['scan_vehicle', 'Scan Vehicle', 'camera',     uv_link('modules/scan_vehicle.php')],
        ['attendance',   'Attendance',   'user-check', uv_link('modules/attendance.php')],
        ['gym_signin',   'Gym Signin',   'dumbbell',   uv_link('modules/gym_signin.php')],
    ];
    $TABS = [
        ['scan_vehicle', 'Scan',       'camera',     uv_link('modules/scan_vehicle.php')],
        ['attendance',   'Attendance', 'user-check', uv_link('modules/attendance.php')],
        ['gym_signin',   'Gym',        'dumbbell',   uv_link('modules/gym_signin.php')],
    ];
    $MORE = [];
    if (!in_array($currentScript, ['scan_vehicle.php', 'attendance.php', 'gym_signin.php', 'gym_manager.php'], true)) {
        redirect('modules/scan_vehicle.php');
    }
} elseif ($ROLE === 'gym_manager') {
    $RAIL = [
        ['group', 'Gym desk'],
        ['gym_manager', 'Today’s check-ins', 'dumbbell', uv_link('modules/gym_manager.php')],
        ['gym_history', 'Previous days', 'clock-rotate-left', uv_link('modules/gym_manager.php?date=' . date('Y-m-d', strtotime('-1 day')))],
        ['gym_signin', 'Check-in register', 'clipboard-list', uv_link('modules/gym_signin.php')],
        ['group', 'Account'],
        ['profile', 'My profile', 'user', uv_link('modules/profile.php')],
    ];
    $TABS = array_values(array_filter($RAIL, static function ($item) { return $item[0] !== 'group'; }));
    $MORE = [];
    if (!in_array($currentScript, ['gym_manager.php','gym_signin.php','profile.php'], true)) redirect('modules/gym_manager.php');
} elseif ($ROLE === 'admin') {
    // Admin console rail: grouped the way the redesign lays it out.
    $RAIL = [
        ['group', 'Overview'],
        ['console',     'Console',          'gauge-high',         uv_link('modules/console.php')],
        ['complaints',  'Complaints',       'circle-exclamation', uv_link('modules/complaints.php')],
        // settings.php#<tab> entries use "settings_<tab>" keys so they never
        // collide with the public module pages (gallery.php is 'gallery', the
        // album manager is 'settings_gallery'); the script at the bottom of
        // the page lights the right one from the URL hash.
        ['group', 'Content'],
        ['settings_events',  'Events',         'calendar-days',      uv_link('modules/settings.php#events')],
        ['settings_notices', 'Notices',        'bullhorn',           uv_link('modules/settings.php#notices')],
        ['settings_gallery', 'Gallery albums', 'images',             uv_link('modules/settings.php#gallery')],
        ['settings_ads',     'Ads',            'rectangle-ad',       uv_link('modules/settings.php#ads')],
        ['polls',            'Polls',          'square-poll-vertical', uv_link('modules/polls.php')],
        ['aoa_changes',      'AOA projects',   'screwdriver-wrench', uv_link('modules/aoa_changes.php')],
        ['group', 'People'],
        ['settings_users',   'Residents',      'users',              uv_link('modules/settings.php#users')],
        ['settings_passwords','Passwords',     'key',                uv_link('modules/settings.php#passwords')],
        ['settings_house-requests','House requests','house-chimney', uv_link('modules/settings.php#house-requests')],
        ['settings_spocs',   'SPOCs',          'address-book',       uv_link('modules/settings.php#spocs')],
        ['group', 'Money'],
        ['settings_finance', 'Finance entries','indian-rupee-sign',  uv_link('modules/settings.php#finance')],
        ['group', 'Operations'],
        ['attendance',       'Attendance',     'user-check',         uv_link('modules/attendance.php')],
        ['gym_signin',       'Gym Signin',     'dumbbell',           uv_link('modules/gym_signin.php')],
        ['gym_manager',      'Gym desk',       'clipboard-check',    uv_link('modules/gym_manager.php')],
        ['settings_vendors', 'Vendors',        'people-carry-box',   uv_link('modules/settings.php#vendors')],
        ['parking_vehicles', 'Parking & passes','car',               uv_link('modules/parking_vehicles.php')],
        ['mobile_app',       'Mobile app',     'mobile-screen-button', uv_link('modules/mobile_app.php')],
        ['group', 'Portal'],
        ['home',             'Home feed',      'house',              uv_link('modules/home.php')],
        ['calendar',         'Calendar',       'calendar-days',      uv_link('modules/calendar.php')],
        ['notices',          'Society notices','bullhorn',           uv_link('modules/notices.php')],
        ['gallery',          'Photo gallery',  'images',             uv_link('modules/gallery.php')],
        ['ads',              'Society ads',    'rectangle-ad',       uv_link('modules/ads.php')],
        ['vendors',          'Vendor directory','people-carry-box',  uv_link('modules/vendors.php')],
        ['spocs',            'SPOC directory', 'address-book',       uv_link('modules/spocs.php')],
        ['finance',          'Finance report', 'indian-rupee-sign',  uv_link('modules/finance.php')],
        ['profile',          'My profile',     'user',               uv_link('modules/profile.php')],
    ];
    $TABS = [
        ['console',            'Console',    'gauge-high',         uv_link('modules/console.php')],
        ['complaints',         'Complaints', 'circle-exclamation', uv_link('modules/complaints.php')],
        ['settings_notices',   'Content',    'bullhorn',           uv_link('modules/settings.php#notices')],
        ['settings_users',     'People',     'users',              uv_link('modules/settings.php#users')],
        ['settings_passwords', 'Setup',      'gear',               uv_link('modules/settings.php#passwords')],
    ];
    $MORE = $RAIL;
} else {
    // Resident / family member shell (the redesign's rail order).
    $RAIL = [
        ['group', 'Everyday'],
        ['home',             'Home',        'house',                uv_link('modules/home.php')],
        ['notices',          'Notices',     'bullhorn',             uv_link('modules/notices.php')],
        ['calendar',         'Calendar',    'calendar-days',        uv_link('modules/calendar.php')],
        ['complaints',       'Complaints',  'circle-exclamation',   uv_link('modules/complaints.php')],
        ['group', 'Community'],
        ['community',        'Community',   'users',                uv_link('modules/community.php')],
        ['gallery',          'Gallery',     'images',               uv_link('modules/gallery.php')],
        ['ads',              'Society Ads', 'rectangle-ad',         uv_link('modules/ads.php')],
        ['polls',            'Polls',       'square-poll-vertical', uv_link('modules/polls.php')],
        ['vendors',          'Vendors',     'people-carry-box',     uv_link('modules/vendors.php')],
        ['spocs',            'SPOCs',       'address-book',         uv_link('modules/spocs.php')],
        ['finance',          'Finance',     'indian-rupee-sign',    uv_link('modules/finance.php')],
        ['parking_vehicles', 'Parking',     'car',                  uv_link('modules/parking_vehicles.php')],
        ['aoa_changes',      'AOA Changes', 'screwdriver-wrench',   uv_link('modules/aoa_changes.php')],
        ['group', 'My house'],
        ['family_members',   'Family',      'users',                uv_link('modules/family_members.php')],
        ['gym_pass',         'Gym Pass',    'dumbbell',             uv_link('modules/gym_pass.php')],
        ['profile',          'Profile',     'user',                 uv_link('modules/profile.php')],
    ];
    $TABS = [
        ['home',       'Home',       'house',                uv_link('modules/home.php')],
        ['notices',    'Notices',    'bullhorn',             uv_link('modules/notices.php')],
        ['complaints', 'Complaints', 'circle-exclamation',   uv_link('modules/complaints.php')],
        ['community',  'Community',  'users',                uv_link('modules/community.php')],
        ['profile',    'Profile',    'user',                 uv_link('modules/profile.php')],
    ];
    // "Everything else" sheet: every module that is not one of the five tabs.
    $MORE = [
        ['group', 'Everything else'],
        ['calendar',         'Calendar',    'calendar-days',        uv_link('modules/calendar.php')],
        ['polls',            'Polls',       'square-poll-vertical', uv_link('modules/polls.php')],
        ['aoa_changes',      'AOA Changes', 'screwdriver-wrench',   uv_link('modules/aoa_changes.php')],
        ['gallery',          'Gallery',     'images',               uv_link('modules/gallery.php')],
        ['ads',              'Society Ads', 'rectangle-ad',         uv_link('modules/ads.php')],
        ['vendors',          'Vendors',     'people-carry-box',     uv_link('modules/vendors.php')],
        ['spocs',            'SPOCs',       'address-book',         uv_link('modules/spocs.php')],
        ['finance',          'Finance',     'indian-rupee-sign',    uv_link('modules/finance.php')],
        ['parking_vehicles', 'Parking',     'car',                  uv_link('modules/parking_vehicles.php')],
        ['family_members',   'Family',      'users',                uv_link('modules/family_members.php')],
        ['gym_pass',         'Gym Pass',    'dumbbell',             uv_link('modules/gym_pass.php')],
    ];
}

// Which bottom tab lights up for a given page key (per role, since each
// role has its own five tabs).
if ($ROLE === 'security') {
    $TAB_OF = ['scan_vehicle' => 'scan_vehicle', 'attendance' => 'attendance', 'gym_signin' => 'gym_signin', 'gym_manager' => 'gym_signin', 'gym_history' => 'gym_signin'];
} elseif ($ROLE === 'gym_manager') {
    $TAB_OF = ['gym_manager' => 'gym_manager', 'gym_history' => 'gym_history', 'gym_signin' => 'gym_signin', 'profile' => 'profile'];
} elseif ($ROLE === 'admin') {
    $TAB_OF = [
        'console' => 'console', 'home' => 'console',
        'complaints' => 'complaints',
        // Content
        'settings_events' => 'settings_notices', 'settings_notices' => 'settings_notices',
        'settings_gallery' => 'settings_notices', 'settings_ads' => 'settings_notices',
        'polls' => 'settings_notices', 'aoa_changes' => 'settings_notices', 'calendar' => 'settings_notices',
        'notices' => 'settings_notices', 'gallery' => 'settings_notices', 'ads' => 'settings_notices',
        // People
        'settings_users' => 'settings_users', 'settings_house-requests' => 'settings_users',
        'settings_spocs' => 'settings_users', 'spocs' => 'settings_users', 'profile' => 'settings_users',
        // Setup
        'settings_passwords' => 'settings_passwords', 'settings_finance' => 'settings_passwords',
        'settings_vendors' => 'settings_passwords', 'finance' => 'settings_passwords', 'vendors' => 'settings_passwords',
        'attendance' => 'settings_passwords', 'gym_signin' => 'settings_passwords', 'parking_vehicles' => 'settings_passwords',
        'gym_manager' => 'settings_passwords', 'gym_history' => 'settings_passwords', 'mobile_app' => 'settings_passwords',
        // settings.php with no hash opens the Events pane
        'settings' => 'settings_notices',
    ];
} else {
    $TAB_OF = [
        'home' => 'home',
        'notices' => 'notices', 'calendar' => 'notices', 'aoa_changes' => 'notices', 'polls' => 'notices',
        'complaints' => 'complaints',
        'community' => 'community', 'gallery' => 'community', 'ads' => 'community', 'vendors' => 'community', 'spocs' => 'community',
        'profile' => 'profile', 'family_members' => 'profile', 'parking_vehicles' => 'profile', 'finance' => 'profile', 'gym_pass' => 'profile',
    ];
}

function layout_head($title) {
    global $U;
    $root = dirname(__DIR__);
    $v = function ($rel) use ($root) { return @filemtime($root . '/' . $rel) ?: 1; };
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B4A46">
<title><?= e($title) ?> - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $v('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uni-vishwas-theme.css?v=<?= $v('assets/css/uni-vishwas-theme.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uni-vishwas-shell.css?v=<?= $v('assets/css/uni-vishwas-shell.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/redesign.css?v=<?= $v('assets/css/redesign.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<script src="<?= APP_URL ?>/assets/vendor/chartjs/chart.umd.js" defer></script>
</head>
<?php }

/**
 * @param string      $title       Page title shown in the topbar
 * @param string      $currentKey  Navigation key of this page (see $RAIL / $TAB_OF)
 * @param string|null $subtitle    Optional small line under the title
 * @param string|null $actionsHtml Optional extra topbar controls (already-escaped HTML)
 */
function layout_shell_start($title, $currentKey, $subtitle = null, $actionsHtml = null) {
    global $U, $houseLocked, $ROLE, $RAIL, $TABS, $MORE, $TAB_OF;
    layout_head($title);
    $locked   = $houseLocked && $ROLE === 'resident';
    $activeTab = $TAB_OF[$currentKey] ?? $currentKey;
    $isActive = function ($key) use ($currentKey) { return $key === $currentKey; };
    $name = $U['owner_name'] ?: $U['username'];
    $flatLabel = user_flat_label($U);
    $roleLabel = !empty($U['parent_user_id']) ? 'Family Member' : ($ROLE === 'gym_manager' ? 'Gym Manager' : ucfirst($ROLE));
?>
<body class="app-body role-<?= e($ROLE) ?> page-<?= e($currentKey) ?><?= $locked ? ' house-locked' : '' ?>">
<div class="app">
  <aside class="sidebar" aria-label="Main navigation">
    <div class="sb-brand">
      <div class="logo">UV</div>
      <div><b>Uni-Vishwas</b><div class="muted small"><?= e(default_society_name()) ?></div></div>
    </div>
    <nav class="sb-nav">
      <?php foreach ($RAIL as $item): ?>
        <?php if ($item[0] === 'group'): ?>
          <div class="sb-group"><?= e($item[1]) ?></div>
        <?php else: [$key, $label, $icon, $href] = $item;
              $dis = $locked && $key !== 'profile'; ?>
          <a href="<?= $dis ? '#' : e($href) ?>" class="<?= $isActive($key) ? 'active' : '' ?><?= $dis ? ' disabled' : '' ?>"
             data-key="<?= e($key) ?>" data-tone="<?= e(uv_tone($key)) ?>" title="<?= e($label) ?>"<?= $dis ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
            <i class="fa fa-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sb-nav sb-bottom" style="flex:0 0 auto;">
      <div class="sb-user"><div class="uv-avatar"><?= e(mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8')) ?></div><div><b><?= e($name) ?></b><small><?= e($roleLabel) ?><?= $flatLabel ? ' · ' . e($flatLabel) : '' ?></small></div><a href="<?= e(uv_link('auth/logout.php?csrf=' . csrf_token())) ?>" aria-label="Logout" title="Logout"><i class="fa fa-right-from-bracket"></i></a></div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="topbar-title"><?= e($title) ?></div>
        <?php if ($subtitle): ?><div class="topbar-sub"><?= e($subtitle) ?></div><?php endif; ?>
      </div>
      <div class="topbar-right">
        <?= $actionsHtml ?? '' ?>
        <?php if ($ROLE === 'resident' && !$locked): ?>
          <a class="gym-quick <?= $currentKey === 'gym_pass' ? 'active' : '' ?>" href="<?= uv_link('modules/gym_pass.php') ?>" title="Gym pass - scan the QR at the gym door">
            <i class="fa fa-dumbbell"></i><span>Gym pass</span>
          </a>
        <?php endif; ?>
        <?php if ($MORE): ?>
          <button type="button" class="uv-more-btn" id="uvMoreBtn" aria-label="More options" aria-controls="uvSheet" aria-expanded="false"><i class="fa fa-grip"></i></button>
        <?php endif; ?>
        <div class="profile" onclick="this.classList.toggle('open')">
          <?php $photo = $U['photo'] ?? null; ?>
          <?php if ($photo): ?>
            <img src="<?= e(upload_url($photo)) ?>" alt="" class="avatar">
          <?php else: ?>
            <div class="avatar-initials"><?= e(strtoupper(substr($name, 0, 1))) ?></div>
          <?php endif; ?>
          <div class="who">
            <b><?= e($name) ?></b>
            <div class="muted small"><?= e($roleLabel) ?><?= $flatLabel ? ' · ' . e($flatLabel) : '' ?></div>
          </div>
          <i class="fa fa-chevron-down"></i>
          <div class="dropdown">
            <?php if ($ROLE !== 'security'): ?>
              <a href="<?= uv_link('modules/profile.php') ?>"><i class="fa fa-user"></i> My Profile</a>
            <?php endif; ?>
            <a href="<?= e(uv_link('auth/logout.php?csrf=' . csrf_token())) ?>"><i class="fa fa-sign-out"></i> Logout</a>
          </div>
        </div>
      </div>
    </header>

    <section class="content">
      <?php if ($locked): ?>
        <div class="alert warn house-lock-alert">
          <b>House setup required.</b> Your tower and house number are not configured yet. Select them from Profile and wait for admin approval to unlock the portal options.
        </div>
      <?php endif; ?>
<?php }

function layout_shell_end() {
    global $U, $houseLocked, $ROLE, $TABS, $MORE, $TAB_OF;
    $locked = $houseLocked && $ROLE === 'resident';
?>
    </section>
  </main>
</div>

<!-- Mobile: five fixed tabs -->
<nav class="uv-tabbar" aria-label="Primary">
  <?php foreach ($TABS as [$key, $label, $icon, $href]):
        $dis = $locked && $key !== 'profile'; ?>
    <a href="<?= $dis ? '#' : e($href) ?>" class="uv-tab<?= $dis ? ' disabled' : '' ?>" data-tab="<?= e($key) ?>" data-tone="<?= e(uv_tone($key)) ?>">
      <i class="fa fa-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($MORE): ?>
<!-- Mobile: the More sheet -->
<div class="uv-sheet-backdrop" id="uvSheetBackdrop"></div>
<div class="uv-sheet" id="uvSheet" role="dialog" aria-modal="true" aria-label="More options">
  <div class="uv-sheet-handle"></div>
  <?php $first = true; foreach ($MORE as $item): ?>
    <?php if ($item[0] === 'group'): ?>
      <?php if (!$first): ?></div><?php endif; ?>
      <?php if ($first): ?><div class="uv-sheet-title"><?= e($item[1]) ?></div><?php else: ?><div class="uv-sheet-group"><?= e($item[1]) ?></div><?php endif; ?>
      <div class="uv-sheet-grid">
      <?php $first = false; ?>
    <?php else: [$key, $label, $icon, $href] = $item;
          $dis = $locked && $key !== 'profile'; ?>
      <?php if ($first): ?><div class="uv-sheet-title">Everything else</div><div class="uv-sheet-grid"><?php $first = false; endif; ?>
      <a href="<?= $dis ? '#' : e($href) ?>" class="uv-more-item<?= $dis ? ' disabled' : '' ?>" data-key="<?= e($key) ?>" data-tone="<?= e(uv_tone($key)) ?>">
        <i class="fa fa-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$first): ?></div><?php endif; ?>
  <div class="uv-sheet-foot">
    <div>Uni-Vishwas · <?= e(default_society_name()) ?></div>
    <a href="<?= e(uv_link('auth/logout.php?csrf=' . csrf_token())) ?>"><i class="fa fa-right-from-bracket" style="margin-right:6px;"></i>Logout</a>
  </div>
</div>
<?php endif; ?>

<!-- Full-screen photo viewer: notice / AOA / gallery / attendance photos open here. -->
<div class="uv-lightbox" id="uvLightbox" role="dialog" aria-label="Photo viewer">
  <button type="button" class="uv-lightbox-close" aria-label="Close">&times;</button>
  <img src="" alt="">
</div>
<script>
(function () {
  // active bottom tab / More item / (for settings.php) rail entry
  var m = document.body.className.match(/page-([a-z_\-]+)/);
  var page = m ? m[1] : '';
  var tabOf = <?= json_encode($TAB_OF) ?>;
  function pageKey() {
    // settings.php is one page with many panes: the hash says which one.
    if (page === 'settings') { var h = (location.hash || '').slice(1); return 'settings_' + (h || 'events'); }
    return page;
  }
  function markActive() {
    var key = pageKey();
    var t = tabOf[key] || tabOf[page] || key;
    var all = document.querySelectorAll('.uv-tab.active, .uv-more-item.active' + (page === 'settings' ? ', .sb-nav a.active' : ''));
    for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
    var el = document.querySelector('.uv-tab[data-tab="' + t + '"]') || document.querySelector('.uv-tab[data-tab="' + page + '"]');
    if (el) el.classList.add('active');
    var mi = document.querySelector('.uv-more-item[data-key="' + key + '"]');
    if (mi) mi.classList.add('active');
    if (page === 'settings') { var ra = document.querySelector('.sb-nav a[data-key="' + key + '"]'); if (ra) ra.classList.add('active'); }
  }
  if (page) markActive();
  // More sheet
  var btn = document.getElementById('uvMoreBtn'), bd = document.getElementById('uvSheetBackdrop');
  function setSheet(open) {
    document.body.classList.toggle('sheet-open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (btn) btn.addEventListener('click', function () { setSheet(!document.body.classList.contains('sheet-open')); });
  if (bd) bd.addEventListener('click', function () { setSheet(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSheet(false); });
  // tapping an item closes the sheet even when it only changes the hash (settings.php#tab)
  document.addEventListener('click', function (e) { if (e.target.closest('.uv-more-item')) setSheet(false); });
  window.addEventListener('hashchange', function () { setSheet(false); if (page) markActive(); });
  // lightbox
  var box = document.getElementById('uvLightbox');
  var pic = box.querySelector('img');
  function openLb(src, alt) { pic.src = src; pic.alt = alt || ''; box.classList.add('open'); document.body.style.overflow = 'hidden'; }
  function closeLb() { box.classList.remove('open'); pic.src = ''; document.body.style.overflow = ''; }
  document.addEventListener('click', function (e) {
    var img = e.target.closest('.notice-photo img, img.att-photo-thumb, .gallery-item img, .uv-thread-item .txt img');
    if (img) { e.preventDefault(); openLb(img.src, img.alt); return; }
    if (e.target.closest('#uvLightbox')) closeLb();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLb(); });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script src="<?= APP_URL ?>/assets/js/redesign.js?v=<?= (int)@filemtime(APP_ROOT . '/assets/js/redesign.js') ?>" defer></script>
<?= uv_update_prompt_html() /* old app installs only: "update from Google Play" */ ?>
</body></html>
<?php }
