<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST required'], 405);

$body = read_json_body();
$code = trim((string)($body['code'] ?? ''));
$name = trim((string)($body['name'] ?? 'Guest'));
if ($code === '') json_response(['error' => 'code required'], 400);
if ($name === '') $name = 'Guest';
if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

$pdo = db();
$stmt = $pdo->prepare('SELECT meeting_code, name, description FROM meetings WHERE meeting_code = ?');
$stmt->execute([$code]);
$meeting = $stmt->fetch();
if (!$meeting) json_response(['error' => 'Meeting not found'], 404);

$peer_id = uuid_v4();
$now = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare('INSERT INTO participants (meeting_code, peer_id, display_name, joined_at, last_seen) VALUES (?,?,?,?,?)');
$stmt->execute([$code, $peer_id, $name, $now, $now]);

json_response([
    'peer_id' => $peer_id,
    'meeting' => $meeting,
]);
