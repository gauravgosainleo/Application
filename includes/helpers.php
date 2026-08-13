<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function read_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function require_login(): array {
    if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
        json_response(['success' => false, 'message' => 'Authentication required'], 401);
    }
    return [
        'user_id'  => $_SESSION['user_id']  ?? null,
        'is_admin' => !empty($_SESSION['is_admin']),
        'email'    => $_SESSION['email']    ?? null,
        'name'     => $_SESSION['name']     ?? null,
    ];
}

function require_admin(): void {
    if (empty($_SESSION['is_admin'])) {
        json_response(['success' => false, 'message' => 'Admin access required'], 403);
    }
}

function log_event(?int $user_id, string $email, string $action, string $status, string $detail = ''): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_logs (user_id, email, action, status, ip_address, user_agent, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $user_id,
            $email,
            $action,
            $status,
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $detail,
        ]);
    } catch (Throwable $e) {
        error_log('log_event failed: ' . $e->getMessage());
    }
}

function generate_otp(int $length = 6): string {
    $min = (int) str_pad('1', $length, '0');
    $max = (int) str_pad('9', $length, '9');
    return (string) random_int($min, $max);
}

function generate_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function is_valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function sanitize(string $s): string {
    return trim(strip_tags($s));
}

function save_upload(string $field, string $subdir, array $allowed = ['jpg','jpeg','png','gif','webp']): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $file = $_FILES[$field];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $target_dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    $target = $target_dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) return null;
    return $subdir . '/' . $name;
}

function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function are_friends(int $a, int $b): bool {
    $stmt = db()->prepare(
        "SELECT 1 FROM friend_requests
         WHERE status = 'accepted'
           AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
         LIMIT 1"
    );
    $stmt->execute([$a, $b, $b, $a]);
    return (bool) $stmt->fetchColumn();
}
