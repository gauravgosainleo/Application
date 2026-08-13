<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':      list_forums(false); break;
    case 'mine':      list_forums(true);  break;
    case 'create':    create_forum();     break;
    case 'delete':    delete_forum();     break;
    case 'react':     react_forum();      break;
    case 'comment':   add_comment();      break;
    case 'comments':  list_comments();    break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function list_forums(bool $mine): void {
    $ctx = require_login();
    $params = []; $where = '';
    if ($mine) {
        if ($ctx['is_admin']) json_response(['success'=>true,'items'=>[]]);
        $where = ' WHERE f.user_id = ? ';
        $params[] = current_user_id();
    }
    $sql = "SELECT f.*, u.name AS author, u.profile_picture,
              (SELECT COUNT(*) FROM forum_reactions r WHERE r.forum_id=f.id AND r.reaction='like')    AS likes,
              (SELECT COUNT(*) FROM forum_reactions r WHERE r.forum_id=f.id AND r.reaction='dislike') AS dislikes,
              (SELECT COUNT(*) FROM forum_comments c WHERE c.forum_id=f.id) AS comments_count
            FROM forums f LEFT JOIN users u ON u.id = f.user_id
            $where
            ORDER BY f.created_at DESC LIMIT 200";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}

function create_forum(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot post forums'],400);
    $d = read_json_input();
    $title   = sanitize($d['title']   ?? '');
    $content = trim($d['content'] ?? '');
    if (!$title || !$content) json_response(['success'=>false,'message'=>'Title and content required'],400);
    db()->prepare('INSERT INTO forums (user_id,title,content) VALUES (?,?,?)')
        ->execute([current_user_id(), $title, $content]);
    json_response(['success'=>true,'message'=>'Forum posted']);
}

function delete_forum(): void {
    $ctx = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT user_id FROM forums WHERE id=?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) json_response(['success'=>false,'message'=>'Not found'],404);
    if (!$ctx['is_admin'] && (int)$f['user_id'] !== current_user_id()) {
        json_response(['success'=>false,'message'=>'Not allowed'],403);
    }
    db()->prepare('DELETE FROM forums WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}

function react_forum(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot react'],400);
    $d = read_json_input();
    $id = (int)($d['forum_id'] ?? 0);
    $r  = $d['reaction'] ?? '';
    if (!$id || !in_array($r, ['like','dislike'], true)) {
        json_response(['success'=>false,'message'=>'Invalid input'],400);
    }
    $stmt = db()->prepare('SELECT reaction FROM forum_reactions WHERE forum_id=? AND user_id=?');
    $stmt->execute([$id, current_user_id()]);
    $existing = $stmt->fetchColumn();
    if ($existing === $r) {
        db()->prepare('DELETE FROM forum_reactions WHERE forum_id=? AND user_id=?')
            ->execute([$id, current_user_id()]);
    } elseif ($existing) {
        db()->prepare('UPDATE forum_reactions SET reaction=? WHERE forum_id=? AND user_id=?')
            ->execute([$r, $id, current_user_id()]);
    } else {
        db()->prepare('INSERT INTO forum_reactions (forum_id,user_id,reaction) VALUES (?,?,?)')
            ->execute([$id, current_user_id(), $r]);
    }
    json_response(['success'=>true]);
}

function list_comments(): void {
    require_login();
    $id = (int)($_GET['forum_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT c.*, u.name, u.profile_picture FROM forum_comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.forum_id = ? ORDER BY c.created_at ASC LIMIT 500'
    );
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['profile_picture']) $r['profile_picture_url'] = UPLOAD_URL . '/' . $r['profile_picture'];
    json_response(['success'=>true,'items'=>$rows]);
}

function add_comment(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot comment'],400);
    $d = read_json_input();
    $id = (int)($d['forum_id'] ?? 0);
    $c  = sanitize($d['comment'] ?? '');
    if (!$id || !$c) json_response(['success'=>false,'message'=>'Forum id and comment required'],400);
    db()->prepare('INSERT INTO forum_comments (forum_id,user_id,comment) VALUES (?,?,?)')
        ->execute([$id, current_user_id(), $c]);
    json_response(['success'=>true]);
}
