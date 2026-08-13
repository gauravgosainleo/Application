<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':     list_news(); break;
    case 'create':   create_news(); break;
    case 'delete':   delete_news(); break;
    case 'comment':  add_comment(); break;
    case 'comments': list_comments(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function list_news(): void {
    require_login();
    $stmt = db()->query(
        'SELECT n.*, (SELECT COUNT(*) FROM news_comments c WHERE c.news_id = n.id) AS comments_count
         FROM news_posts n ORDER BY n.created_at DESC LIMIT 100'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) if ($r['image']) $r['image_url'] = UPLOAD_URL . '/' . $r['image'];
    json_response(['success'=>true,'items'=>$rows]);
}

function create_news(): void {
    require_admin();
    $title   = sanitize($_POST['title']   ?? '');
    $content = trim($_POST['content'] ?? '');
    if (!$title || !$content) json_response(['success'=>false,'message'=>'Title and content required'],400);
    $img = save_upload('image','news');
    db()->prepare('INSERT INTO news_posts (admin_id,title,content,image) VALUES (?,?,?,?)')
        ->execute([$_SESSION['user_id'] ?: null, $title, $content, $img]);
    log_event(null, $_SESSION['email'] ?? '', 'news_create', 'success', $title);
    json_response(['success'=>true,'message'=>'News published']);
}

function delete_news(): void {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    db()->prepare('DELETE FROM news_posts WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}

function list_comments(): void {
    require_login();
    $id = (int)($_GET['news_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT c.*, u.name, u.profile_picture FROM news_comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.news_id = ? ORDER BY c.created_at DESC LIMIT 500'
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
    $id = (int)($d['news_id'] ?? 0);
    $c  = sanitize($d['comment'] ?? '');
    if (!$id || !$c) json_response(['success'=>false,'message'=>'News id and comment required'],400);
    db()->prepare('INSERT INTO news_comments (news_id,user_id,comment) VALUES (?,?,?)')
        ->execute([$id, current_user_id(), $c]);
    json_response(['success'=>true]);
}
