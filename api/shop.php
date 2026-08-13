<?php
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':           list_apps(); break;
    case 'mine':           list_mine(); break;
    case 'view':           view_app(); break;
    case 'submit':         submit_app(); break;
    case 'verify_payment': verify_payment(); break;
    case 'comment':        add_comment(); break;
    case 'comments':       list_comments(); break;
    case 'like':           toggle_like(); break;
    case 'interest':       send_interest(); break;
    case 'admin_pending':  admin_pending(); break;
    case 'admin_approve':  admin_approve(); break;
    case 'admin_reject':   admin_reject(); break;
    default: json_response(['success'=>false,'message'=>'Unknown action'],400);
}

function list_apps(): void {
    require_login();
    $stmt = db()->query(
        "SELECT a.*, u.name AS developer_name, u.email AS developer_email, u.phone AS developer_phone,
           (SELECT COUNT(*) FROM ai_shop_likes    l WHERE l.app_id = a.id) AS likes_count,
           (SELECT COUNT(*) FROM ai_shop_comments c WHERE c.app_id = a.id) AS comments_count
         FROM ai_shop_apps a JOIN users u ON u.id = a.user_id
         WHERE a.status = 'approved'
         ORDER BY a.published_at DESC"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) attach_images($r);
    json_response(['success'=>true,'items'=>$rows]);
}

function list_mine(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>true,'items'=>[]]);
    $stmt = db()->prepare('SELECT * FROM ai_shop_apps WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([current_user_id()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) attach_images($r);
    json_response(['success'=>true,'items'=>$rows]);
}

function view_app(): void {
    require_login();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT a.*, u.name AS developer_name, u.email AS developer_email, u.phone AS developer_phone,
                u.profile_picture AS developer_photo, u.id AS developer_id,
           (SELECT COUNT(*) FROM ai_shop_likes l WHERE l.app_id = a.id) AS likes_count
         FROM ai_shop_apps a JOIN users u ON u.id = a.user_id WHERE a.id=?"
    );
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) json_response(['success'=>false,'message'=>'Not found'],404);
    attach_images($app);
    if ($app['developer_photo']) $app['developer_photo_url'] = UPLOAD_URL . '/' . $app['developer_photo'];
    json_response(['success'=>true,'app'=>$app]);
}

function attach_images(array &$r): void {
    $stmt = db()->prepare('SELECT image_path FROM ai_shop_images WHERE app_id=?');
    $stmt->execute([$r['id']]);
    $imgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $r['images'] = array_map(fn($p) => UPLOAD_URL . '/' . $p, $imgs);
}

function submit_app(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot list apps'],400);

    $name        = sanitize($_POST['app_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $link        = trim($_POST['app_link'] ?? '');
    $comments_on = !empty($_POST['comments_enabled']) ? 1 : 0;
    if (!$name || !$description) json_response(['success'=>false,'message'=>'Name and description required'],400);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO ai_shop_apps (user_id,app_name,description,app_link,comments_enabled,status)
                       VALUES (?,?,?,?,?, "pending_payment")')
            ->execute([current_user_id(), $name, $description, $link, $comments_on]);
        $app_id = (int) $pdo->lastInsertId();

        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['name'] as $i => $orig) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $single = [
                    'name'     => $orig,
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                $_FILES['__one__'] = $single;
                $path = save_upload('__one__','shop');
                unset($_FILES['__one__']);
                if ($path) {
                    $pdo->prepare('INSERT INTO ai_shop_images (app_id,image_path) VALUES (?,?)')
                        ->execute([$app_id, $path]);
                }
            }
        }

        $amount_paise = AI_SHOP_LISTING_FEE * 100;
        $order = razorpay_create_order($amount_paise, 'app_' . $app_id);
        if (!isset($order['id'])) {
            throw new RuntimeException('Razorpay order failed: ' . json_encode($order));
        }

        $pdo->prepare('INSERT INTO payments (user_id, app_id, amount, razorpay_order_id, status)
                       VALUES (?,?,?,?, "created")')
            ->execute([current_user_id(), $app_id, AI_SHOP_LISTING_FEE, $order['id']]);
        $payment_id = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE ai_shop_apps SET payment_id=? WHERE id=?')
            ->execute([$payment_id, $app_id]);

        $pdo->commit();
        json_response([
            'success'    => true,
            'app_id'     => $app_id,
            'order_id'   => $order['id'],
            'amount'     => $amount_paise,
            'currency'   => 'INR',
            'key_id'     => RAZORPAY_KEY_ID,
            'name'       => APP_NAME,
            'description'=> 'AI Shop Listing Fee (1 year)',
            'prefill'    => ['name' => $ctx['name'], 'email' => $ctx['email']],
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('submit_app: ' . $e->getMessage());
        json_response(['success'=>false,'message'=>'Submission failed: ' . $e->getMessage()], 500);
    }
}

function razorpay_create_order(int $amount_paise, string $receipt): array {
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    $payload = json_encode([
        'amount'   => $amount_paise, 'currency' => 'INR',
        'receipt'  => $receipt, 'payment_capture' => 1,
    ]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); return ['error' => $err]; }
    curl_close($ch);
    $j = json_decode($resp, true);
    return is_array($j) ? $j : ['error' => 'invalid response'];
}

function verify_payment(): void {
    $ctx = require_login();
    $d = read_json_input();
    $order_id   = $d['razorpay_order_id']   ?? '';
    $payment_id = $d['razorpay_payment_id'] ?? '';
    $signature  = $d['razorpay_signature']  ?? '';
    if (!$order_id || !$payment_id || !$signature) {
        json_response(['success'=>false,'message'=>'Missing payment fields'],400);
    }
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expected, $signature)) {
        log_event(current_user_id(), $ctx['email'], 'payment_verify', 'failed', 'bad signature');
        json_response(['success'=>false,'message'=>'Signature verification failed'],400);
    }

    $pdo = db();
    $pdo->prepare('UPDATE payments SET razorpay_payment_id=?, razorpay_signature=?, status="paid", raw_response=?
                   WHERE razorpay_order_id=?')
        ->execute([$payment_id, $signature, json_encode($d), $order_id]);

    $stmt = $pdo->prepare('SELECT app_id FROM payments WHERE razorpay_order_id=?');
    $stmt->execute([$order_id]);
    $app_id = (int) $stmt->fetchColumn();
    if ($app_id) {
        $pdo->prepare('UPDATE ai_shop_apps SET status="awaiting_approval" WHERE id=?')
            ->execute([$app_id]);
    }
    log_event(current_user_id(), $ctx['email'], 'payment_success', 'success', "order=$order_id app=$app_id");
    json_response(['success'=>true,'message'=>'Payment verified. Awaiting admin approval.']);
}

