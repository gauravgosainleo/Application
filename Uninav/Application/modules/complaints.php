<?php
require_once __DIR__ . '/../includes/layout.php';

$U = current_user();
$err = $msg = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'raise' && $U['role'] === 'resident') {
        $subject = trim($_POST['subject'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        if ($subject === '' || $desc === '') $err = 'Subject and description required.';
        else {
            $img = upload_file($_FILES['image'] ?? [], 'complaints');
            $stmt = db()->prepare('INSERT INTO complaints (user_id,subject,description,image,status) VALUES (?,?,?,?,"Complaint Raised")');
            $stmt->execute([$U['id'],$subject,$desc,$img]);
            $cid = db()->lastInsertId();
            $link = APP_URL . '/modules/complaints.php?id=' . $cid;
            send_mail(COMPLAINTS_INBOX, "[New Complaint #$cid] ".$subject,
                "<p>From <b>".e($U['owner_name'])."</b> (".e($U['tower']).'-'.e($U['house_number']).")</p>
                 <p><b>Subject:</b> ".e($subject)."</p>
                 <p><b>Description:</b><br>".nl2br(e($desc))."</p>
                 <p><a href='$link'>Open in portal</a></p>");
            if ($U['email']) send_mail($U['email'], "Complaint #$cid received", "<p>Your complaint has been logged. We'll update you on progress.</p>");
            $msg = 'Complaint raised successfully.';
        }
    }

    if ($action === 'update_status' && $U['role'] === 'admin') {
        $cid     = (int)$_POST['id'];
        $status  = $_POST['status'] ?? '';
        $reason  = trim($_POST['ignore_reason'] ?? '');
        $comment = trim($_POST['comment'] ?? '');
        $valid   = ['Complaint Raised','Work In Progress','Completed','Ignored'];
        if (!in_array($status,$valid,true)) $err='Bad status.';
        elseif ($status==='Ignored' && $reason==='') $err='Reason required to ignore.';
        else {
            db()->prepare('UPDATE complaints SET status=?, ignore_reason=? WHERE id=?')
                ->execute([$status, $status==='Ignored'?$reason:null, $cid]);
            $img = upload_file($_FILES['comment_image'] ?? [], 'complaints');
            if ($comment !== '' || $img) {
                db()->prepare('INSERT INTO complaint_comments (complaint_id,admin_id,comment,image,status_change) VALUES (?,?,?,?,?)')
                    ->execute([$cid,$U['id'],$comment ?: '(status changed)',$img,$status]);
            }
            // Email resident
            $stmt = db()->prepare('SELECT c.subject,u.email,u.owner_name FROM complaints c JOIN users u ON u.id=c.user_id WHERE c.id=?');
            $stmt->execute([$cid]);
            if ($r = $stmt->fetch()) {
                if ($r['email']) send_mail($r['email'],"Update on your complaint #$cid",
                    "<p>Hi ".e($r['owner_name']).",</p>
                     <p>Status of your complaint <b>".e($r['subject'])."</b> has been updated to <b>".e($status)."</b>.</p>"
                    .($status==='Ignored' ? '<p><b>Reason:</b> '.e($reason).'</p>' : '')
                    .($comment ? '<p><b>Admin note:</b> '.nl2br(e($comment)).'</p>' : ''));
            }
            $msg = "Complaint #$cid updated.";
        }
    }
}

// Detail view
$viewId = (int)($_GET['id'] ?? 0);
if ($viewId) {
    $sql = 'SELECT c.*, u.owner_name, u.tower, u.house_number, u.email FROM complaints c JOIN users u ON u.id=c.user_id WHERE c.id=?';
    $stmt = db()->prepare($sql); $stmt->execute([$viewId]);
    $comp = $stmt->fetch();
    if (!$comp || ($U['role']==='resident' && $comp['user_id'] != $U['id'])) {
        http_response_code(403); die('Not allowed.');
    }
    $cm = db()->prepare('SELECT cc.*, a.owner_name as admin_name FROM complaint_comments cc LEFT JOIN users a ON a.id=cc.admin_id WHERE complaint_id=? ORDER BY id ASC');
    $cm->execute([$viewId]);
    $comments = $cm->fetchAll();
}

// List
if ($U['role']==='admin') {
    $filterStatus = $_GET['status'] ?? '';
    $sql = 'SELECT c.*, u.owner_name, u.tower, u.house_number FROM complaints c JOIN users u ON u.id=c.user_id';
    $args = [];
    if ($filterStatus !== '') { $sql .= ' WHERE c.status=?'; $args[]=$filterStatus; }
    $sql .= ' ORDER BY c.id DESC';
    $stmt = db()->prepare($sql); $stmt->execute($args);
    $list = $stmt->fetchAll();

    $counts = db()->query("SELECT status, COUNT(*) c FROM complaints GROUP BY status")->fetchAll();
    $tiles  = ['Complaint Raised'=>0,'Work In Progress'=>0,'Completed'=>0,'Ignored'=>0];
    foreach ($counts as $r) $tiles[$r['status']] = (int)$r['c'];
} else {
    $stmt = db()->prepare('SELECT * FROM complaints WHERE user_id=? ORDER BY id DESC');
    $stmt->execute([$U['id']]);
    $list = $stmt->fetchAll();
}

layout_shell_start('Complaints','complaints');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<?php if ($U['role']==='admin'): ?>
  <div class="tiles">
    <div class="tile raised"><div class="big"><?= $tiles['Complaint Raised'] ?></div><div>Raised</div></div>
    <div class="tile progress"><div class="big"><?= $tiles['Work In Progress'] ?></div><div>In Progress</div></div>
    <div class="tile done"><div class="big"><?= $tiles['Completed'] ?></div><div>Completed</div></div>
    <div class="tile ignored"><div class="big"><?= $tiles['Ignored'] ?></div><div>Ignored</div></div>
  </div>
  <div class="chart-wrap"><canvas id="complaintsChart" height="120"></canvas></div>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      new Chart(document.getElementById('complaintsChart'), {
        type: 'doughnut',
        data: {
          labels: ['Raised','In Progress','Completed','Ignored'],
          datasets: [{ data: [<?= $tiles['Complaint Raised'] ?>,<?= $tiles['Work In Progress'] ?>,<?= $tiles['Completed'] ?>,<?= $tiles['Ignored'] ?>],
            backgroundColor: ['#f59e0b','#3b82f6','#10b981','#6b7280'] }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
      });
    });
  </script>
<?php endif; ?>

<?php if ($U['role']==='resident'): ?>
  <details class="panel" <?= empty($viewId)?'open':'' ?>>
    <summary><b>Raise a new complaint</b></summary>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="raise">
      <label>Subject</label>
      <input name="subject" required>
      <label>Description</label>
      <textarea name="description" rows="4" required></textarea>
      <label>Attach image (optional)</label>
      <input type="file" name="image" accept="image/*">
      <button class="btn primary">Submit Complaint</button>
    </form>
  </details>
<?php endif; ?>

<?php if ($U['role']==='admin'): ?>
  <form method="get" class="filterbar">
    <select name="status">
      <option value="">All Statuses</option>
      <?php foreach (['Complaint Raised','Work In Progress','Completed','Ignored'] as $s): ?>
        <option <?= ($_GET['status'] ?? '')===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn primary">Filter</button>
  </form>
<?php endif; ?>

<?php if ($viewId && isset($comp)): ?>
  <article class="panel">
    <h3><?= e($comp['subject']) ?> <span class="pill <?= strtolower(str_replace(' ','-',$comp['status'])) ?>"><?= e($comp['status']) ?></span></h3>
    <div class="muted small">By <?= e($comp['owner_name']) ?> (<?= e($comp['tower']) ?>-<?= e($comp['house_number']) ?>) · <?= e($comp['created_at']) ?></div>
    <?php if ($comp['image']): ?><img src="<?= e(upload_url($comp['image'])) ?>" class="ev-img"><?php endif; ?>
    <p><?= nl2br(e($comp['description'])) ?></p>
    <?php if ($comp['status']==='Ignored' && $comp['ignore_reason']): ?>
      <div class="alert err"><b>Ignored:</b> <?= e($comp['ignore_reason']) ?></div>
    <?php endif; ?>

    <h4>Comments & Updates</h4>
    <?php if (!$comments): ?><p class="muted">No comments yet.</p><?php endif; ?>
    <?php foreach ($comments as $c): ?>
      <div class="comment">
        <div class="muted small"><b><?= e($c['admin_name'] ?: 'Admin') ?></b> · <?= e($c['created_at']) ?> <?= $c['status_change']?'· → '.e($c['status_change']):'' ?></div>
        <p><?= nl2br(e($c['comment'])) ?></p>
        <?php if ($c['image']): ?><img src="<?= e(upload_url($c['image'])) ?>" class="ev-img"><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($U['role']==='admin'): ?>
      <form method="post" enctype="multipart/form-data" class="form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="id" value="<?= $comp['id'] ?>">
        <label>Change Status</label>
        <select name="status" onchange="document.getElementById('ir').style.display = this.value==='Ignored'?'block':'none'">
          <?php foreach (['Complaint Raised','Work In Progress','Completed','Ignored'] as $s): ?>
            <option <?= $comp['status']===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <div id="ir" style="display:<?= $comp['status']==='Ignored'?'block':'none' ?>">
          <label>Ignore reason</label>
          <input type="text" name="ignore_reason" value="<?= e($comp['ignore_reason'] ?? '') ?>">
        </div>
        <label>Admin comment (optional)</label>
        <textarea name="comment" rows="3"></textarea>
        <label>Attach image (optional)</label>
        <input type="file" name="comment_image" accept="image/*">
        <button class="btn primary">Update</button>
      </form>
    <?php endif; ?>
    <p><a href="complaints.php">&larr; Back to list</a></p>
  </article>
<?php else: ?>
  <?php if (!$list): ?>
    <div class="empty"><i class="fa fa-circle-exclamation"></i><p>No complaints found.</p></div>
  <?php endif; ?>
  <table class="tbl">
    <thead><tr><th>#</th><th>Subject</th><?php if ($U['role']==='admin'): ?><th>Resident</th><?php endif; ?><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr>
        <td>#<?= $c['id'] ?></td>
        <td><?= e($c['subject']) ?></td>
        <?php if ($U['role']==='admin'): ?><td><?= e($c['owner_name']) ?> (<?= e($c['tower']) ?>-<?= e($c['house_number']) ?>)</td><?php endif; ?>
        <td><span class="pill <?= strtolower(str_replace(' ','-',$c['status'])) ?>"><?= e($c['status']) ?></span></td>
        <td class="muted small"><?= e(date('d M Y', strtotime($c['created_at']))) ?></td>
        <td><a class="btn ghost" href="complaints.php?id=<?= $c['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php layout_shell_end(); ?>
