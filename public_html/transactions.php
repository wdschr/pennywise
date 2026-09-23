<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        Flash::error('Your session expired. Please try again.');
    } elseif (!$ctx['can_edit']) {
        Flash::error('You do not have permission to edit this budget.');
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $ownerId = $ctx['owner_id'];

        if ($formAction === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $existing = Transactions::find($id, $ownerId);
            if ($existing !== null) {
                $stmt = Database::pdo()->prepare('DELETE FROM transactions WHERE id = :id AND owner_user_id = :owner');
                $stmt->execute(['id' => $id, 'owner' => $ownerId]);
                Audit::log($ownerId, 'transaction_deleted', 'transactions', $id);
                Flash::success('Transaction deleted.');
            }
        } elseif (in_array($formAction, ['create', 'update'], true)) {
            $kind = ($_POST['kind'] ?? 'expense') === 'income' ? 'income' : 'expense';
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $presetItemId = (int) ($_POST['preset_item_id'] ?? 0) ?: null;
            $description = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $txnDate = (string) ($_POST['txn_date'] ?? date('Y-m-d'));
            $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

            $category = Categories::find($categoryId);
            if ($category === null || (int) $category['owner_user_id'] !== $ownerId || $category['kind'] !== $kind) {
                Flash::error('Please choose a valid category.');
            } elseif ($amount <= 0) {
                Flash::error('Amount must be greater than zero.');
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) {
                Flash::error('Please provide a valid date.');
            } else {
                if ($presetItemId !== null) {
                    $stmt = Database::pdo()->prepare('SELECT id FROM preset_items WHERE id = :id AND owner_user_id = :owner');
                    $stmt->execute(['id' => $presetItemId, 'owner' => $ownerId]);
                    if ($stmt->fetchColumn() === false) {
                        $presetItemId = null;
                    }
                }

                if ($formAction === 'create') {
                    $stmt = Database::pdo()->prepare(
                        'INSERT INTO transactions (owner_user_id, kind, category_id, preset_item_id, description, amount, txn_date, source, notes)
                         VALUES (:owner, :kind, :category_id, :preset_item_id, :description, :amount, :txn_date, "manual", :notes)'
                    );
                    $stmt->execute([
                        'owner' => $ownerId, 'kind' => $kind, 'category_id' => $categoryId,
                        'preset_item_id' => $presetItemId, 'description' => $description ?: null,
                        'amount' => $amount, 'txn_date' => $txnDate, 'notes' => $notes,
                    ]);
                    Audit::log($ownerId, 'transaction_created', 'transactions', (int) Database::pdo()->lastInsertId());
                    Flash::success('Transaction added.');
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $existing = Transactions::find($id, $ownerId);
                    if ($existing === null) {
                        Flash::error('Transaction not found.');
                    } else {
                        $stmt = Database::pdo()->prepare(
                            'UPDATE transactions SET kind = :kind, category_id = :category_id, preset_item_id = :preset_item_id,
                             description = :description, amount = :amount, txn_date = :txn_date, notes = :notes
                             WHERE id = :id AND owner_user_id = :owner'
                        );
                        $stmt->execute([
                            'kind' => $kind, 'category_id' => $categoryId, 'preset_item_id' => $presetItemId,
                            'description' => $description ?: null, 'amount' => $amount, 'txn_date' => $txnDate,
                            'notes' => $notes, 'id' => $id, 'owner' => $ownerId,
                        ]);
                        Audit::log($ownerId, 'transaction_updated', 'transactions', $id);
                        Flash::success('Transaction updated.');
                    }
                }
            }
        }
    }

    $redirect = '/transactions.php?' . http_build_query($_GET);
    header('Location: ' . $redirect);
    exit;
}

$monthWasDefaulted = !isset($_GET['month']) || !preg_match('/^\d{4}-\d{2}$/', $_GET['month']);
$month = $_GET['month'] ?? Recurring::defaultBudgetMonth();
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = Recurring::defaultBudgetMonth();
}
$defaultedToNextMonth = $monthWasDefaulted && $month !== date('Y-m');

