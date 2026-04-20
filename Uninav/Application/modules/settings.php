<?php
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$U = current_user();
$err = $msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check()) {
    $a = $_POST['action'] ?? '';

    // --- Events ---
    if ($a === 'event_add') {
        $img = upload_file($_FILES['image'] ?? [], 'events');
        db()->prepare('INSERT INTO events (title,description,event_date,event_time,location,image,created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$_POST['title'],$_POST['description'],$_POST['event_date'],$_POST['event_time'] ?: null,$_POST['location'],$img,$U['id']]);
        $msg='Event added.';
    }
    if ($a === 'event_del') {
        db()->prepare('DELETE FROM events WHERE id=?')->execute([(int)$_POST['id']]);
        $msg='Event deleted.';
    }
    if ($a === 'event_update') {
        $id = (int)$_POST['id'];
        $img = upload_file($_FILES['image'] ?? [], 'events');
        if ($img) db()->prepare('UPDATE events SET title=?,description=?,event_date=?,event_time=?,location=?,image=? WHERE id=?')
                       ->execute([$_POST['title'],$_POST['description'],$_POST['event_date'],$_POST['event_time'] ?: null,$_POST['location'],$img,$id]);
        else db()->prepare('UPDATE events SET title=?,description=?,event_date=?,event_time=?,location=? WHERE id=?')
                 ->execute([$_POST['title'],$_POST['description'],$_POST['event_date'],$_POST['event_time'] ?: null,$_POST['location'],$id]);
        $msg='Event updated.';
    }

    // --- Notices ---
    if ($a === 'notice_add') {
        $img = upload_file($_FILES['image'] ?? [], 'notices');
        db()->prepare('INSERT INTO notices (title,body,image,posted_on,created_by) VALUES (?,?,?,?,?)')
            ->execute([$_POST['title'],$_POST['body'],$img, $_POST['posted_on'] ?: date('Y-m-d'), $U['id']]);
        $msg='Notice added.';
    }
    if ($a === 'notice_del') {
        db()->prepare('DELETE FROM notices WHERE id=?')->execute([(int)$_POST['id']]);
        $msg='Notice deleted.';
    }
    if ($a === 'notice_update') {
        $id = (int)$_POST['id'];
        $img = upload_file($_FILES['image'] ?? [], 'notices');
        if ($img) db()->prepare('UPDATE notices SET title=?,body=?,posted_on=?,image=? WHERE id=?')
                       ->execute([$_POST['title'],$_POST['body'],$_POST['posted_on'],$img,$id]);
        else db()->prepare('UPDATE notices SET title=?,body=?,posted_on=? WHERE id=?')
                 ->execute([$_POST['title'],$_POST['body'],$_POST['posted_on'],$id]);
        $msg='Notice updated.';
    }

    // --- Finance ---
    if ($a === 'finance_month_save') {
        $m  = $_POST['month_year'];
        $ob = (float)$_POST['opening_balance'];
        $mi = (float)$_POST['monthly_income'];
        $ex = db()->prepare('SELECT id FROM finance_months WHERE month_year=?');
        $ex->execute([$m]);
        if ($ex->fetchColumn()) db()->prepare('UPDATE finance_months SET opening_balance=?, monthly_income=? WHERE month_year=?')->execute([$ob,$mi,$m]);
        else db()->prepare('INSERT INTO finance_months (month_year,opening_balance,monthly_income) VALUES (?,?,?)')->execute([$m,$ob,$mi]);
        $msg='Finance month saved.';
    }
    if ($a === 'finance_expense_add') {
        db()->prepare('INSERT INTO finance_expenses (month_year,expense_date,description,amount) VALUES (?,?,?,?)')
            ->execute([substr($_POST['expense_date'],0,7),$_POST['expense_date'],$_POST['description'],(float)$_POST['amount']]);
        $msg='Expense added.';
    }
    if ($a === 'finance_expense_del') {
        db()->prepare('DELETE FROM finance_expenses WHERE id=?')->execute([(int)$_POST['id']]);
        $msg='Expense deleted.';
    }

    // --- Vendors ---
    if ($a === 'vendor_add' || $a === 'vendor_update') {
        $photoRel = null;
        $up = upload_file($_FILES['photo'] ?? [], 'vendors'); if ($up) $photoRel = $up;
        if ($a === 'vendor_add') {
            db()->prepare('INSERT INTO vendors (category,name,photo,police_verified,contact_number,status,notes) VALUES (?,?,?,?,?,?,?)')
                ->execute([$_POST['category'],$_POST['name'],$photoRel, !empty($_POST['police_verified'])?1:0, $_POST['contact_number'], $_POST['status'], $_POST['notes']]);
            $vid = db()->lastInsertId();
        } else {
            $vid = (int)$_POST['id'];
            if ($photoRel) db()->prepare('UPDATE vendors SET category=?,name=?,photo=?,police_verified=?,contact_number=?,status=?,notes=? WHERE id=?')
                ->execute([$_POST['category'],$_POST['name'],$photoRel, !empty($_POST['police_verified'])?1:0, $_POST['contact_number'], $_POST['status'], $_POST['notes'], $vid]);
            else db()->prepare('UPDATE vendors SET category=?,name=?,police_verified=?,contact_number=?,status=?,notes=? WHERE id=?')
                ->execute([$_POST['category'],$_POST['name'], !empty($_POST['police_verified'])?1:0, $_POST['contact_number'], $_POST['status'], $_POST['notes'], $vid]);
        }
        // Refresh houses
        db()->prepare('DELETE FROM vendor_houses WHERE vendor_id=?')->execute([$vid]);
        $houses = explode(',', $_POST['houses'] ?? '');
        $ins = db()->prepare('INSERT INTO vendor_houses (vendor_id,tower,house_number) VALUES (?,?,?)');
        foreach ($houses as $h) {
            $h = trim($h);
            if ($h === '') continue;
            if (preg_match('/^([A-Ga-g])\s*-\s*(.+)$/', $h, $m2)) $ins->execute([$vid, strtoupper($m2[1]), trim($m2[2])]);
        }
        $msg='Vendor saved.';
    }
    if ($a === 'vendor_del') {
        $vid = (int)$_POST['id'];
        db()->prepare('DELETE FROM vendor_houses WHERE vendor_id=?')->execute([$vid]);
        db()->prepare('DELETE FROM vendors WHERE id=?')->execute([$vid]);
        $msg='Vendor removed.';
    }

    // --- Ads ---
    if ($a === 'ad_delete')    { db()->prepare('DELETE FROM advertisements WHERE id=?')->execute([(int)$_POST['id']]); $msg='Ad deleted.'; }
    if ($a === 'user_restrict'){ db()->prepare('UPDATE users SET ad_restricted=? WHERE id=?')->execute([(int)$_POST['restrict'],(int)$_POST['uid']]); $msg='Updated.'; }
}

// Loads
$events   = db()->query('SELECT * FROM events ORDER BY event_date DESC LIMIT 200')->fetchAll();
$notices  = db()->query('SELECT * FROM notices ORDER BY posted_on DESC LIMIT 200')->fetchAll();
$months   = db()->query('SELECT * FROM finance_months ORDER BY month_year DESC LIMIT 24')->fetchAll();
$selMonth = $_GET['fm'] ?? date('Y-m');
$curExps  = db()->prepare('SELECT * FROM finance_expenses WHERE month_year=? ORDER BY expense_date DESC'); $curExps->execute([$selMonth]); $curExps=$curExps->fetchAll();
$vendors  = db()->query('SELECT * FROM vendors ORDER BY category, name')->fetchAll();
$vhMap    = [];
foreach (db()->query('SELECT vendor_id,tower,house_number FROM vendor_houses')->fetchAll() as $r) {
    $vhMap[$r['vendor_id']][] = $r['tower'].'-'.$r['house_number'];
}
$ads     = db()->query('SELECT a.*, u.owner_name FROM advertisements a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC')->fetchAll();
$users   = db()->query("SELECT * FROM users WHERE role='resident' ORDER BY tower, house_number")->fetchAll();

layout_shell_start('Admin Settings','settings');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<div class="tabs">
  <a href="#events"   class="active" onclick="switchTab(this,'events')">Events</a>
  <a href="#notices"  onclick="switchTab(this,'notices')">Notices</a>
  <a href="#finance"  onclick="switchTab(this,'finance')">Finance</a>
  <a href="#vendors"  onclick="switchTab(this,'vendors')">3rd-Party Vendors</a>
  <a href="#ads"      onclick="switchTab(this,'ads')">Ads</a>
  <a href="#users"    onclick="switchTab(this,'users')">Users</a>
</div>

<!-- EVENTS -->
<section id="tab-events" class="tab-pane active">
  <h3>Add Event</h3>
  <form method="post" enctype="multipart/form-data" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="event_add">
    <div class="grid2">
      <div><label>Title</label><input name="title" required></div>
      <div><label>Date</label><input type="date" name="event_date" required></div>
      <div><label>Time</label><input type="time" name="event_time"></div>
      <div><label>Location</label><input name="location"></div>
    </div>
    <label>Description</label><textarea name="description" rows="2"></textarea>
    <label>Image</label><input type="file" name="image" accept="image/*">
    <button class="btn primary">Add Event</button>
  </form>

  <h3>All Events</h3>
  <table class="tbl">
    <thead><tr><th>Date</th><th>Title</th><th>Location</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($events as $e): ?>
    <tr>
      <td><?= e(date('d M Y', strtotime($e['event_date']))) ?><?= $e['event_time']?' '.e(substr($e['event_time'],0,5)):'' ?></td>
      <td><?= e($e['title']) ?></td><td class="muted small"><?= e($e['location']) ?></td>
      <td>
        <details><summary><i class="fa fa-pen"></i> Edit</summary>
          <form method="post" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="event_update">
            <input type="hidden" name="id" value="<?= $e['id'] ?>">
            <label>Title</label><input name="title" value="<?= e($e['title']) ?>">
            <label>Date</label><input type="date" name="event_date" value="<?= e($e['event_date']) ?>">
            <label>Time</label><input type="time" name="event_time" value="<?= e($e['event_time']) ?>">
            <label>Location</label><input name="location" value="<?= e($e['location']) ?>">
            <label>Description</label><textarea name="description"><?= e($e['description']) ?></textarea>
            <label>Replace image</label><input type="file" name="image" accept="image/*">
            <button class="btn primary">Save</button>
          </form>
        </details>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete event?')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="event_del">
          <input type="hidden" name="id" value="<?= $e['id'] ?>">
          <button class="btn ghost"><i class="fa fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- NOTICES -->
<section id="tab-notices" class="tab-pane">
  <h3>Add Notice</h3>
  <form method="post" enctype="multipart/form-data" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="notice_add">
    <div class="grid2">
      <div><label>Title</label><input name="title" required></div>
      <div><label>Posted On</label><input type="date" name="posted_on" value="<?= date('Y-m-d') ?>" required></div>
    </div>
    <label>Body</label><textarea name="body" rows="4" required></textarea>
    <label>Image</label><input type="file" name="image" accept="image/*">
    <button class="btn primary">Publish Notice</button>
  </form>

  <h3>All Notices</h3>
  <?php foreach ($notices as $n): ?>
    <div class="panel">
      <b><?= e($n['title']) ?></b> <span class="muted small">· <?= e(date('d M Y', strtotime($n['posted_on']))) ?></span>
      <details><summary>Edit</summary>
        <form method="post" enctype="multipart/form-data" class="form">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="notice_update">
          <input type="hidden" name="id" value="<?= $n['id'] ?>">
          <label>Title</label><input name="title" value="<?= e($n['title']) ?>">
          <label>Date</label><input type="date" name="posted_on" value="<?= e($n['posted_on']) ?>">
          <label>Body</label><textarea name="body" rows="3"><?= e($n['body']) ?></textarea>
          <label>Replace image</label><input type="file" name="image" accept="image/*">
          <button class="btn primary">Save</button>
        </form>
      </details>
      <form method="post" onsubmit="return confirm('Delete?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="notice_del">
        <input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button class="btn ghost"><i class="fa fa-trash"></i> Delete</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<!-- FINANCE -->
<section id="tab-finance" class="tab-pane">
  <h3>Monthly Setup</h3>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="finance_month_save">
    <div class="grid2">
      <div><label>Month</label><input type="month" name="month_year" value="<?= e($selMonth) ?>" required></div>
      <div><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="<?= e($months && $months[0]['month_year']===$selMonth ? $months[0]['opening_balance'] : '0') ?>"></div>
      <div><label>Monthly Income</label><input type="number" step="0.01" name="monthly_income" value="<?= e($months && $months[0]['month_year']===$selMonth ? $months[0]['monthly_income'] : '0') ?>"></div>
    </div>
    <button class="btn primary">Save Month</button>
  </form>

  <h3>Expenses (<?= e($selMonth) ?>)</h3>
  <form method="get" class="filterbar"><input type="month" name="fm" value="<?= e($selMonth) ?>"><button class="btn primary">Load</button></form>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="finance_expense_add">
    <div class="grid2">
      <div><label>Date</label><input type="date" name="expense_date" required value="<?= e($selMonth.'-01') ?>"></div>
      <div><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
    </div>
    <label>Description</label><input name="description" required>
    <button class="btn primary">Add Expense</button>
  </form>
  <table class="tbl">
    <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($curExps as $x): ?>
      <tr>
        <td><?= e(date('d M Y', strtotime($x['expense_date']))) ?></td>
        <td><?= e($x['description']) ?></td>
        <td>₹<?= number_format($x['amount'],2) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="finance_expense_del">
            <input type="hidden" name="id" value="<?= $x['id'] ?>">
            <button class="btn ghost"><i class="fa fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- VENDORS -->
<section id="tab-vendors" class="tab-pane">
  <h3>Add / Update Vendor</h3>
  <form method="post" enctype="multipart/form-data" class="form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="vendor_add">
    <div class="grid2">
      <div><label>Category</label>
        <select name="category">
          <option value="maid">Maid</option><option value="press">Press/Car Cleaner</option>
          <option value="security">Security</option><option value="housekeeping">House Keeping</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div><label>Name</label><input name="name" required></div>
      <div><label>Contact number</label><input name="contact_number"></div>
      <div><label>Status</label>
        <select name="status"><option>Available</option><option>DND</option></select>
      </div>
    </div>
    <label><input type="checkbox" name="police_verified" value="1"> Police Verified</label>
    <label>Houses (comma-separated, e.g. A-101,B-204)</label>
    <input name="houses" placeholder="A-101, B-205">
    <label>Notes</label><input name="notes">
    <label>Photo</label><input type="file" name="photo" accept="image/*">
    <button class="btn primary">Save Vendor</button>
  </form>

  <h3>All Vendors</h3>
  <table class="tbl">
    <thead><tr><th>Category</th><th>Name</th><th>Phone</th><th>Verified</th><th>Status</th><th>Houses</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($vendors as $v): ?>
      <tr>
        <td><?= e(ucfirst($v['category'])) ?></td>
        <td><?= e($v['name']) ?></td>
        <td><?= e($v['contact_number']) ?></td>
        <td><?= $v['police_verified']?'Yes':'No' ?></td>
        <td><?= e($v['status']) ?></td>
        <td class="muted small"><?= e(implode(', ', $vhMap[$v['id']] ?? [])) ?></td>
        <td>
          <details><summary>Edit</summary>
            <form method="post" enctype="multipart/form-data" class="form">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="vendor_update">
              <input type="hidden" name="id" value="<?= $v['id'] ?>">
              <label>Name</label><input name="name" value="<?= e($v['name']) ?>">
              <label>Category</label>
              <select name="category">
                <?php foreach (['maid','press','security','housekeeping','other'] as $c): ?>
                  <option value="<?= $c ?>" <?= $v['category']===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
              <label>Phone</label><input name="contact_number" value="<?= e($v['contact_number']) ?>">
              <label>Status</label>
              <select name="status"><option <?= $v['status']==='Available'?'selected':'' ?>>Available</option><option <?= $v['status']==='DND'?'selected':'' ?>>DND</option></select>
              <label><input type="checkbox" name="police_verified" value="1" <?= $v['police_verified']?'checked':'' ?>> Verified</label>
              <label>Houses</label><input name="houses" value="<?= e(implode(',', $vhMap[$v['id']] ?? [])) ?>">
              <label>Notes</label><input name="notes" value="<?= e($v['notes']) ?>">
              <label>Replace photo</label><input type="file" name="photo" accept="image/*">
              <button class="btn primary">Save</button>
            </form>
          </details>
          <form method="post" onsubmit="return confirm('Delete vendor?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="vendor_del">
            <input type="hidden" name="id" value="<?= $v['id'] ?>">
            <button class="btn ghost"><i class="fa fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- ADS -->
<section id="tab-ads" class="tab-pane">
  <h3>All Advertisements</h3>
  <table class="tbl">
    <thead><tr><th>Title</th><th>By</th><th>Price</th><th>Posted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ads as $a): ?>
      <tr>
        <td><?= e($a['title']) ?></td>
        <td><?= e($a['owner_name']) ?></td>
        <td><?= $a['price']!==null?'₹'.number_format($a['price'],2):'-' ?></td>
        <td class="muted small"><?= e($a['created_at']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="ad_delete">
            <input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn ghost"><i class="fa fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- USERS -->
<section id="tab-users" class="tab-pane">
  <h3>Resident Users</h3>
  <table class="tbl">
    <thead><tr><th>Name</th><th>House</th><th>Email</th><th>Verified</th><th>Ad Restricted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['owner_name']) ?> <span class="muted small">(<?= e($u['username']) ?>)</span></td>
        <td><?= e($u['tower']) ?>-<?= e($u['house_number']) ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= $u['email_verified']?'Yes':'No' ?></td>
        <td><?= $u['ad_restricted']?'Restricted':'Allowed' ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="user_restrict">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <input type="hidden" name="restrict" value="<?= $u['ad_restricted']?0:1 ?>">
            <button class="btn ghost"><?= $u['ad_restricted']?'Allow ads':'Restrict ads' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<script>
function switchTab(el, key) {
  document.querySelectorAll('.tabs a').forEach(a => a.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-'+key).classList.add('active');
  return false;
}
// Open tab from hash
document.addEventListener('DOMContentLoaded', () => {
  const h = location.hash.replace('#','');
  if (h) { const a = document.querySelector('.tabs a[href="#'+h+'"]'); if (a) a.click(); }
});
</script>

<?php layout_shell_end(); ?>
