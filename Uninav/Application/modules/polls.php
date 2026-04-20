<?php
require_once __DIR__ . '/../includes/layout.php';
$U = current_user();
$err = $msg = '';

// Create poll (admin)
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_check()) {
    $a = $_POST['action'] ?? '';
    if ($a==='create' && is_admin()) {
        $q    = trim($_POST['question'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $opts = array_values(array_filter(array_map('trim', $_POST['options'] ?? []), fn($s)=>$s!==''));
        if ($q==='' || count($opts) < 2) $err='Need a question and at least 2 options.';
        else {
            db()->prepare('INSERT INTO polls (question,description,created_by,active) VALUES (?,?,?,1)')
                ->execute([$q,$desc,$U['id']]);
            $pid = db()->lastInsertId();
            $ins = db()->prepare('INSERT INTO poll_options (poll_id,option_text) VALUES (?,?)');
            foreach ($opts as $o) $ins->execute([$pid,$o]);
            $msg='Poll published.';
        }
    }
    if ($a==='vote' && in_array($U['role'], ['resident','admin'], true)) {
        $pid = (int)$_POST['poll_id'];
        $oid = (int)$_POST['option_id'];
        try {
            db()->prepare('INSERT INTO poll_votes (poll_id,option_id,user_id) VALUES (?,?,?)')
                ->execute([$pid,$oid,$U['id']]);
            $msg='Your vote has been recorded.';
        } catch (Exception $ex) { $err='You have already voted in this poll.'; }
    }
    if ($a==='toggle' && is_admin()) {
        db()->prepare('UPDATE polls SET active = 1 - active WHERE id=?')->execute([(int)$_POST['id']]);
    }
    if ($a==='delete' && is_admin()) {
        $pid = (int)$_POST['id'];
        db()->prepare('DELETE FROM poll_votes WHERE poll_id=?')->execute([$pid]);
        db()->prepare('DELETE FROM poll_options WHERE poll_id=?')->execute([$pid]);
        db()->prepare('DELETE FROM polls WHERE id=?')->execute([$pid]);
        $msg='Poll deleted.';
    }
    if ($a==='email_poll' && is_admin()) {
        $pid = (int)$_POST['id'];
        $p = db()->prepare('SELECT * FROM polls WHERE id=?'); $p->execute([$pid]); $poll=$p->fetch();
        $link = APP_URL . '/modules/polls.php';
        $subs = db()->query("SELECT email,owner_name FROM users WHERE role='resident' AND email IS NOT NULL AND email<>''")->fetchAll();
        foreach ($subs as $r) {
            send_mail($r['email'], "New Poll: ".$poll['question'],
                "<p>Hi ".e($r['owner_name']).",</p><p>A new poll has been posted: <b>".e($poll['question'])."</b></p><p><a href='$link'>Vote now</a></p>");
        }
        $msg = 'Emails sent to residents.';
    }
}

$polls = db()->query('SELECT * FROM polls ORDER BY id DESC')->fetchAll();

layout_shell_start('Society Polls','polls');
?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>

<?php if (is_admin()): ?>
  <details class="panel">
    <summary><b>+ Create Poll</b></summary>
    <form method="post" class="form" id="poll-form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="create">
      <label>Question</label>
      <input name="question" required>
      <label>Description (optional)</label>
      <textarea name="description" rows="2"></textarea>
      <label>Options</label>
      <div id="opts">
        <input name="options[]" placeholder="Option 1" required>
        <input name="options[]" placeholder="Option 2" required>
      </div>
      <button type="button" class="btn ghost" onclick="
        const el=document.createElement('input'); el.name='options[]'; el.placeholder='Option '+ (document.querySelectorAll('#opts input').length+1); document.getElementById('opts').appendChild(el);
      ">+ Add option</button>
      <button class="btn primary" type="submit">Publish Poll</button>
    </form>
  </details>
<?php endif; ?>

<?php foreach ($polls as $p):
    $opts = db()->prepare('SELECT * FROM poll_options WHERE poll_id=?'); $opts->execute([$p['id']]); $options = $opts->fetchAll();
    $counts = db()->prepare('SELECT option_id, COUNT(*) c FROM poll_votes WHERE poll_id=? GROUP BY option_id'); $counts->execute([$p['id']]);
    $tally = []; foreach ($counts->fetchAll() as $r) $tally[$r['option_id']] = (int)$r['c'];
    $total = array_sum($tally);
    $voted = false;
    if (in_array($U['role'], ['resident','admin'], true)) {
        $v = db()->prepare('SELECT 1 FROM poll_votes WHERE poll_id=? AND user_id=?');
        $v->execute([$p['id'], $U['id']]);
        $voted = (bool)$v->fetchColumn();
    } elseif ($U['role']==='guest') { $voted = true; }
?>
  <article class="panel">
    <header>
      <h3><?= e($p['question']) ?>
        <?php if (!$p['active']): ?><span class="pill ignored">Closed</span><?php endif; ?>
      </h3>
      <?php if ($p['description']): ?><p class="muted small"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
    </header>

    <?php if (!$voted && $p['active']): ?>
      <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="vote">
        <input type="hidden" name="poll_id" value="<?= $p['id'] ?>">
        <?php foreach ($options as $o): ?>
          <label class="radio"><input type="radio" name="option_id" value="<?= $o['id'] ?>" required> <?= e($o['option_text']) ?></label>
        <?php endforeach; ?>
        <button class="btn primary">Submit Vote</button>
      </form>
    <?php else: ?>
      <div class="results">
      <?php foreach ($options as $o): $c = $tally[$o['id']] ?? 0; $pct = $total ? round($c*100/$total) : 0; ?>
        <div class="result-row">
          <div class="result-label"><?= e($o['option_text']) ?> <span class="muted small">(<?= $c ?> · <?= $pct ?>%)</span></div>
          <div class="bar"><div class="fill" style="width:<?= $pct ?>%"></div></div>
        </div>
      <?php endforeach; ?>
      <div class="muted small">Total votes: <?= $total ?></div>
      </div>
    <?php endif; ?>

    <?php if (is_admin()): ?>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost" href="<?= APP_URL ?>/api/poll_pdf.php?id=<?= $p['id'] ?>"><i class="fa fa-file-pdf"></i> Download PDF</a>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="email_poll"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn ghost"><i class="fa fa-envelope"></i> Email Poll</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn ghost"><?= $p['active']?'Close':'Reopen' ?></button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this poll?')"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn ghost"><i class="fa fa-trash"></i> Delete</button></form>
      </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>

<?php if (!$polls): ?><div class="empty"><i class="fa fa-square-poll-vertical"></i><p>No polls yet.</p></div><?php endif; ?>
<?php layout_shell_end(); ?>
