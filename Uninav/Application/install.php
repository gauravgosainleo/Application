<?php
// =====================================================================
// Uninav Society Management - Installer
// Run once in browser:  /Uninav/Application/install.php
// =====================================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$log = [];
$ok  = true;

function run_step(&$log, &$ok, $name, $fn) {
    try { $fn(); $log[] = "[OK] $name"; }
    catch (Throwable $e) { $ok = false; $log[] = "[FAIL] $name - " . $e->getMessage(); }
}

// 1. Run schema
run_step($log, $ok, 'Apply schema.sql', function () {
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    $pdo = db();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && stripos($stmt, 'SET ') !== 0) {
            $pdo->exec($stmt);
        } else if (stripos($stmt, 'SET ') === 0) {
            $pdo->exec($stmt);
        }
    }
});

// 2. Seed admin user
run_step($log, $ok, 'Seed admin user', function () {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([ADMIN_USERNAME]);
    if (!$stmt->fetch()) {
        $hash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO users (username, owner_name, password_hash, role, email_verified) VALUES (?,?,?,?,1)')
            ->execute([ADMIN_USERNAME, 'Society Administrator', $hash, 'admin']);
    }
});

// 3. Seed guest user
run_step($log, $ok, 'Seed guest user', function () {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([GUEST_USERNAME]);
    if (!$stmt->fetch()) {
        $hash = password_hash(GUEST_PASSWORD, PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO users (username, owner_name, password_hash, role, email_verified) VALUES (?,?,?,?,1)')
            ->execute([GUEST_USERNAME, 'Guest User', $hash, 'guest']);
    }
});

// 4. Ensure upload dirs
run_step($log, $ok, 'Create upload directories', function () {
    foreach (['complaints','notices','vendors','ads','profiles','polls','events'] as $d) {
        $p = UPLOAD_DIR . '/' . $d;
        if (!is_dir($p)) mkdir($p, 0775, true);
    }
});
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Install Uninav Society</title>
<link rel="stylesheet" href="assets/css/style.css"></head>
<body style="padding:40px;font-family:system-ui;">
<h1>Uninav Society - Installer</h1>
<ul>
<?php foreach ($log as $l): ?>
<li><?= e($l) ?></li>
<?php endforeach; ?>
</ul>
<?php if ($ok): ?>
    <p style="color:green;font-weight:bold;">Install complete.
       <a href="<?= APP_URL ?>/auth/login.php">Go to Login &rarr;</a></p>
    <p style="color:#900;"><strong>Security:</strong> delete <code>install.php</code> after setup.</p>
<?php else: ?>
    <p style="color:red;font-weight:bold;">Install encountered errors, see above.</p>
<?php endif; ?>
</body></html>