function toggle_like(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot like'],400);
    $d = read_json_input();
    $id = (int)($d['app_id'] ?? 0);
    $stmt = db()->prepare('SELECT 1 FROM ai_shop_likes WHERE app_id=? AND user_id=?');
    $stmt->execute([$id, current_user_id()]);
    if ($stmt->fetchColumn()) {
        db()->prepare('DELETE FROM ai_shop_likes WHERE app_id=? AND user_id=?')
            ->execute([$id, current_user_id()]);
        json_response(['success'=>true,'liked'=>false]);
    }
    db()->prepare('INSERT INTO ai_shop_likes (app_id,user_id) VALUES (?,?)')
        ->execute([$id, current_user_id()]);
    json_response(['success'=>true,'liked'=>true]);
}

function list_comments(): void {
    require_login();
    $id = (int)($_GET['app_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT c.*, u.name, u.profile_picture FROM ai_shop_comments c
         LEFT JOIN users u ON u.id = c.user_id WHERE c.app_id = ? ORDER BY c.created_at ASC LIMIT 500'
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
    $id = (int)($d['app_id'] ?? 0);
    $c  = sanitize($d['comment'] ?? '');
    if (!$id || !$c) json_response(['success'=>false,'message'=>'Input required'],400);
    $stmt = db()->prepare('SELECT comments_enabled FROM ai_shop_apps WHERE id=?');
    $stmt->execute([$id]);
    $en = $stmt->fetchColumn();
    if ($en === false) json_response(['success'=>false,'message'=>'App not found'],404);
    if (!$en) json_response(['success'=>false,'message'=>'Comments are disabled for this app'],400);
    db()->prepare('INSERT INTO ai_shop_comments (app_id,user_id,comment) VALUES (?,?,?)')
        ->execute([$id, current_user_id(), $c]);
    json_response(['success'=>true]);
}

function send_interest(): void {
    $ctx = require_login();
    if ($ctx['is_admin']) json_response(['success'=>false,'message'=>'Admins cannot send interest'],400);
    $d = read_json_input();
    $id = (int)($d['app_id'] ?? 0);
    $msg = sanitize($d['message'] ?? '');
    db()->prepare('INSERT INTO ai_shop_interests (app_id,user_id,message) VALUES (?,?,?)')
        ->execute([$id, current_user_id(), $msg]);
    json_response(['success'=>true,'message'=>'Interest sent to developer']);
}

function admin_pending(): void {
    require_admin();
    $stmt = db()->query(
        "SELECT a.*, u.name AS developer_name, u.email AS developer_email
         FROM ai_shop_apps a JOIN users u ON u.id = a.user_id
         WHERE a.status = 'awaiting_approval' ORDER BY a.created_at DESC"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) attach_images($r);
    json_response(['success'=>true,'items'=>$rows]);
}

function admin_approve(): void {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    db()->prepare('UPDATE ai_shop_apps
                   SET status="approved", published_at=NOW(),
                       expires_at=DATE_ADD(NOW(), INTERVAL 1 YEAR)
                   WHERE id=?')
        ->execute([$id]);
    log_event(null, 'admin@aiplatform.fun', 'shop_app_approved', 'success', "app=$id");
    json_response(['success'=>true]);
}

function admin_reject(): void {
    require_admin();
    $d = read_json_input();
    $id = (int)($d['id'] ?? 0);
    $reason = sanitize($d['reason'] ?? 'Not approved');
    db()->prepare('UPDATE ai_shop_apps SET status="rejected", rejection_reason=? WHERE id=?')
        ->execute([$reason, $id]);
    log_event(null, 'admin@aiplatform.fun', 'shop_app_rejected', 'success', "app=$id");
    json_response(['success'=>true]);
}
