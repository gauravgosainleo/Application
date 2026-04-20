<?php
// WebRTC signalling relay. POST enqueues a signal for a target peer; GET fetches
// and marks as delivered all signals addressed to the calling peer.
require_once __DIR__ . '/../db.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = read_json_body();
    $code = trim((string)($body['code'] ?? ''));
    $from = trim((string)($body['from'] ?? ''));
    $to = trim((string)($body['to'] ?? ''));
    $type = trim((string)($body['type'] ?? ''));
    $payload = $body['payload'] ?? null;

    if ($code === '' || $from === '' || $to === '' || $type === '') {
        json_response(['error' => 'code, from, to, type required'], 400);
    }
    if (!in_array($type, ['offer', 'answer', 'ice', 'bye', 'media-state'], true)) {
        json_response(['error' => 'Invalid signal type'], 400);
    }
    $payload_json = json_encode($payload);
    if ($payload_json === false) json_response(['error' => 'Invalid payload'], 400);

    $stmt = $pdo->prepare('INSERT INTO signals (meeting_code, from_peer, to_peer, signal_type, payload, created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$code, $from, $to, $type, $payload_json, gmdate('Y-m-d H:i:s')]);

    json_response(['ok' => true]);
}

if ($method === 'GET') {
    $code = trim((string)($_GET['code'] ?? ''));
    $peer = trim((string)($_GET['peer'] ?? ''));
    if ($code === '' || $peer === '') json_response(['error' => 'code and peer required'], 400);

    // Heartbeat this peer while polling.
    $upd = $pdo->prepare('UPDATE participants SET last_seen = ? WHERE meeting_code = ? AND peer_id = ?');
    $upd->execute([gmdate('Y-m-d H:i:s'), $code, $peer]);

    $stmt = $pdo->prepare('SELECT id, from_peer, signal_type, payload FROM signals WHERE meeting_code = ? AND to_peer = ? AND delivered = 0 ORDER BY id ASC LIMIT 200');
    $stmt->execute([$code, $peer]);
    $rows = $stmt->fetchAll();

    $ids = array_column($rows, 'id');
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $mark = $pdo->prepare("UPDATE signals SET delivered = 1 WHERE id IN ($in)");
        $mark->execute($ids);
    }

    // Garbage-collect old signals (> 2 minutes, delivered).
    $gcCutoff = gmdate('Y-m-d H:i:s', time() - 120);
    $pdo->prepare('DELETE FROM signals WHERE delivered = 1 AND created_at < ?')->execute([$gcCutoff]);

    $out = array_map(function ($r) {
        return [
            'from' => $r['from_peer'],
            'type' => $r['signal_type'],
            'payload' => json_decode($r['payload'], true),
        ];
    }, $rows);

    json_response(['signals' => $out]);
}

json_response(['error' => 'Method not allowed'], 405);
