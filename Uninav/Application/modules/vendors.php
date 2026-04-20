<?php
require_once __DIR__ . '/../includes/layout.php';
$U = current_user();

$cats = [
    'maid'         => 'Maids',
    'press'        => 'Press & Car Cleaners',
    'security'     => 'Security',
    'housekeeping' => 'House Keeping',
    'other'        => 'Other Vendors',
];
$cat = $_GET['cat'] ?? 'maid';
if (!isset($cats[$cat])) $cat = 'maid';

$err = $msg = '';

// Raise complaint from vendor panel (residents only)
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check() && ($_POST['action'] ?? '')==='vendor_complaint' && $U['role']==='resident') {
    $vid = (int)$_POST['vendor_id'];
    $desc = trim($_POST['description'] ?? '');
    if ($desc==='') $err = 'Description required.';
    else {
        $stmt = db()->prepare('SELECT name, category FROM vendors WHERE id=?');
        $stmt->execute([$vid]);
        $v = $stmt->fetch();
        if (!$v) $err='Invalid vendor.';
        else {
            $subject = "Complaint about ".$cats[$v['category']]." - ".$v['name'];
            $img = upload_file($_FILES['image'] ?? [], 'complaints');
            db()->prepare('INSERT INTO complaints (user_id,subject,description,image,related_type,related_id) VALUES (?,?,?,?,?,?)')
                ->execute([$U['id'],$subject,$desc,$img,'vendor',$vid]);
            $cid = db()->lastInsertId();
            send_mail(COMPLAINTS_INBOX, "[Vendor Complaint #$cid] ".$subject,
                "<p>From ".e($U['owner_name'])." (".e($U['tower']).'-'.e($U['house_number']).")</p><p>".nl2br(e($desc))."</p>");
            $msg = 'Complaint raised for this vendor.';
        }
    }
}

$all = db()->prepare('SELECT * FROM vendors WHERE category=? ORDER BY name');
$all->execute([$cat]);
$rows = $all->fetchAll();

// Load house mapping for each vendor
$ids = array_column($rows, 'id');
$houseMap = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $hs = db()->prepare("SELECT vendor_id, tower, house_number FROM vendor_houses WHERE vendor_id IN ($in)");
    $hs->execute($ids);
    foreach ($hs->fetchAll() as $h) $houseMap[$h['vendor_id']][] = $h['tower'].'-'.$h['house_number'];
}

// Determine "Own" vendors for residents
$ownIds = [];
if ($U['role']==='resident') {
    foreach ($rows as $v) {
        foreach ($houseMap[$v['id']] ?? [] as $h) {
            if ($h === $U['tower'].'-'.$U['house_number']) { $ownIds[] = $v['id']; break; }
        }
    }
}

layout_shell_start('Third-party Vendors','vendors');
?>
<div class="tabs">
  <?php foreach ($cats as $k=>$label): ?>
    <a href="?cat=<?= $k ?>" class="<?= $cat===$k?'active':'' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<?php if ($U['role']==='resident' && $ownIds): ?>
  <h3>Your Own <?= e($cats[$cat]) ?></h3>
  <div class="grid-cards">
    <?php foreach ($rows as $v): if (!in_array($v['id'],$ownIds,true)) continue; ?>
      <?= render_vendor_card($v, $houseMap[$v['id']] ?? [], true, $U) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3>All <?= e($cats[$cat]) ?></h3>
<?php if (!$rows): ?><div class="empty"><i class="fa fa-people-carry-box"></i><p>No entries yet.</p></div><?php endif; ?>
<div class="grid-cards">
  <?php foreach ($rows as $v): ?>
    <?= render_vendor_card($v, $houseMap[$v['id']] ?? [], false, $U) ?>
  <?php endforeach; ?>
</div>

<?php if (is_admin()): ?>
  <p class="muted">Admins: manage vendors under <a href="settings.php#vendors">Settings → 3rd Party Vendor</a>.</p>
<?php endif; ?>

<?php
function render_vendor_card($v, $houses, $isOwn, $U) {
    ob_start(); ?>
    <div class="vendor-card <?= $isOwn?'own':'' ?>">
      <div class="v-head">
        <?php if ($v['photo']): ?>
          <img src="<?= e(upload_url($v['photo'])) ?>" class="avatar">
        <?php else: ?>
          <div class="avatar-initials"><?= e(strtoupper(substr($v['name'],0,1))) ?></div>
        <?php endif; ?>
        <div>
          <b><?= e($v['name']) ?></b>
          <div class="muted small">
            <?= $v['police_verified'] ? '<span class="pill done"><i class="fa fa-shield"></i> Verified</span>' : '<span class="pill ignored">Unverified</span>' ?>
            <span class="pill <?= $v['status']==='Available'?'done':'ignored' ?>"><?= e($v['status']) ?></span>
          </div>
        </div>
      </div>
      <div class="muted small"><i class="fa fa-phone"></i> <?= e($v['contact_number']) ?></div>
      <div class="muted small"><i class="fa fa-house"></i> <?= e(implode(', ', $houses)) ?></div>
      <?php if ($v['notes']): ?><div class="muted small"><?= e($v['notes']) ?></div><?php endif; ?>
      <?php if ($U['role']==='resident'): ?>
        <button class="btn ghost" onclick="document.getElementById('vc-<?= $v['id'] ?>').classList.toggle('open')">
          <i class="fa fa-circle-exclamation"></i> Raise Complaint
        </button>
        <div class="modal" id="vc-<?= $v['id'] ?>">
          <div class="modal-card">
            <div class="modal-head"><h3>Complaint: <?= e($v['name']) ?></h3>
              <button class="x" onclick="document.getElementById('vc-<?= $v['id'] ?>').classList.remove('open')">×</button></div>
            <div class="modal-body">
              <form method="post" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="vendor_complaint">
                <input type="hidden" name="vendor_id" value="<?= $v['id'] ?>">
                <label>Describe the issue</label>
                <textarea name="description" rows="3" required></textarea>
                <label>Attach image (optional)</label>
                <input type="file" name="image" accept="image/*">
                <button class="btn primary">Submit</button>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
layout_shell_end(); ?>
