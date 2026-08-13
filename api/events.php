<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':   list_events(); break;
    case 'create': create_event(); break;
    case 'update': update_event(); break;
    case 'delete': delete_event(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function list_events(): void {
    require_login();
    $month = $_GET['month'] ?? '';
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-d 23:59:59', strtotime($start . ' +1 month -1 day'));
        $stmt  = db()->prepare('SELECT * FROM events WHERE event_datetime BETWEEN ? AND ? ORDER BY event_datetime ASC');
        $stmt->execute([$start, $end]);
    } else {
        $stmt = db()->query('SELECT * FROM events WHERE event_datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY event_datetime ASC');
    }
    json_response(['success'=>true,'items'=>$stmt->fetchAll()]);
}

function create_event(): void {
    require_admin();
    $d = read_json_input();
    $name = sanitize($d['event_name'] ?? '');
    $desc = trim($d['description'] ?? '');
    $link = trim($d['link'] ?? '');
    $dt   = trim($d['event_datetime'] ?? '');
    if (!$name || !$desc || !$dt) json_response(['success'=>false,'message'=>'All fields required'],400);
    $ts = strtotime($dt);
    if (!$ts) json_response(['success'=>false,'message'=>'Invalid date/time'],400);
    db()->prepare('INSERT INTO events (admin_id,event_name,description,link,event_datetime) VALUES (?,?,?,?,?)')
        ->execute([$_SESSION['user_id'] ?: null, $name, $desc, $link, date('Y-m-d H:i:s', $ts)]);
    log_event(null, $_SESSION['email'] ?? '', 'event_create', 'success', $name);
    json_response(['success'=>true,'message'=>'Event saved']);
}

function update_event(): void {
    require_admin();
    $d = read_json_input();
    $id = (int)($d['id'] ?? 0);
    $name = sanitize($d['event_name'] ?? '');
    $desc = trim($d['description'] ?? '');
    $link = trim($d['link'] ?? '');
    $dt   = date('Y-m-d H:i:s', strtotime($d['event_datetime'] ?? 'now'));
    db()->prepare('UPDATE events SET event_name=?,description=?,link=?,event_datetime=? WHERE id=?')
        ->execute([$name,$desc,$link,$dt,$id]);
    json_response(['success'=>true]);
}

function delete_event(): void {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}
