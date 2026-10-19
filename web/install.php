<?php
/*
 * G VI Fan Hub - one-time installer.
 * Open https://<your-domain>/install.php after uploading the files.
 * It creates the database tables, the admin account and starter content,
 * then locks itself (app/storage/installed.lock). Delete this file afterwards.
 */

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/sql/seed.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

$lockFile = STORAGE_DIR . '/installed.lock';
$installed = is_file($lockFile);
$errors = [];
$done = false;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function run_schema()
{
    $sql = file_get_contents(APP_DIR . '/sql/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            db()->exec($statement);
        }
    }
}

// ----------------------------------------------------- requirement checks
$checks = [
    ['PHP 7.4 or newer', version_compare(PHP_VERSION, '7.4.0', '>='), 'Current: ' . PHP_VERSION . ' — choose PHP 8.x in hPanel → Advanced → PHP Configuration', true],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), 'Enable pdo_mysql in PHP extensions', true],
    ['JSON extension', function_exists('json_encode'), 'Enable json', true],
    ['OpenSSL (secure SMTP email)', extension_loaded('openssl'), 'Enable openssl for SMTP over SSL/TLS', true],
    ['cURL (news auto-fetch)', function_exists('curl_init'), 'Recommended for the news auto mode', false],
    ['SimpleXML (news feeds)', function_exists('simplexml_load_string'), 'Required for the news auto mode', false],
    ['GD (image resizing)', function_exists('imagecreatetruecolor'), 'Recommended: uploads are resized and cleaned of GPS data', false],
    ['mbstring', function_exists('mb_strlen'), 'Recommended for international text', false],
    ['app/storage is writable', is_writable(STORAGE_DIR), 'Set folder permission to 755 (or 775)', true],
    ['uploads/ is writable', is_writable(WEB_DIR . '/uploads'), 'Set folder permission to 755 (or 775)', true],
];
$blocking = false;
foreach ($checks as $c) {
    if (!$c[1] && $c[3]) {
        $blocking = true;
    }
}

