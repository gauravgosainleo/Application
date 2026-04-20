<?php
require_once __DIR__ . '/../db.php';

$code = trim((string)($_GET['code'] ?? ''));
$peer = trim((string)($_GET['peer'] ?? ''));
if ($code === '' || $peer === '') json_response(['error' => 'code and peer required'], 400);

$pdo = db();
$now = gmdate('Y-m-d H:i:s');

// Heartbeat.
$stmt = $pdo->prepare('UPDATE participants SET last_seen = ? WHERE meeting_code = ? AND peer_id = ?');
$stmt->execute([$now, $code, $peer]);

// Purge stale.
$cutoff = gmdate('Y-m-d H:i:s', time() - PARTICIPANT_TIMEOUT);
$del = $pdo->prepare('DELETE FROM participants WHERE meeting_code = ? AND last_seen < ?');
$del->execute([$code, $cutoff]);

// Active peers (excluding self).
$stmt = $pdo->prepare('SELECT peer_id, display_name, joined_at FROM participants WHERE meeting_code = ? AND peer_id <> ? ORDER BY joined_at ASC');
$stmt->execute([$code, $peer]);
$peers = $stmt->fetchAll();

json_response(['peers' => $peers, 'server_time' => $now]);
