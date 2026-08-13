<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get':             get_profile(); break;
    case 'update':          update_profile(); break;
    case 'upload_photo':    upload_photo(); break;
    case 'change_password': change_password(); break;
    case 'view_user':       view_user(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function get_profile(): void {
    require_login();
    if (is_admin()) {
        json_response(['success'=>true,'user'=>[
            'id'=>0,'name'=>'Administrator','email'=>'admin@aiplatform.fun',
            'phone'=>null,'profile_picture'=>null,'bio'=>'Platform administrator','role'=>'admin',
        ]]);
    }
    $stmt = db()->prepare('SELECT id,name,email,phone,profile_picture,bio,role FROM users WHERE id=?');
    $stmt->execute([current_user_id()]);
    $user = $stmt->fetch();
    if ($user && $user['profile_picture']) $user['profile_picture_url'] = UPLOAD_URL . '/' . $user['profile_picture'];
    json_response(['success'=>true,'user'=>$user]);
}

function update_profile(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admin profile is fixed'],400);
    $d = read_json_input();
    $name  = sanitize($d['name'] ?? '');
    $phone = sanitize($d['phone'] ?? '');
    $email = strtolower(trim($d['email'] ?? ''));
    $bio   = sanitize($d['bio'] ?? '');
    if (!$name) json_response(['success'=>false,'message'=>'Name required'],400);
    if (!is_valid_email($email)) json_response(['success'=>false,'message'=>'Invalid email'],400);

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $stmt->execute([$email, current_user_id()]);
    if ($stmt->fetchColumn()) json_response(['success'=>false,'message'=>'Email already in use'],400);

    db()->prepare('UPDATE users SET name=?, phone=?, email=?, bio=? WHERE id=?')
        ->execute([$name, $phone, $email, $bio, current_user_id()]);
    $_SESSION['name']  = $name;
    $_SESSION['email'] = $email;
    log_event(current_user_id(), $email, 'profile_update', 'success');
    json_response(['success'=>true,'message'=>'Profile updated']);
}

function upload_photo(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admin profile is fixed'],400);
    $path = save_upload('photo', 'profiles');
    if (!$path) json_response(['success'=>false,'message'=>'Upload failed'],400);
    db()->prepare('UPDATE users SET profile_picture=? WHERE id=?')->execute([$path, current_user_id()]);
    json_response(['success'=>true,'path'=>UPLOAD_URL . '/' . $path]);
}

function change_password(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admin password is fixed in config'],400);
    $d = read_json_input();
    $current = $d['current_password'] ?? '';
    $new     = $d['new_password'] ?? '';
    $conf    = $d['confirm_password'] ?? '';
    if (strlen($new) < 6) json_response(['success'=>false,'message'=>'Password too short'],400);
    if ($new !== $conf)   json_response(['success'=>false,'message'=>'Passwords do not match'],400);
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([current_user_id()]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        json_response(['success'=>false,'message'=>'Current password is incorrect'],400);
    }
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
    log_event(current_user_id(), $ctx['email'], 'password_change', 'success');
    json_response(['success'=>true,'message'=>'Password changed']);
}

function view_user(): void {
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_response(['success'=>false,'message'=>'Invalid user id'],400);
    $stmt = db()->prepare('SELECT id, name, email, phone, profile_picture, bio FROM users WHERE id=?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) json_response(['success'=>false,'message'=>'User not found'],404);
    if ($user['profile_picture']) $user['profile_picture_url'] = UPLOAD_URL . '/' . $user['profile_picture'];

    $me = current_user_id();
    $f_status = 'none';
    if ($me && $me !== $id) {
        $stmt = db()->prepare('SELECT status, sender_id FROM friend_requests
            WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) LIMIT 1');
        $stmt->execute([$me, $id, $id, $me]);
        $fr = $stmt->fetch();
        if ($fr) {
            if ($fr['status'] === 'accepted') $f_status = 'friends';
            elseif ($fr['status'] === 'pending' && (int)$fr['sender_id'] === $me) $f_status = 'request_sent';
            elseif ($fr['status'] === 'pending') $f_status = 'request_received';
            else $f_status = $fr['status'];
        }
    }
    json_response(['success'=>true,'user'=>$user,'friendship'=>$f_status]);
}
