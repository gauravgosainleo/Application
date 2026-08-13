<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'send':    send_request(); break;
    case 'accept':  accept_request(); break;
    case 'reject':  reject_request(); break;
    case 'list':    list_friends(); break;
    case 'pending': pending_requests(); break;
    case 'search':  search_users(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function send_request(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins do not use friends'],400);
    $d = read_json_input();
    $to = (int)($d['user_id'] ?? 0);
    if (!$to || $to === current_user_id()) json_response(['success'=>false,'message'=>'Invalid user'],400);

    $stmt = db()->prepare('SELECT status, sender_id FROM friend_requests
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) LIMIT 1');
    $stmt->execute([current_user_id(), $to, $to, current_user_id()]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['status'] === 'accepted') json_response(['success'=>false,'message'=>'You are already friends']);
        if ($existing['status'] === 'pending')  json_response(['success'=>false,'message'=>'Request already pending']);
    }
    db()->prepare('INSERT INTO friend_requests (sender_id, receiver_id, status) VALUES (?,?, "pending")
                   ON DUPLICATE KEY UPDATE status="pending", updated_at=NOW()')
        ->execute([current_user_id(), $to]);
    json_response(['success'=>true,'message'=>'Request sent']);
}

function accept_request(): void {
    require_login();
    $d = read_json_input();
    $id = (int)($d['request_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM friend_requests WHERE id=? AND receiver_id=?');
    $stmt->execute([$id, current_user_id()]);
    $fr = $stmt->fetch();
    if (!$fr) json_response(['success'=>false,'message'=>'Request not found'],404);
    db()->prepare('UPDATE friend_requests SET status="accepted" WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}

function reject_request(): void {
    require_login();
    $d = read_json_input();
    $id = (int)($d['request_id'] ?? 0);
    db()->prepare('UPDATE friend_requests SET status="rejected" WHERE id=? AND receiver_id=?')
        ->execute([$id, current_user_id()]);
    json_response(['success'=>true]);
}

function list_friends(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>true,'items'=>[]]);
    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.email, u.profile_picture FROM friend_requests f
         JOIN users u ON u.id = IF(f.sender_id=?, f.receiver_id, f.sender_id)
         WHERE f.status='accepted' AND (f.sender_id=? OR f.receiver_id=?)"
    );
    $stmt->execute([current_user_id(), current_user_id(), current_user_id()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}

function pending_requests(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>true,'items'=>[]]);
    $stmt = db()->prepare(
        "SELECT f.id AS request_id, u.id AS user_id, u.name, u.email, u.profile_picture, f.created_at
         FROM friend_requests f JOIN users u ON u.id = f.sender_id
         WHERE f.status = 'pending' AND f.receiver_id = ?
         ORDER BY f.created_at DESC"
    );
    $stmt->execute([current_user_id()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}

function search_users(): void {
    require_login();
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_response(['success'=>true,'items'=>[]]);
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        'SELECT id, name, email, profile_picture FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id <> ? LIMIT 20'
    );
    $stmt->execute([$like, $like, current_user_id() ?: 0]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}
