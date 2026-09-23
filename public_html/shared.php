<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$sharedOwners = Rbac::ownersSharedWithMe((int) $currentUser['id']);
$isAdmin = $currentUser['role'] === 'admin';
$allUsers = $isAdmin ? Rbac::otherActiveUsers((int) $currentUser['id']) : [];

render_header('Shared with me', 'shared', $currentUser);
?>
<div class="topbar"><h1>Shared with me</h1></div>

<div class="card">
  <h2>Budgets shared with you</h2>
  <?php if ($sharedOwners === []): ?>
    <p class="hint">Nobody has shared any categories with you yet. Category owners can grant you read-only access from their Categories page.</p>
  <?php else: ?>
    <p class="hint">You have read-only access to specific categories in these budgets.</p>
    <ul>
      <?php foreach ($sharedOwners as $owner): ?>
        <li style="margin-bottom:8px;">
          <strong><?= Format::e($owner['display_name']) ?></strong> -
          <a href="/dashboard.php?owner=<?= (int) $owner['id'] ?>">Dashboard</a> ·
          <a href="/transactions.php?owner=<?= (int) $owner['id'] ?>">Transactions</a> ·
          <a href="/reports.php?owner=<?= (int) $owner['id'] ?>">Reports</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<div class="card">
  <h2>Admin oversight - all budgets</h2>
  <p class="hint">As an administrator you can view (read-only) any user's full budget for support purposes.</p>
  <?php if ($allUsers === []): ?>
    <p class="hint">No other accounts yet.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($allUsers as $u): ?>
        <li style="margin-bottom:8px;">
          <strong><?= Format::e($u['display_name']) ?></strong> (<?= Format::e($u['email']) ?>, <?= Format::e($u['role']) ?>) -
          <a href="/dashboard.php?owner=<?= (int) $u['id'] ?>">Dashboard</a> ·
          <a href="/transactions.php?owner=<?= (int) $u['id'] ?>">Transactions</a> ·
          <a href="/reports.php?owner=<?= (int) $u['id'] ?>">Reports</a> ·
          <a href="/savings.php?owner=<?= (int) $u['id'] ?>">Savings</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
