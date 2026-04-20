<?php
require_once __DIR__ . '/../db.php';

$body = read_json_body();
$code = trim((string)($body['code'] ?? ($_GET['code'] ?? '')));
$peer = trim((string)($body['peer'] ?? ($_GET['peer'] ?? '')));
if ($code === '' || $peer === '') json_response(['error' => 'code and peer required'], 400);

$pdo = db();
$pdo->prepare('DELETE FROM participants WHERE meeting_code = ? AND peer_id = ?')->execute([$code, $peer]);
$pdo->prepare('DELETE FROM signals WHERE meeting_code = ? AND (from_peer = ? OR to_peer = ?)')->execute([$code, $peer, $peer]);

json_response(['ok' => true]);
