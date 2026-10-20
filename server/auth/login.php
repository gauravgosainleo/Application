<?php
// ============================================================================
// Login (OTP removed)
// - The screen shows the society name.
// - The resident selects their House Number and enters the house password.
// - Every house has ONE primary password (created randomly, admin can reset and
//   change it in Settings > Password Management).
// - Family members login with the SAME house number and their OWN password
//   (added by the primary resident in the Family Members option, max 4).
// - Admin and Security incharge keep their username + password logins below.
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/app_update_prompt.php';
ensure_house_password_schema();
ensure_gym_workflow_schema();
if (is_logged_in()) redirect('dashboard.php');

// Make sure every house already has an account + password before showing the list.
ensure_house_accounts_and_passwords();

$err = '';
$msg = flash('msg');
$selFlat = '';
$activeSeg = 'resident';

function set_login_session(array $u): void {
    uv_login_succeeded();
    uv_auth_login($u);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $err = 'Invalid session, please try again.';
    } elseif (!uv_login_allowed()) {
        http_response_code(429);
        header('Retry-After: 900');
        $err = 'Too many sign-in attempts. Please try again after 15 minutes.';
    } else {
        $action = $_POST['action'] ?? 'house_login';

        // ------------------------------------------------------------
        // Resident / Family member login: house number + password
        // ------------------------------------------------------------
        if ($action === 'house_login') {
            $selFlat  = trim($_POST['flat'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            [$tower, $house] = array_pad(explode('|', $selFlat, 2), 2, '');
            $tower = normalize_tower($tower);
            $house = normalize_house_number($house);

            if ($tower === '' || $house === '') {
                $err = 'Please select your house number.';
            } elseif ($password === '') {
                $err = 'Please enter your password.';
            } else {
                $owner = find_primary_resident_user_by_house($tower, $house);
                if (!$owner) {
                    $err = 'This house number was not found. Please contact the society admin.';
                } else {
                    // The owner and each family member of this house have their own
                    // password - whichever one matches decides who is logging in.
                    $candidates = array_merge([$owner], family_members_of((int)$owner['id']));
                    $match = null;
                    foreach ($candidates as $c) {
                        if (!empty($c['password_hash']) && password_verify($password, $c['password_hash'])) {
                            $match = $c;
                            break;
                        }
                    }
                    if ($match) {
                        set_login_session($match);
                        redirect('dashboard.php');
                    } else {
                        $err = 'Incorrect password for house ' . resident_flat_label($tower, $house)
                             . '. If you forgot it, please contact the society admin.';
                    }
                }
            }
        }

        // ------------------------------------------------------------
        // Security incharge login (username + password)
        // ------------------------------------------------------------
        if ($action === 'security_login') {
            $activeSeg = 'security';
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['security_password'] ?? '';
            ensure_vehicles_schema();
            $stmt = db()->prepare("SELECT * FROM users WHERE username=? AND role='security' LIMIT 1");
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if ($u && !empty($u['password_hash']) && password_verify($password, $u['password_hash'])) {
                set_login_session($u);
                redirect('modules/scan_vehicle.php');
            } else {
                $err = 'Invalid security credentials.';
            }
        }

        // ------------------------------------------------------------
        // Gym manager login (username + password)
        if ($action === 'gym_manager_login') {
            $activeSeg = 'gym_manager';
            $stmt = db()->prepare("SELECT * FROM users WHERE username=? AND role='gym_manager' LIMIT 1");
            $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
            $u = $stmt->fetch();
            if ($u && !empty($u['password_hash']) && password_verify((string)($_POST['gym_manager_password'] ?? ''), $u['password_hash'])) {
                set_login_session($u);
                redirect('modules/gym_manager.php');
            } else {
                $err = 'Invalid gym manager credentials.';
            }
        }

        // Admin login (username + password)
        // ------------------------------------------------------------
        if ($action === 'admin_login') {
            $activeSeg = 'admin';
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $stmt = db()->prepare("SELECT * FROM users WHERE username=? AND role='admin' LIMIT 1");
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if ($u && !empty($u['password_hash']) && password_verify($password, $u['password_hash'])) {
                set_login_session($u);
                redirect('dashboard.php');
            } else {
                $err = 'Invalid admin credentials.';
            }
        }
    }
}

// Flat list for the type-ahead: value is "tower|house", label is e.g. "E-1208".
$flatOptions = [];
$selFlatLabel = '';
foreach (all_flats_for_login() as $f) {
    $val = $f['tower'] . '|' . $f['house_number'];
    $label = resident_flat_label($f['tower'], $f['house_number']);
    $flatOptions[] = ['v' => $val, 'l' => $label];
    if ($selFlat === $val) $selFlatLabel = $label;
}

// Towers for the 3D stack on the login art panel (real data from the roster).
$towers = [];
try {
    $towers = db()->query("SELECT tower, COUNT(*) c FROM resident_details GROUP BY tower ORDER BY tower")->fetchAll();
} catch (Throwable $e) { $towers = []; }
$maxFlats = 1;
foreach ($towers as $t) $maxFlats = max($maxFlats, (int)$t['c']);
$assetV = function ($rel) { return @filemtime(dirname(__DIR__) . '/' . $rel) ?: 1; };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B4A46">
<title>Login - <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $assetV('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uni-vishwas-theme.css?v=<?= $assetV('assets/css/uni-vishwas-theme.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uni-vishwas-shell.css?v=<?= $assetV('assets/css/uni-vishwas-shell.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/redesign.css?v=<?= $assetV('assets/css/redesign.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<style>
.flat-picker { position:relative; }
.flat-suggestions {
  position:absolute; left:0; right:0; top:calc(100% - 16px); z-index:30;
  background:#fff; border:1px solid #CFDEDA; border-top:none;
  border-radius:0 0 10px 10px; box-shadow:0 8px 20px rgba(0,0,0,.12);
  max-height:240px; overflow-y:auto; display:none;
}
.flat-suggestions.open { display:block; }
.flat-suggestions .sug { padding:11px 14px; cursor:pointer; font-size:15px; border-bottom:1px solid #f1f5f9; }
.flat-suggestions .sug:last-child { border-bottom:none; }
.flat-suggestions .sug:hover, .flat-suggestions .sug.active { background:#E6F2EF; }
.flat-suggestions .sug-none { padding:11px 14px; color:#6b7280; font-size:14px; }
</style>
</head>
<body class="auth-body uv-login">
<div class="uv-login-wrap">

  <div class="uv-login-art">
    <span class="blob1"></span><span class="blob2"></span>
    <div class="inner">
      <div class="uv-login-brand">
        <div class="logo">UV</div>
        <b>Uni-Vishwas</b>
      </div>
      <h1>Welcome back to<br><?= e(default_society_name()) ?></h1>
      <p>Type your house number, then enter your password. Your password was issued by the society office.</p>
      <?php if ($towers): ?>
      <div class="uv-towers" aria-hidden="true">
        <?php foreach ($towers as $i => $t):
              $h = 80 + (int)round(60 * ((int)$t['c'] / $maxFlats)); ?>
          <div class="uv-tower" style="animation-delay:<?= $i * 0.6 ?>s">
            <div class="b" style="height:<?= $h ?>px"><span><?= e(strtoupper($t['tower'])) ?></span></div>
            <div class="base"></div>
            <div class="n"><?= (int)$t['c'] ?> flats</div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="uv-login-form">
    <div class="inner">
      <h2>Welcome home</h2>
      <p class="lead">Your society, all in one place. Choose your login.</p>

      <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

      <div class="uv-seg" role="tablist">
        <button type="button" data-seg="resident" class="<?= $activeSeg==='resident'?'on':'' ?>">Resident</button>
        <button type="button" data-seg="gym_manager" class="<?= $activeSeg==='gym_manager'?'on':'' ?>">Gym manager</button>
        <button type="button" data-seg="security" class="<?= $activeSeg==='security'?'on':'' ?>">Security</button>
        <button type="button" data-seg="admin"    class="<?= $activeSeg==='admin'?'on':'' ?>">Admin</button>
      </div>

      <form method="post" class="form <?= $activeSeg==='resident'?'on':'' ?>" data-form="resident">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="house_login">
        <label>House number</label>
        <div class="flat-picker">
          <input type="text" id="flatSearch" placeholder="e.g. E 1208"
                 autocomplete="off" autocapitalize="characters" spellcheck="false"
                 value="<?= e($selFlatLabel) ?>" required>
          <input type="hidden" name="flat" id="flatValue" value="<?= e($selFlat) ?>">
          <div class="flat-suggestions" id="flatSuggestions"></div>
        </div>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <button class="btn primary" type="submit">Sign in</button>
        <div class="uv-login-note">No password yet? Contact the society office.<br>Family members use the same house number.</div>
      </form>

      <form method="post" class="form <?= $activeSeg==='gym_manager'?'on':'' ?>" data-form="gym_manager">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="gym_manager_login">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" placeholder="GymManager" required>
        <label>Password</label>
        <input type="password" name="gym_manager_password" autocomplete="current-password" required>
        <button class="btn primary" type="submit">Sign in as gym manager <i class="fa fa-arrow-right"></i></button>
        <div class="uv-login-note">View daily opt-ins, confirm check-ins and review previous days.</div>
      </form>

      <form method="post" class="form <?= $activeSeg==='security'?'on':'' ?>" data-form="security">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="security_login">
        <label>Name</label>
        <input type="text" name="username" autocomplete="username" placeholder="Security" required>
        <label>Password</label>
        <input type="password" name="security_password" autocomplete="current-password" required>
        <button class="btn primary" type="submit">Sign in as security</button>
        <div class="uv-login-note">Scan Vehicle, Attendance and Gym Signin only.</div>
      </form>

      <form method="post" class="form <?= $activeSeg==='admin'?'on':'' ?>" data-form="admin">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="admin_login">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required>
        <label>Password</label>
        <input type="password" name="admin_password" autocomplete="current-password" required>
        <button class="btn primary" type="submit">Sign in as admin</button>
      </form>
    </div>
  </div>

</div>
<script src="<?= APP_URL ?>/assets/js/redesign.js?v=<?= $assetV('assets/js/redesign.js') ?>" defer></script>

<script>
(function () {
  // Segmented control: Resident / Security / Admin
  var segs = document.querySelectorAll('.uv-seg button');
  segs.forEach(function (b) {
    b.addEventListener('click', function () {
      segs.forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('form[data-form]').forEach(function (f) { f.classList.toggle('on', f.dataset.form === b.dataset.seg); });
    });
  });
})();
(function () {
  var FLATS = <?= json_encode($flatOptions, JSON_UNESCAPED_UNICODE) ?>;
  var MAX_SUGGESTIONS = 12;
  var input = document.getElementById('flatSearch');
  var hidden = document.getElementById('flatValue');
  var box = document.getElementById('flatSuggestions');
  var form = input.form;
  var activeIndex = -1, current = [];
  function norm(s) { return (s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
  FLATS.forEach(function (f) { f.n = norm(f.l); });
  function close() { box.classList.remove('open'); box.innerHTML = ''; activeIndex = -1; current = []; }
  function choose(f) { input.value = f.l; hidden.value = f.v; close(); }
  function render(list, q) {
    box.innerHTML = ''; activeIndex = -1; current = list;
    if (!q) { box.classList.remove('open'); return; }
    if (!list.length) {
      var none = document.createElement('div');
      none.className = 'sug-none'; none.textContent = 'No matching house number';
      box.appendChild(none);
    } else {
      list.forEach(function (f) {
        var d = document.createElement('div');
        d.className = 'sug'; d.textContent = f.l;
        d.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(f); });
        box.appendChild(d);
      });
    }
    box.classList.add('open');
  }
  function update() {
    var q = norm(input.value); hidden.value = '';
    if (!q) { close(); return; }
    var starts = [], contains = [];
    for (var i = 0; i < FLATS.length; i++) {
      var f = FLATS[i];
      if (f.n.indexOf(q) === 0) starts.push(f);
      else if (f.n.indexOf(q) !== -1) contains.push(f);
    }
    var exact = FLATS.filter(function (f) { return f.n === q; });
    if (exact.length === 1) hidden.value = exact[0].v;
    render(starts.concat(contains).slice(0, MAX_SUGGESTIONS), q);
  }
  input.addEventListener('input', update);
  input.addEventListener('focus', function () { if (!hidden.value) update(); });
  input.addEventListener('blur', function () { setTimeout(close, 150); });
  input.addEventListener('keydown', function (ev) {
    var items = box.querySelectorAll('.sug');
    if (ev.key === 'ArrowDown' && items.length) { ev.preventDefault(); activeIndex = (activeIndex + 1) % items.length; }
    else if (ev.key === 'ArrowUp' && items.length) { ev.preventDefault(); activeIndex = (activeIndex - 1 + items.length) % items.length; }
    else if (ev.key === 'Enter') {
      if (box.classList.contains('open') && items.length) { ev.preventDefault(); choose(current[activeIndex >= 0 ? activeIndex : 0]); }
      return;
    } else if (ev.key === 'Escape') { close(); return; }
    else return;
    items.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); });
  });
  form.addEventListener('submit', function (ev) {
    if (hidden.value) return;
    var q = norm(input.value);
    var exact = FLATS.filter(function (f) { return f.n === q; });
    if (exact.length === 1) { hidden.value = exact[0].v; return; }
    ev.preventDefault(); input.focus(); update();
  });
})();
</script>

<p style="text-align:center"><a href="<?= e(APP_URL) ?>/privacy.php">Privacy and data use</a></p>
<?= uv_update_prompt_html() /* old app installs only: "update from Google Play" */ ?>
</body>
</html>
