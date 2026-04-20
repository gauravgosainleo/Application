<?php
require_once __DIR__ . '/../includes/layout.php';

$month = $_GET['m'] ?? date('Y-m');
$stmt = db()->prepare('SELECT * FROM finance_months WHERE month_year=?');
$stmt->execute([$month]);
$fm = $stmt->fetch();

$exp = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE month_year=?');
$exp->execute([$month]);
$totalExp = (float)$exp->fetchColumn();

$opening = (float)($fm['opening_balance'] ?? 0);
$income  = (float)($fm['monthly_income']  ?? 0);
$balance = $opening + $income - $totalExp;

$expList = db()->prepare('SELECT * FROM finance_expenses WHERE month_year=? ORDER BY expense_date DESC, id DESC');
$expList->execute([$month]);
$expenses = $expList->fetchAll();

$showAll = !empty($_GET['all']);

layout_shell_start('Society Finance & Expenses','finance');
?>
<form method="get" class="filterbar">
  <label>Month</label>
  <input type="month" name="m" value="<?= e($month) ?>">
  <button class="btn primary">View</button>
</form>

<div class="tiles">
  <div class="tile raised"><div>Opening Balance</div><div class="big">₹<?= number_format($opening,2) ?></div></div>
  <div class="tile progress"><div>Monthly Income</div><div class="big">₹<?= number_format($income,2) ?></div></div>
  <div class="tile ignored"><div>Monthly Expenses</div><div class="big">₹<?= number_format($totalExp,2) ?></div></div>
  <div class="tile done"><div>Monthly Balance</div><div class="big">₹<?= number_format($balance,2) ?></div></div>
</div>

<a class="btn primary" href="?m=<?= e($month) ?>&all=1">Show All Expenses</a>

<?php if ($showAll): ?>
  <h3>Expenses for <?= e(date('F Y', strtotime($month.'-01'))) ?></h3>
  <?php if (!$expenses): ?><p class="muted">No expenses recorded.</p><?php endif; ?>
  <table class="tbl">
    <thead><tr><th>Date</th><th>Description</th><th>Amount</th></tr></thead>
    <tbody>
    <?php foreach ($expenses as $x): ?>
      <tr><td><?= e(date('d M Y', strtotime($x['expense_date']))) ?></td>
          <td><?= e($x['description']) ?></td>
          <td>₹<?= number_format($x['amount'],2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if (is_admin()): ?>
  <p class="muted">Admins: manage months and expenses under <a href="settings.php#finance">Settings → Finance</a>.</p>
<?php endif; ?>

<?php layout_shell_end(); ?>
