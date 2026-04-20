<?php
require_once __DIR__ . '/../config/db.php';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect($path) {
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) || !empty($_SESSION['is_guest']);
}

function current_user(): ?array {
    if (!empty($_SESSION['is_guest'])) {
        return [
            'id' => 0,
            'username' => 'Guest',
            'owner_name' => 'Guest User',
            'role' => 'guest',
            'tower' => null,
            'house_number' => null,
            'email' => null,
            'photo' => null,
        ];
    }
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login() {
    if (!is_logged_in()) redirect('auth/login.php');
}

function require_admin() {
    require_login();
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        http_response_code(403);
        die('Access denied. Admins only.');
    }
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function is_guest(): bool {
    $u = current_user();
    return $u && $u['role'] === 'guest';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool {
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return hash_equals($_SESSION['csrf'] ?? '', $t);
}

function flash($key, $msg = null) {
    if ($msg === null) {
        $m = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $m;
    }
    $_SESSION['flash'][$key] = $msg;
}

function upload_file(array $file, string $subdir): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed, true)) return null;
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $subdir . '/' . $name;
}

function upload_url($rel) {
    return $rel ? UPLOAD_URL . '/' . $rel : '';
}

function send_mail(string $to, string $subject, string $html, array $extraHeaders = []): bool {
    $headers  = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM;
    foreach ($extraHeaders as $h) $headers[] = $h;
    return @mail($to, $subject, $html, implode("\r\n", $headers));
}

function months_ago($n = 12): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $t = strtotime("-$i months");
        $out[] = date('Y-m', $t);
    }
    return $out;
}
