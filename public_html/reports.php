<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);

$to = $_GET['to'] ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01'))));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-5 months'));

$summary = Transactions::summary($ctx['owner_id'], $from, $to, $ctx['category_ids']);
$expenseByCategory = array_values(array_filter($summary['by_category'], fn($c) => $c['kind'] === 'expense'));
$incomeByCategory = array_values(array_filter($summary['by_category'], fn($c) => $c['kind'] === 'income'));

$fromMonth = date('Y-m', strtotime($from));
$toMonth = date('Y-m', strtotime($to));
$trend = Transactions::monthlyTrend($ctx['owner_id'], $fromMonth, $toMonth, $ctx['category_ids']);

$exportQuery = http_build_query(array_filter([
    'from' => $from, 'to' => $to,
    'owner' => $ctx['is_self'] ? null : $ctx['owner_id'],
]));

render_header('Reports', 'reports', $currentUser, $ctx);
?>
<div class="topbar">
  <h1>Reports</h1>
  <div>
    <a class="btn secondary" href="/export/pdf.php?<?= $exportQuery ?>">Export PDF</a>
    <a class="btn secondary" href="/export/xlsx.php?<?= $exportQuery ?>">Export XLSX</a>
  </div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <?php if (!$ctx['is_self']): ?><input type="hidden" name="owner" value="<?= (int) $ctx['owner_id'] ?>"><?php endif; ?>
    <label style="margin:0;">From<input type="date" name="from" value="<?= Format::e($from) ?>"></label>
    <label style="margin:0;">To<input type="date" name="to" value="<?= Format::e($to) ?>"></label>
    <button class="btn secondary" type="submit">Update</button>
  </form>
</div>

<div class="card-grid">
  <div class="card stat-card positive"><div class="stat-label">Total income</div><div class="stat-value"><?= Format::money($summary['income']) ?></div></div>
  <div class="card stat-card negative"><div class="stat-label">Total expenses</div><div class="stat-value"><?= Format::money($summary['expense']) ?></div></div>
  <div class="card stat-card <?= $summary['net'] >= 0 ? 'positive' : 'negative' ?>"><div class="stat-label">Net</div><div class="stat-value"><?= Format::money($summary['net']) ?></div></div>
</div>

<div class="card-grid" style="grid-template-columns: 1.3fr 1fr;">
  <div class="card">
    <h2>Income vs expenses by month</h2>
    <?php if ($trend === []): ?>
      <p class="hint">No data in this range.</p>
    <?php else: ?>
      <canvas height="260" data-chart="trend" data-source="trend-data"></canvas>
      <script type="application/json" id="trend-data"><?= json_encode([
          'labels' => array_map(fn($m) => Format::monthLabel($m['month']), $trend),
          'income' => array_column($trend, 'income'),
          'expense' => array_column($trend, 'expense'),
      ]) ?></script>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Expenses by category</h2>
    <?php if ($expenseByCategory === []): ?>
      <p class="hint">No expenses in this range.</p>
    <?php else: ?>
      <canvas height="260" data-chart="pie" data-source="expense-pie-data"></canvas>
      <script type="application/json" id="expense-pie-data"><?= json_encode([
          'labels' => array_column($expenseByCategory, 'name'),
          'values' => array_map('floatval', array_column($expenseByCategory, 'total')),
          'colors' => array_column($expenseByCategory, 'color'),
      ]) ?></script>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Category breakdown</h2>
  <table>
    <thead><tr><th>Category</th><th>Type</th><th>Total</th><th>% of total</th></tr></thead>
    <tbody>
    <?php foreach ($summary['by_category'] as $c): $base = $c['kind'] === 'expense' ? $summary['expense'] : $summary['income']; ?>
      <tr>
        <td><span class="badge" style="border-color:<?= Format::e($c['color'] ?? '') ?>"><?= Format::e($c['name']) ?></span></td>
        <td><?= $c['kind'] === 'income' ? 'Income' : 'Expense' ?></td>
        <td><?= Format::money($c['total']) ?></td>
        <td><?= $base > 0 ? number_format(((float) $c['total'] / $base) * 100, 1) . '%' : '-' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(['/assets/vendor/chart.umd.min.js', '/assets/js/charts.js']); ?>
