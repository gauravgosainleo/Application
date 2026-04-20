<?php
require_once __DIR__ . '/../includes/layout.php';
$U = current_user();
$err = $msg = '';

// Guests have no house — show a friendly message.
if (empty($U['tower']) || empty($U['house_number'])) {
    layout_shell_start('My Home Mates','homemates');
    echo '<div class="empty"><i class="fa fa-house-user"></i><p>This view is for residents linked to a tower + house.</p></div>';
    layout_shell_end();
    return;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check() && $U['role']==='resident') {
    $a = $_POST['action'] ?? '';
    if ($a === 'flag') {
        $target = (int)$_POST['user_id'];
        $reason = trim($_POST['reason'] ?? '');

        // Verify target is an active homemate
        $st = db()->prepare("SELECT id FROM users WHERE id=? AND tower=? AND house_number=? AND status='active' AND id<>?");
        $st->execute([$target, $U['tower'], $U['house_number'], $U['id']]);
        if (!$st->fetchColumn()) $err = 'Invalid selection.';
        else {
            // Prevent duplicates from same flagger
            $dup = db()->prepare("SELECT id FROM user_flags WHERE flagged_user_id=? AND flagged_by=? AND status='open'");
            $dup->execute([$target, $U['id']]);
            if ($dup->fetchColumn()) $err = 'You have already flagged this user. Admin will review.';
            else {
                db()->prepare('INSERT INTO user_flags (flagged_user_id,flagged_by,reason) VALUES (?,?,?)')
                    ->execute([$target, $U['id'], $reason ?: null]);
                // Notify admin
                $info = db()->prepare('SELECT u.owner_name as tname, u.tower, u.house_number, b.owner_name as bname FROM users u, users b WHERE u.id=? AND b.id=?');
                $info->execute([$target, $U['id']]); $r = $info->fetch();
                if ($r) send_mail(COMPLAINTS_INBOX, 'Resident flagged a home-mate',
                    "<p><b>".e($r['bname'])."</b> flagged <b>".e($r['tname'])."</b> from ".e($r['tower']).'-'.e($r['house_number'])." as not a family member.</p>"
                    .($reason ? '<p>Reason: '.nl2br(e($reason)).'</p>' : '')
                    ."<p>Review in admin Settings → Flagged Users.</p>");
                $msg = 'Thanks, the admin will review this flag.';
            }
        }
    }
}

// Homemates list
$hm = db()->prepare("SELECT u.*,
        (SELECT COUNT(*) FROM user_flags WHERE flagged_user_id=u.id AND flagged_by=? AND status='open') AS already_flagged
        FROM users u
        WHERE u.tower=? AND u.house_number=? AND u.status='active' AND u.id<>?
        ORDER BY u.owner_name");
$hm->execute([$U['id'], $U['tower'], $U['house_number'], $U['id']]);
$mates = $hm->fetchAll();

layout_shell_start('My Home Mates','homemates');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<p class="muted">Residents registered for <b><?= e($U['tower']) ?>-<?= e($U['house_number']) ?></b>. If someone here isn't a family member, flag them and the admin will take action.</p>

<?php if (!$mates): ?>
  <div class="empty"><i class="fa fa-house-user"></i><p>No other residents are registered under your house yet.</p></div>
<?php endif; ?>

<div class="grid-cards">
  <?php foreach ($mates as $m): ?>
    <div class="vendor-card">
      <div class="v-head">
        <?php if ($m['photo']): ?>
          <img src="<?= e(upload_url($m['photo'])) ?>" class="avatar">
        <?php else: ?>
          <div class="avatar-initials"><?= e(strtoupper(substr($m['owner_name'] ?: $m['username'],0,1))) ?></div>
        <?php endif; ?>
        <div>
          <b><?= e($m['owner_name']) ?></b>
          <div class="muted small">@<?= e($m['username']) ?></div>
        </div>
      </div>
      <?php if ($m['phone']): ?><div class="muted small"><i class="fa fa-phone"></i> <?= e($m['phone']) ?></div><?php endif; ?>
      <?php if ($m['email']): ?><div class="muted small"><i class="fa fa-envelope"></i> <?= e($m['email']) ?></div><?php endif; ?>

      <?php if ($U['role']==='resident'): ?>
        <?php if ($m['already_flagged']): ?>
          <div class="pill ignored">Flagged — under review</div>
        <?php else: ?>
          <button class="btn ghost" onclick="document.getElementById('flag-<?= $m['id'] ?>').classList.toggle('open')">
            <i class="fa fa-flag"></i> Not a family member
          </button>
          <div class="modal" id="flag-<?= $m['id'] ?>">
            <div class="modal-card">
              <div class="modal-head">
                <h3>Flag <?= e($m['owner_name']) ?></h3>
                <button class="x" onclick="document.getElementById('flag-<?= $m['id'] ?>').classList.remove('open')">×</button>
              </div>
              <div class="modal-body">
                <form method="post" class="form">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="flag">
                  <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                  <label>Reason (optional)</label>
                  <textarea name="reason" rows="3" placeholder="e.g. This person is not known to me."></textarea>
                  <button class="btn primary"><i class="fa fa-flag"></i> Submit Flag</button>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php layout_shell_end(); ?>
