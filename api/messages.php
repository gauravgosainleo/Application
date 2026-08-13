<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'threads':  threads(); break;
    case 'list':     list_messages(); break;
    case 'send':     send_message(); break;
    case 'mark_read':mark_read(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function threads(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>true,'items'=>[]]);
    $uid = current_user_id();
    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.email, u.profile_picture,
                (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id)
                 ORDER BY id DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id)
                 ORDER BY id DESC LIMIT 1) AS last_time,
                (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=? AND read_status=0) AS unread
         FROM friend_requests f
         JOIN users u ON u.id = IF(f.sender_id=?, f.receiver_id, f.sender_id)
         WHERE f.status='accepted' AND (f.sender_id=? OR f.receiver_id=?)
         ORDER BY last_time DESC"
    );
    $stmt->execute([$uid,$uid,$uid,$uid,$uid,$uid,$uid,$uid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}

function list_messages(): void {
    $ctx = require_login();
    $with = (int)($_GET['with'] ?? 0);
    if ($ctx['is_admin'] || !$with) json_response(['success'=>true,'items'=>[]]);
    if (!are_friends(current_user_id(), $with)) {
        json_response(['success'=>false,'message'=>'You must be friends to view messages'],403);
    }
    $stmt = db()->prepare(
        'SELECT * FROM messages
         WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
         ORDER BY id ASC LIMIT 1000'
    );
    $stmt->execute([current_user_id(),$with,$with,current_user_id()]);
    db()->prepare('UPDATE messages SET read_status=1 WHERE sender_id=? AND receiver_id=?')
        ->execute([$with, current_user_id()]);
    json_response(['success'=>true,'items'=>$stmt->fetchAll()]);
}

function send_message(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot message'],400);
    $d = read_json_input();
    $to  = (int)($d['to'] ?? 0);
    $msg = sanitize($d['message'] ?? '');
    if (!$to || !$msg) json_response(['success'=>false,'message'=>'Recipient and message required'],400);
    if (!are_friends(current_user_id(), $to)) {
        json_response(['success'=>false,'message'=>'First send friend request and if its accepted then you can send the messages'],403);
    }
    db()->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)')
        ->execute([current_user_id(), $to, $msg]);
    json_response(['success'=>true]);
}

function mark_read(): void {
    require_login();
    $d = read_json_input();
    $with = (int)($d['with'] ?? 0);
    db()->prepare('UPDATE messages SET read_status=1 WHERE sender_id=? AND receiver_id=?')
        ->execute([$with, current_user_id()]);
    json_response(['success'=>true]);
}
