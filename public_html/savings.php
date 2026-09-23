<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);
$ownerId = $ctx['owner_id'];

if (!$ctx['is_self'] && $ctx['category_ids'] !== null) {
    // Savings pots aren't covered by category sharing - only the owner
    // and admins (full oversight) can see them.
    Flash::error('Savings pots are not shared with you.');
    header('Location: /shared.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        Flash::error('Your session expired. Please try again.');
    } elseif (!$ctx['can_edit']) {
        Flash::error('You do not have permission to edit this budget.');
    } else {
        $action = $_POST['form_action'] ?? '';
        $pdo = Database::pdo();

        if ($action === 'create_pot') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $potType = ($_POST['pot_type'] ?? 'general') === 'goal' ? 'goal' : 'general';
            $target = $potType === 'goal' && $_POST['target_amount'] !== '' ? (float) $_POST['target_amount'] : null;
            $targetDate = $potType === 'goal' && !empty($_POST['target_date']) ? $_POST['target_date'] : null;
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#4f8ef7';

            if ($name === '') {
                Flash::error('Pot name is required.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO savings_pots (owner_user_id, name, pot_type, target_amount, target_date, color) VALUES (:owner, :name, :type, :target, :date, :color)'
                );
                $stmt->execute(['owner' => $ownerId, 'name' => $name, 'type' => $potType, 'target' => $target, 'date' => $targetDate, 'color' => $color]);
                Flash::success('Savings pot created.');
            }
        } elseif ($action === 'add_transaction') {
            $pot = Savings::find((int) ($_POST['pot_id'] ?? 0), $ownerId);
            $direction = ($_POST['direction'] ?? 'contribution') === 'withdrawal' ? -1 : 1;
            $amount = abs((float) ($_POST['amount'] ?? 0));
            $date = (string) ($_POST['txn_date'] ?? date('Y-m-d'));
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;

            if ($pot === null || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                Flash::error('Please provide a valid amount and date.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO savings_transactions (pot_id, amount, txn_date, note) VALUES (:pot, :amount, :date, :note)'
                );
                $stmt->execute(['pot' => $pot['id'], 'amount' => $direction * $amount, 'date' => $date, 'note' => $note]);
                Audit::log($ownerId, 'savings_transaction_added', 'savings_pots', (int) $pot['id']);
                Flash::success(($direction > 0 ? 'Contribution' : 'Withdrawal') . ' logged.');
            }
        } elseif ($action === 'archive_pot' || $action === 'unarchive_pot') {
            $pot = Savings::find((int) ($_POST['pot_id'] ?? 0), $ownerId);
            if ($pot !== null) {
                $stmt = $pdo->prepare('UPDATE savings_pots SET is_archived = :archived WHERE id = :id');
                $stmt->execute(['archived' => $action === 'archive_pot' ? 1 : 0, 'id' => $pot['id']]);
                Flash::success($action === 'archive_pot' ? 'Pot archived.' : 'Pot restored.');
            }
        } elseif ($action === 'delete_transaction') {
            $txnId = (int) ($_POST['txn_id'] ?? 0);
            $stmt = $pdo->prepare(
                'DELETE st FROM savings_transactions st JOIN savings_pots sp ON sp.id = st.pot_id
                 WHERE st.id = :txn AND sp.owner_user_id = :owner'
            );
            $stmt->execute(['txn' => $txnId, 'owner' => $ownerId]);
            Flash::success('Entry removed.');
        }
    }

    header('Location: /savings.php' . (!$ctx['is_self'] ? '?owner=' . (int) $ownerId : ''));
    exit;
}

$pots = Savings::potsForOwner($ownerId, true);
$totalBalance = array_sum(array_column($pots, 'balance'));
$activePots = array_values(array_filter($pots, fn($p) => !$p['is_archived']));
$archivedPots = array_values(array_filter($pots, fn($p) => $p['is_archived']));