if ($ctx['is_self'] && $month >= date('Y-m')) {
    $carried = Recurring::generateForMonth((int) $currentUser['id'], $month . '-01');
    if ($carried['count'] > 0) {
        Flash::success('Carried forward ' . $carried['count'] . ' recurring item(s) for ' . Format::monthLabel($month) . ': ' . implode(', ', $carried['items']));
    }
}

$filters = [
    'from' => $month . '-01',
    'to' => date('Y-m-t', strtotime($month . '-01')),
    'kind' => $_GET['kind'] ?? null,
    'category_id' => $_GET['category'] ?? null,
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category_ids' => $ctx['category_ids'],
];

$items = Transactions::query($ctx['owner_id'], $filters, 500);

$expenseCategories = Categories::forOwner($ctx['owner_id'], 'expense');
$incomeCategories = Categories::forOwner($ctx['owner_id'], 'income');
if ($ctx['category_ids'] !== null) {
    $allowed = array_flip($ctx['category_ids']);
    $expenseCategories = array_values(array_filter($expenseCategories, fn($c) => isset($allowed[(int) $c['id']])));
    $incomeCategories = array_values(array_filter($incomeCategories, fn($c) => isset($allowed[(int) $c['id']])));
}
$presets = $ctx['is_self'] ? Categories::allPresetsForOwner($ctx['owner_id']) : [];
$presetsByCategory = [];
foreach ($presets as $p) {
    $presetsByCategory[$p['category_id']][] = $p;
}

render_header('Transactions', 'transactions', $currentUser, $ctx);
?>
<div class="topbar">
  <h1>Transactions</h1>
  <?php if ($ctx['can_edit']): ?>
  <div>
    <button class="btn" type="button" data-modal-open="add" data-kind="expense">+ Add expense</button>
    <button class="btn secondary" type="button" data-modal-open="add" data-kind="income">+ Add income</button>
  </div>
  <?php endif; ?>
