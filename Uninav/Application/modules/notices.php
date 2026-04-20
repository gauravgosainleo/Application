<?php
require_once __DIR__ . '/../includes/layout.php';

$q    = trim($_GET['q'] ?? '');
$date = $_GET['date'] ?? '';
$sql  = 'SELECT * FROM notices WHERE 1=1';
$args = [];
if ($q   !== '') { $sql .= ' AND (title LIKE ? OR body LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
if ($date!== '') { $sql .= ' AND posted_on = ?'; $args[] = $date; }
$sql .= ' ORDER BY posted_on DESC, id DESC';
$stmt = db()->prepare($sql); $stmt->execute($args);
$rows = $stmt->fetchAll();

layout_shell_start('Society Notices','notices');
?>
<form method="get" class="filterbar">
  <input type="search" name="q" placeholder="Search notices…" value="<?= e($q) ?>">
  <input type="date" name="date" value="<?= e($date) ?>">
  <button class="btn primary"><i class="fa fa-filter"></i> Filter</button>
  <?php if ($q||$date): ?><a class="btn ghost" href="notices.php">Clear</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty"><i class="fa fa-bullhorn"></i><p>No notices yet.</p></div>
<?php endif; ?>

<div class="notices">
<?php foreach ($rows as $n): ?>
  <article class="notice-card">
    <header>
      <h3><?= e($n['title']) ?></h3>
      <span class="muted small"><?= e(date('d M Y', strtotime($n['posted_on']))) ?></span>
    </header>
    <?php if ($n['image']): ?><img src="<?= e(upload_url($n['image'])) ?>" alt=""><?php endif; ?>
    <p><?= nl2br(e($n['body'])) ?></p>
  </article>
<?php endforeach; ?>
</div>
<?php layout_shell_end(); ?>