function render_pot_card(array $pot, array $ctx): void
{
    ?>
<div class="card">
  <div class="topbar" style="margin-bottom:12px;">
    <h2 style="margin:0;">
      <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= Format::e($pot['color'] ?? '#4f8ef7') ?>;margin-right:8px;"></span>
      <?= Format::e($pot['name']) ?>
    </h2>
    <div class="stat-value" style="font-size:22px;"><?= Format::money($pot['balance']) ?></div>
  </div>

  <?php if ($pot['pot_type'] === 'goal' && $pot['target_amount']): ?>
    <?php $pct = min(100, max(0, ($pot['balance'] / (float) $pot['target_amount']) * 100)); ?>
    <p class="hint"><?= number_format($pct, 1) ?>% of <?= Format::money($pot['target_amount']) ?> target<?= $pot['target_date'] ? ' by ' . Format::date($pot['target_date']) : '' ?></p>
    <div class="progress-bar"><div class="progress-bar-fill" style="width:<?= $pct ?>%;"></div></div>
  <?php endif; ?>

  <?php $history = Savings::balanceHistory((int) $pot['id']); ?>
  <?php if (count($history) > 1): ?>
    <canvas style="margin-top:16px;" height="180" data-chart="line" data-source="pot-history-<?= (int) $pot['id'] ?>" data-label="Balance" data-color="<?= Format::e($pot['color'] ?? '#4f8ef7') ?>"></canvas>
    <script type="application/json" id="pot-history-<?= (int) $pot['id'] ?>"><?= json_encode([
        'labels' => array_map(fn($p) => Format::date($p['date']), $history),
        'values' => array_column($history, 'balance'),
    ]) ?></script>
  <?php endif; ?>

  <?php $recent = Savings::transactions((int) $pot['id'], 8); ?>
  <?php if ($recent !== []): ?>
  <table style="margin-top:16px;">
    <thead><tr><th>Date</th><th>Amount</th><th>Note</th><?php if ($ctx['can_edit']): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($recent as $t): ?>
      <tr>
        <td><?= Format::date($t['txn_date']) ?></td>
        <td style="color:<?= $t['amount'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $t['amount'] >= 0 ? '+' : '-' ?><?= Format::money(abs((float) $t['amount'])) ?></td>
        <td><?= Format::e($t['note']) ?></td>
        <?php if ($ctx['can_edit']): ?>
        <td>
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="delete_transaction">
            <input type="hidden" name="txn_id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn small danger" data-confirm="Remove this entry?">Remove</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($ctx['can_edit']): ?>
  <form method="post" style="margin-top:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_action" value="add_transaction">
    <input type="hidden" name="pot_id" value="<?= (int) $pot['id'] ?>">
    <label style="margin:0;">Type
      <select name="direction"><option value="contribution">Contribution (+)</option><option value="withdrawal">Withdrawal (-)</option></select>
    </label>
    <label style="margin:0;">Amount<input type="number" step="0.01" min="0.01" name="amount" required></label>
    <label style="margin:0;">Date<input type="date" name="txn_date" value="<?= date('Y-m-d') ?>" required></label>
    <label style="margin:0;">Note<input type="text" name="note"></label>
    <button class="btn small" type="submit">Log entry</button>
  </form>
  <form method="post" style="margin-top:10px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="pot_id" value="<?= (int) $pot['id'] ?>">
    <button type="submit" name="form_action" value="archive_pot" class="btn small secondary">Archive pot</button>
  </form>
  <?php endif; ?>
</div>
    <?php
}

render_header('Savings', 'savings', $currentUser, $ctx);
?>
<div class="topbar">
  <h1>Savings</h1>
  <div class="stat-card card" style="margin:0;padding:10px 18px;">
    <div class="stat-label">Total across all pots</div>
    <div class="stat-value"><?= Format::money($totalBalance) ?></div>
  </div>
</div>

<?php if ($ctx['can_edit']): ?>
<div class="card">
  <h2>New savings pot</h2>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_action" value="create_pot">
    <label style="margin:0;">Name<input type="text" name="name" required></label>
    <label style="margin:0;">Type
      <select name="pot_type" id="pot-type-select">
        <option value="general">Simple running balance</option>
        <option value="goal">Goal with a target</option>
      </select>
    </label>
    <label style="margin:0;">Target amount<input type="number" step="0.01" min="0" name="target_amount"></label>
    <label style="margin:0;">Target date<input type="date" name="target_date"></label>
    <label style="margin:0;">Colour<input type="color" name="color" value="#4f8ef7" style="padding:2px;height:40px;"></label>
    <button class="btn" type="submit">Create pot</button>
  </form>
</div>
<?php endif; ?>

<?php foreach ($activePots as $pot): ?>
  <?php render_pot_card($pot, $ctx); ?>
<?php endforeach; ?>

<?php if ($archivedPots !== []): ?>
<details class="card">
  <summary style="cursor:pointer;">Archived pots (<?= count($archivedPots) ?>)</summary>
  <table style="margin-top:16px;">
    <thead><tr><th>Name</th><th>Final balance</th><?php if ($ctx['can_edit']): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($archivedPots as $pot): ?>
      <tr>
        <td>
          <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= Format::e($pot['color'] ?? '#4f8ef7') ?>;margin-right:6px;"></span>
          <?= Format::e($pot['name']) ?>
        </td>
        <td><?= Format::money($pot['balance']) ?></td>
        <?php if ($ctx['can_edit']): ?>
        <td>
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="unarchive_pot">
            <input type="hidden" name="pot_id" value="<?= (int) $pot['id'] ?>">
            <button type="submit" class="btn small secondary">Restore</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</details>
<?php endif; ?>

<?php render_footer(['/assets/vendor/chart.umd.min.js', '/assets/js/charts.js', '/assets/js/app.js']); ?>
