<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST required'], 405);
}

$body = read_json_body();
$name = trim((string)($body['name'] ?? ''));
$description = trim((string)($body['description'] ?? ''));

if ($name === '') json_response(['error' => 'Meeting name is required'], 400);
if (mb_strlen($name) > 150) json_response(['error' => 'Meeting name too long'], 400);
if (mb_strlen($description) > 2000) json_response(['error' => 'Description too long'], 400);

try {
    $pdo = db();
    // Ensure uniqueness of generated short code.
    for ($tries = 0; $tries < 5; $tries++) {
        $code = short_id(10);
        $stmt = $pdo->prepare('SELECT 1 FROM meetings WHERE meeting_code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) break;
    }

    $stmt = $pdo->prepare('INSERT INTO meetings (meeting_code, name, description, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$code, $name, $description, gmdate('Y-m-d H:i:s')]);

    $join_url = rtrim(APP_BASE_URL, '/') . '/meeting.php?code=' . urlencode($code);
    json_response(['code' => $code, 'join_url' => $join_url, 'name' => $name, 'description' => $description]);
} catch (Throwable $e) {
    json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
}
