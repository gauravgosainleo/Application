<?php
require_once __DIR__ . '/../includes/layout.php';
$U = current_user();
$err = $msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check()) {
    $a = $_POST['action'] ?? '';
    if ($a === 'add' && in_array($U['role'], ['resident','admin'], true)) {
        if (!empty($U['ad_restricted'])) $err = 'You are restricted from posting ads.';
        else {
            $title = trim($_POST['title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $name  = trim($_POST['contact_name'] ?? '');
            $phone = trim($_POST['contact_phone'] ?? '');
            $price = $_POST['price'] !== '' ? (float)$_POST['price'] : null;
            if ($title==='' || $desc==='' || $name==='' || $phone==='') $err='All required fields must be filled.';
            else {
                $img = upload_file($_FILES['photo'] ?? [], 'ads');
                db()->prepare('INSERT INTO advertisements (user_id,title,description,photo,price,contact_name,contact_phone) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$U['id'],$title,$desc,$img,$price,$name,$phone]);
                $msg='Advertisement posted.';
            }
        }
    }
    if ($a === 'delete' && is_admin()) {
        db()->prepare('DELETE FROM advertisements WHERE id=?')->execute([(int)$_POST['id']]);
        $msg='Ad deleted.';
    }
    if ($a === 'delete_own' && $U['role']==='resident') {
        db()->prepare('DELETE FROM advertisements WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $U['id']]);
        $msg='Ad deleted.';
    }
}

$stmt = db()->query("SELECT a.*, u.owner_name, u.tower, u.house_number FROM advertisements a JOIN users u ON u.id=a.user_id WHERE a.status='active' ORDER BY a.id DESC");
$ads = $stmt->fetchAll();

layout_shell_start('Society Advertisements','ads');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<?php if (in_array($U['role'],['resident','admin'],true) && empty($U['ad_restricted'])): ?>
  <details class="panel">
    <summary><b>+ Add Advertisement</b></summary>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <label>Title</label><input name="title" required>
      <label>Description</label><textarea name="description" rows="3" required></textarea>
      <div class="grid2">
        <div><label>Price (optional)</label><input type="number" step="0.01" name="price"></div>
        <div><label>Photo</label><input type="file" name="photo" accept="image/*"></div>
      </div>
      <div class="grid2">
        <div><label>Contact Name</label><input name="contact_name" value="<?= e($U['owner_name']) ?>" required></div>
        <div><label>Contact Phone</label><input name="contact_phone" required></div>
      </div>
      <button class="btn primary">Publish</button>
    </form>
  </details>
<?php elseif (!empty($U['ad_restricted'])): ?>
  <div class="alert err">You are currently restricted from posting new advertisements.</div>
<?php endif; ?>

<?php if (!$ads): ?><div class="empty"><i class="fa fa-rectangle-ad"></i><p>No ads yet.</p></div><?php endif; ?>
<div class="grid-cards">
  <?php foreach ($ads as $a): ?>
    <div class="ad-card">
      <?php if ($a['photo']): ?><img src="<?= e(upload_url($a['photo'])) ?>"><?php endif; ?>
      <h4><?= e($a['title']) ?> <?php if ($a['price']!==null): ?><span class="pill done">₹<?= number_format($a['price'],2) ?></span><?php endif; ?></h4>
      <p><?= nl2br(e($a['description'])) ?></p>
      <div class="muted small">Contact <b><?= e($a['contact_name']) ?></b> · <?= e($a['contact_phone']) ?></div>
      <div class="muted small">Posted by <?= e($a['owner_name']) ?> (<?= e($a['tower']) ?>-<?= e($a['house_number']) ?>)</div>
      <?php if (is_admin() || (int)$a['user_id'] === (int)$U['id']): ?>
        <form method="post" onsubmit="return confirm('Delete this ad?')" style="margin-top:6px;">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="<?= is_admin()?'delete':'delete_own' ?>">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button class="btn ghost"><i class="fa fa-trash"></i> Delete</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_shell_end(); ?>
