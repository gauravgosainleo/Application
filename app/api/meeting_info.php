<?php
require_once __DIR__ . '/../db.php';

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') json_response(['error' => 'code required'], 400);

$stmt = db()->prepare('SELECT meeting_code, name, description, created_at FROM meetings WHERE meeting_code = ?');
$stmt->execute([$code]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'Meeting not found'], 404);

json_response($row);
