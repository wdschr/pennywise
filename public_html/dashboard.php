<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);

$monthWasDefaulted = !isset($_GET['month']) || !preg_match('/^\d{4}-\d{2}$/', $_GET['month']);
$month = $_GET['month'] ?? Recurring::defaultBudgetMonth();
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = Recurring::defaultBudgetMonth();
}
$defaultedToNextMonth = $monthWasDefaulted && $month !== date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Carry forward recurring items (subscriptions, income) for the month
// being viewed, as long as it isn't in the past - so navigating ahead to
// next month's budget populates it, without rewriting history on old
// months.
if ($ctx['is_self'] && $month >= date('Y-m')) {
    $carried = Recurring::generateForMonth((int) $currentUser['id'], $monthStart);
    if ($carried['count'] > 0) {
        Flash::success('Carried forward ' . $carried['count'] . ' recurring item(s) for ' . Format::monthLabel($month) . ': ' . implode(', ', $carried['items']));
    }
}

$summary = Transactions::summary($ctx['owner_id'], $monthStart, $monthEnd, $ctx['category_ids']);
$recent = Transactions::query($ctx['owner_id'], [
    'from' => $monthStart,
    'to' => $monthEnd,
    'category_ids' => $ctx['category_ids'],
], 10);

$savingsBalance = $ctx['category_ids'] === null ? Savings::totalBalanceForOwner($ctx['owner_id']) : null;

$expenseByCategory = array_values(array_filter($summary['by_category'], fn($c) => $c['kind'] === 'expense'));

render_header('Dashboard', 'dashboard', $currentUser, $ctx);
?>
<div class="topbar">
  <h1>Dashboard</h1>
  <form method="get" style="display:flex;gap:10px;align-items:center;">
    <?php if (!$ctx['is_self']): ?><input type="hidden" name="owner" value="<?= (int) $ctx['owner_id'] ?>"><?php endif; ?>
    <input type="month" name="month" value="<?= Format::e($month) ?>" data-auto-submit>
  </form>
</div>
<?php if ($defaultedToNextMonth): ?>
  <p class="hint">It's the 20th or later, so you're viewing <strong><?= Format::e(Format::monthLabel($month)) ?></strong> by default - the budget it funds. <a href="?month=<?= Format::e(date('Y-m')) ?><?= $ctx['is_self'] ? '' : '&owner=' . (int) $ctx['owner_id'] ?>">View <?= Format::e(Format::monthLabel(date('Y-m'))) ?> instead</a></p>
<?php endif; ?>

<div class="card-grid">
  <div class="card stat-card positive">
    <div class="stat-label">Income</div>
    <div class="stat-value"><?= Format::money($summary['income']) ?></div>
  </div>
  <div class="card stat-card negative">
    <div class="stat-label">Expenses</div>
    <div class="stat-value"><?= Format::money($summary['expense']) ?></div>
  </div>
  <div class="card stat-card <?= $summary['net'] >= 0 ? 'positive' : 'negative' ?>">
    <div class="stat-label">Net</div>
    <div class="stat-value"><?= Format::money($summary['net']) ?></div>
  </div>
  <?php if ($savingsBalance !== null): ?>
  <div class="card stat-card positive">
    <div class="stat-label">Total savings</div>
    <div class="stat-value"><?= Format::money($savingsBalance) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="card-grid" style="grid-template-columns: 1.2fr 1fr;">
  <div class="card">
    <h2>Recent transactions this month</h2>
    <?php if ($recent === []): ?>
      <p class="hint">No transactions logged for <?= Format::e(Format::monthLabel($month)) ?> yet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $t): ?>
        <tr>
          <td><?= Format::date($t['txn_date']) ?></td>
          <td><span class="badge" style="border-color:<?= Format::e($t['category_color'] ?? '') ?>"><?= Format::e($t['category_name']) ?></span></td>
          <td><?= Format::e($t['description']) ?></td>
          <td style="color:<?= $t['kind'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>">
            <?= $t['kind'] === 'income' ? '+' : '-' ?><?= Format::money($t['amount']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:12px;"><a href="/transactions.php?month=<?= Format::e($month) ?><?= $ctx['is_self'] ? '' : '&owner=' . (int) $ctx['owner_id'] ?>">View all transactions &rarr;</a></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Spending by category</h2>
    <?php if ($expenseByCategory === []): ?>
      <p class="hint">No expenses logged yet this month.</p>
    <?php else: ?>
      <canvas id="category-chart" height="220" data-chart="pie" data-source="category-chart-data"></canvas>
      <script type="application/json" id="category-chart-data"><?= json_encode([
          'labels' => array_column($expenseByCategory, 'name'),
          'values' => array_map('floatval', array_column($expenseByCategory, 'total')),
          'colors' => array_column($expenseByCategory, 'color'),
      ]) ?></script>
    <?php endif; ?>
  </div>
</div>

<?php if ($ctx['is_self']): ?>
<div class="card">
  <h2>Quick add</h2>
  <p class="hint">Head to <a href="/transactions.php">Transactions</a> to log income or expenses, including one-click quick-add from your saved presets.</p>
</div>
<?php endif; ?>

<?php
$footerScripts = ['/assets/js/app.js'];
if ($expenseByCategory !== []) {
    $footerScripts = array_merge(['/assets/vendor/chart.umd.min.js', '/assets/js/charts.js'], $footerScripts);
}
render_footer($footerScripts);

