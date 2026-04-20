<?php
require_once __DIR__ . '/../includes/layout.php';

$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12){ $month = 1;  $year++; }

$first = mktime(0,0,0,$month,1,$year);
$days  = (int)date('t', $first);
$startWeekday = (int)date('w', $first); // 0=Sun

$stmt = db()->prepare('SELECT * FROM events WHERE event_date BETWEEN ? AND ?');
$stmt->execute([sprintf('%04d-%02d-01',$year,$month), sprintf('%04d-%02d-%02d',$year,$month,$days)]);
$byDate = [];
foreach ($stmt->fetchAll() as $ev) { $byDate[(int)substr($ev['event_date'],8,2)][] = $ev; }

layout_shell_start('Event Calendar', 'calendar');
?>
<div class="cal-toolbar">
  <div class="nav-arrows">
    <a class="btn ghost" href="?y=<?= $month==1?$year-1:$year ?>&m=<?= $month==1?12:$month-1 ?>"><i class="fa fa-chevron-left"></i></a>
    <h2><?= date('F Y', $first) ?></h2>
    <a class="btn ghost" href="?y=<?= $month==12?$year+1:$year ?>&m=<?= $month==12?1:$month+1 ?>"><i class="fa fa-chevron-right"></i></a>
  </div>
  <a class="btn ghost" href="?y=<?= date('Y') ?>&m=<?= date('n') ?>">Today</a>
</div>

<div class="calendar">
  <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
    <div class="cal-head"><?= $d ?></div>
  <?php endforeach; ?>
  <?php for ($i=0;$i<$startWeekday;$i++) echo '<div class="cal-cell muted"></div>'; ?>
  <?php for ($d=1;$d<=$days;$d++):
    $events = $byDate[$d] ?? [];
    $today  = (date('Y-n-j') === "$year-$month-$d");
  ?>
    <div class="cal-cell <?= $today?'today':'' ?> <?= $events?'has-events':'' ?>"
         onclick='openEvents(<?= json_encode([
            "date" => sprintf("%04d-%02d-%02d",$year,$month,$d),
            "events" => $events
         ]) ?>)'>
      <div class="date"><?= $d ?></div>
      <?php foreach (array_slice($events,0,2) as $ev): ?>
        <div class="chip"><?= e(mb_strimwidth($ev['title'],0,18,'…')) ?></div>
      <?php endforeach; ?>
      <?php if (count($events)>2): ?><div class="muted small">+<?= count($events)-2 ?> more</div><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<!-- Modal -->
<div id="ev-modal" class="modal">
  <div class="modal-card">
    <div class="modal-head"><h3 id="ev-modal-title">Events</h3><button class="x" onclick="document.getElementById('ev-modal').classList.remove('open')">×</button></div>
    <div class="modal-body" id="ev-modal-body"></div>
  </div>
</div>

<script>
function openEvents(data) {
  const modal = document.getElementById('ev-modal');
  document.getElementById('ev-modal-title').textContent = 'Events on ' + data.date;
  const body = document.getElementById('ev-modal-body');
  if (!data.events.length) { body.innerHTML = '<p class="muted">No events.</p>'; }
  else {
    body.innerHTML = data.events.map(ev => `
      <div class="event-item">
        ${ev.image ? `<img src="<?= UPLOAD_URL ?>/${ev.image}" class="ev-img">` : ''}
        <h4>${escapeHtml(ev.title)}</h4>
        <div class="muted small">${ev.event_time||''} ${ev.location ? ' · '+escapeHtml(ev.location) : ''}</div>
        <p>${escapeHtml(ev.description||'')}</p>
      </div>`).join('');
  }
  modal.classList.add('open');
}
function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
</script>
<?php layout_shell_end(); ?>