</div>
<?php if ($defaultedToNextMonth): ?>
  <p class="hint">It's the 20th or later, so you're viewing <strong><?= Format::e(Format::monthLabel($month)) ?></strong> by default - the budget it funds. <a href="?month=<?= Format::e(date('Y-m')) ?><?= $ctx['is_self'] ? '' : '&owner=' . (int) $ctx['owner_id'] ?>">View <?= Format::e(Format::monthLabel(date('Y-m'))) ?> instead</a></p>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
    <?php if (!$ctx['is_self']): ?><input type="hidden" name="owner" value="<?= (int) $ctx['owner_id'] ?>"><?php endif; ?>
    <label style="margin:0;">Month<input type="month" name="month" value="<?= Format::e($month) ?>"></label>
    <label style="margin:0;">Type
      <select name="kind">
        <option value="">All</option>
        <option value="expense" <?= ($_GET['kind'] ?? '') === 'expense' ? 'selected' : '' ?>>Expense</option>
        <option value="income" <?= ($_GET['kind'] ?? '') === 'income' ? 'selected' : '' ?>>Income</option>
      </select>
    </label>
    <label style="margin:0;">Category
      <select name="category">
        <option value="">All</option>
        <?php foreach (array_merge($expenseCategories, $incomeCategories) as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) ($_GET['category'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= Format::e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="margin:0;">Search<input type="text" name="q" value="<?= Format::e($_GET['q'] ?? '') ?>" placeholder="Description or notes"></label>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
</div>

<?php if ($presets !== []): ?>
<div class="card">
  <h2>Quick add</h2>
  <p class="hint">Pick a preset, set the quantity if you need more than one (e.g. two bus fares), and the date - it's remembered for your next quick add, so logging a whole commute day only means picking the date once.</p>
  <?php foreach (array_merge($expenseCategories, $incomeCategories) as $c): ?>
    <?php $catPresets = $presetsByCategory[$c['id']] ?? []; ?>
    <?php if ($catPresets === []) continue; ?>
    <div style="margin-bottom:14px;">
      <div class="hint" style="margin-bottom:6px;"><?= Format::e($c['name']) ?></div>
      <div class="chip-list">
        <?php foreach ($catPresets as $p): ?>
          <button type="button" class="chip" data-preset-chip
            data-preset-id="<?= (int) $p['id'] ?>"
            data-category-id="<?= (int) $p['category_id'] ?>"
            data-kind="<?= Format::e($c['kind']) ?>"
            data-name="<?= Format::e($p['name']) ?>"
            data-amount="<?= Format::e((string) $p['default_amount']) ?>">
            <?= Format::e($p['name']) ?><?= $p['default_amount'] !== null ? ' - ' . Format::money((float) $p['default_amount']) : '' ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <p class="hint">No transactions match your filters.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Amount</th><?php if ($ctx['can_edit']): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($items as $t): ?>
      <tr>
        <td><?= Format::date($t['txn_date']) ?></td>
        <td><span class="badge"><?= $t['kind'] === 'income' ? 'Income' : 'Expense' ?></span></td>
        <td><span class="badge" style="border-color:<?= Format::e($t['category_color'] ?? '') ?>"><?= Format::e($t['category_name']) ?></span></td>
        <td><?= Format::e($t['description']) ?><?php if (!empty($t['source']) && $t['source'] === 'recurring'): ?> <span class="hint">(recurring)</span><?php endif; ?></td>
        <td style="color:<?= $t['kind'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>">
          <?= $t['kind'] === 'income' ? '+' : '-' ?><?= Format::money($t['amount']) ?>
        </td>
        <?php if ($ctx['can_edit']): ?>
        <td style="white-space:nowrap;">
          <button type="button" class="btn small secondary" data-modal-open="edit"
            data-id="<?= (int) $t['id'] ?>" data-kind="<?= Format::e($t['kind']) ?>" data-category-id="<?= (int) $t['category_id'] ?>"
            data-description="<?= Format::e($t['description'] ?? '') ?>" data-amount="<?= Format::e((string) $t['amount']) ?>"
            data-date="<?= Format::e($t['txn_date']) ?>" data-notes="<?= Format::e($t['notes'] ?? '') ?>">Edit</button>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn small danger" data-confirm="Delete this transaction?">Delete</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($ctx['can_edit']): ?>
<div class="modal-backdrop" id="txn-modal">
  <div class="modal">
    <h2 data-modal-title>Add transaction</h2>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="form_action" value="create">
      <input type="hidden" name="id" value="">
      <input type="hidden" name="kind" value="expense">
      <input type="hidden" name="preset_item_id" value="">
      <div style="display:flex;gap:8px;margin-bottom:14px;">
        <button type="button" class="btn" data-kind-btn="expense">Expense</button>
        <button type="button" class="btn secondary" data-kind-btn="income">Income</button>
      </div>
      <label>Category
        <select data-kind-group="expense" name="category_id">
          <?php foreach ($expenseCategories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= Format::e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select data-kind-group="income" name="category_id" style="display:none;" disabled>
          <?php foreach ($incomeCategories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= Format::e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Description<input type="text" name="description"></label>
      <label id="qty-field" style="display:none;">Quantity
        <input type="number" step="1" min="1" id="qty-input" value="1">
      </label>
      <label>Amount<input type="number" step="0.01" min="0.01" name="amount" id="amount-input" required></label>
      <label>Date<input type="date" name="txn_date" required></label>
      <label>Notes<textarea name="notes"></textarea></label>
      <div style="display:flex;gap:10px;">
        <button class="btn" type="submit">Save</button>
        <button class="btn secondary" type="button" data-modal-close>Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php render_footer(['/assets/js/app.js']); ?>