$dbOk = false;
$dbError = '';
if (cfg('db.pass') === 'PUT_YOUR_DB_PASSWORD_HERE') {
    $dbError = 'Open app/config.php and replace PUT_YOUR_DB_PASSWORD_HERE with your MySQL password.';
} else {
    try {
        db()->query('SELECT 1');
        $dbOk = true;
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

$mailReady = cfg('mail.transport') !== 'smtp' || (cfg('mail.password') && cfg('mail.password') !== 'PUT_YOUR_EMAIL_PASSWORD_HERE');

// ------------------------------------------------------------- install
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && !$blocking && $dbOk) {
    $adminUser = trim((string)(isset($_POST['admin_user']) ? $_POST['admin_user'] : ''));
    $adminPass = (string)(isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $adminUser)) {
        $errors[] = 'Admin username must be 3-60 characters (letters, numbers, _ . -).';
    }
    if (strlen($adminPass) < 6) {
        $errors[] = 'Admin password must be at least 6 characters.';
    }
    if (!$errors) {
        try {
            run_schema();
            gvi_seed_settings();
            gvi_seed_admin($adminUser, $adminPass);
            gvi_seed_content();
            app_secret();
            file_put_contents($lockFile, 'Installed ' . gmdate('c') . "\n", LOCK_EX);
            $installed = true;
            $done = true;
        } catch (Exception $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install · G VI Fan Hub</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #0b0714; color: #efeaf8;
    background-image: radial-gradient(circle at 10% -10%, rgba(255,61,139,.25), transparent 40%), radial-gradient(circle at 110% 10%, rgba(124,58,237,.25), transparent 45%); min-height: 100vh; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
  h1 { margin: 0 0 4px; font-size: 30px; letter-spacing: .5px; }
  h1 span { background: linear-gradient(90deg,#ff3d8b,#ff8a3d); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .sub { color: #a59cbf; margin: 0 0 24px; }
  .card { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09); border-radius: 16px; padding: 20px; margin-bottom: 18px; }
  .card h2 { margin: 0 0 12px; font-size: 17px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 7px 4px; border-bottom: 1px solid rgba(255,255,255,.06); vertical-align: top; }
  td.s { width: 70px; font-weight: 700; }
  .ok { color: #3ddc97; } .bad { color: #ff5c7a; } .warn { color: #ffb84d; }
  .hint { color: #a59cbf; font-size: 13px; }
  label { display: block; font-weight: 600; margin: 12px 0 6px; }
  input { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,.14); background: #150d27; color: #fff; font-size: 15px; }
  button, .btn { display: inline-block; margin-top: 18px; padding: 13px 22px; border: 0; border-radius: 12px; font-weight: 700; font-size: 15px; color: #fff; cursor: pointer;
    background: linear-gradient(135deg,#ff3d8b,#ff8a3d); text-decoration: none; }
  button[disabled] { opacity: .45; cursor: not-allowed; }
  .err { background: rgba(255,92,122,.12); border: 1px solid rgba(255,92,122,.4); padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
  .success { background: rgba(61,220,151,.1); border: 1px solid rgba(61,220,151,.35); }
  code { background: rgba(255,255,255,.08); padding: 2px 6px; border-radius: 6px; font-size: 13px; word-break: break-all; }
  ol { padding-left: 20px; } li { margin-bottom: 8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>G VI <span>Fan Hub</span> setup</h1>
  <p class="sub">One-time installer · creates the database tables, the admin login and starter content.</p>

<?php if ($done): ?>
  <div class="card success">
    <h2>✅ Installation complete!</h2>
    <p>The database is ready and the admin account <strong><?= h($_POST['admin_user']) ?></strong> was created.</p>
    <ol>
      <li><strong>Delete <code>install.php</code></strong> from your server (it is locked now, but deleting it is safest).</li>
      <li>Open the app: <a class="ok" href="./">your G VI Fan Hub</a> and register a player account.</li>
      <li>Open the admin panel: <a class="ok" href="admin/">admin/</a></li>
      <li>Optional: add a cron job in hPanel → Advanced → Cron Jobs (every hour):<br><code>/usr/bin/php <?= h(WEB_DIR) ?>/cron/fetch-news.php</code></li>
    </ol>
    <?php if (!$mailReady): ?>
      <p class="warn">⚠ Email is not configured yet — fill in the <code>mail</code> section of <code>app/config.php</code>, otherwise registration OTP emails cannot be sent.</p>
    <?php endif; ?>
  </div>
<?php elseif ($installed): ?>
  <div class="card">
    <h2>Already installed</h2>
    <p>G VI Fan Hub is already installed. For security the installer is locked.</p>
    <p class="hint">To re-install, delete <code>app/storage/installed.lock</code> (existing data is kept; missing tables are created and the admin password is reset to what you enter).</p>
    <a class="btn" href="./">Open the app</a>
  </div>
<?php else: ?>
  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <div class="card">
    <h2>1 · Server check</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td class="s <?= $c[1] ? 'ok' : ($c[3] ? 'bad' : 'warn') ?>"><?= $c[1] ? '✔ OK' : ($c[3] ? '✖ Fix' : '! Warn') ?></td>
          <td><?= h($c[0]) ?><?php if (!$c[1]): ?><div class="hint"><?= h($c[2]) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td class="s <?= $dbOk ? 'ok' : 'bad' ?>"><?= $dbOk ? '✔ OK' : '✖ Fix' ?></td>
        <td>Database connection (<code><?= h(cfg('db.name')) ?></code> as <code><?= h(cfg('db.user')) ?></code>)
          <?php if (!$dbOk): ?><div class="hint"><?= h($dbError) ?></div><?php endif; ?></td>
      </tr>
      <tr>
        <td class="s <?= $mailReady ? 'ok' : 'warn' ?>"><?= $mailReady ? '✔ OK' : '! Warn' ?></td>
        <td>Email for OTP codes (<?= h(cfg('mail.transport')) ?>)
          <?php if (!$mailReady): ?><div class="hint">Add your mailbox password in app/config.php → mail → password. You can install now and fix this later.</div><?php endif; ?></td>
      </tr>
    </table>
  </div>

  <form class="card" method="post" autocomplete="off">
    <h2>2 · Admin account</h2>
    <label for="admin_user">Admin username</label>
    <input id="admin_user" name="admin_user" value="<?= h(isset($_POST['admin_user']) ? $_POST['admin_user'] : 'gg6admin') ?>" required>
    <label for="admin_pass">Admin password</label>
    <input id="admin_pass" name="admin_pass" type="password" minlength="6" required>
    <p class="hint">Type the admin password you want to use. It is never stored in the code; you can change it later in the admin panel.</p>
    <button type="submit" <?= ($blocking || !$dbOk) ? 'disabled' : '' ?>>Install G VI Fan Hub</button>
    <?php if ($blocking || !$dbOk): ?><p class="bad">Fix the items marked ✖ above, then reload this page.</p><?php endif; ?>
  </form>
<?php endif; ?>
</div>
</body>
</html>
