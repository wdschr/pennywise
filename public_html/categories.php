<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ownerId = (int) $currentUser['id'];

function category_belongs(int $categoryId, int $ownerId): ?array
{
    $cat = Categories::find($categoryId);
    return ($cat !== null && (int) $cat['owner_user_id'] === $ownerId) ? $cat : null;
}

/**
 * '' (not set) -> null (1st of month); '0' -> 0 (last Friday); '1'-'31' -> that day.
 */
function parse_carry_forward_day(mixed $raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!ctype_digit((string) $raw)) {
        return null;
    }
    $day = (int) $raw;
    return $day >= 0 && $day <= 31 ? $day : null;
}

function render_carry_forward_day_options(?int $selected): string
{
    $ordinal = static function (int $n): string {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }
        return $n . match ($n % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
        };
    };

    $html = '<option value=""' . ($selected === null ? ' selected' : '') . '>Default (1st of the month)</option>';
    $html .= '<option value="0"' . ($selected === 0 ? ' selected' : '') . '>Last Friday of the month</option>';
    for ($day = 1; $day <= 31; $day++) {
        $html .= '<option value="' . $day . '"' . ($selected === $day ? ' selected' : '') . '>' . $ordinal($day) . '</option>';
    }

    return $html;
}

function parse_carry_forward_interval(mixed $raw): string
{
    return $raw === 'every_4_weeks' ? 'every_4_weeks' : 'monthly';
}

function parse_anchor_date(mixed $raw): ?string
{
    $raw = (string) $raw;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        Flash::error('Your session expired. Please try again.');
        header('Location: /categories.php');
        exit;
    }

    $action = $_POST['form_action'] ?? '';
    $pdo = Database::pdo();

    if ($action === 'create_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $kind = ($_POST['kind'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#4f8ef7';

        if ($name === '') {
            Flash::error('Category name is required.');
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO categories (owner_user_id, kind, name, color, sort_order) VALUES (:owner, :kind, :name, :color, :sort)'
                );
                $stmt->execute(['owner' => $ownerId, 'kind' => $kind, 'name' => $name, 'color' => $color, 'sort' => Categories::nextSortOrder($ownerId, $kind)]);
                Flash::success('Category added.');
            } catch (\PDOException $e) {
                Flash::error('You already have a category with that name.');
            }
        }
    } elseif ($action === 'archive_category' || $action === 'unarchive_category') {
        $cat = category_belongs((int) ($_POST['category_id'] ?? 0), $ownerId);
        if ($cat !== null) {
            $stmt = $pdo->prepare('UPDATE categories SET is_archived = :archived WHERE id = :id');
            $stmt->execute(['archived' => $action === 'archive_category' ? 1 : 0, 'id' => $cat['id']]);
            Flash::success($action === 'archive_category' ? 'Category archived.' : 'Category restored.');
        }
    } elseif ($action === 'delete_category') {
        $cat = category_belongs((int) ($_POST['category_id'] ?? 0), $ownerId);
        if ($cat !== null) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE category_id = :id');
            $count->execute(['id' => $cat['id']]);
            if ((int) $count->fetchColumn() > 0) {
                Flash::error('Cannot delete a category that has transactions - archive it instead.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
                $stmt->execute(['id' => $cat['id']]);
                Flash::success('Category deleted.');
            }
        }
    } elseif ($action === 'create_preset') {
        $cat = category_belongs((int) ($_POST['category_id'] ?? 0), $ownerId);
        $name = trim((string) ($_POST['name'] ?? ''));
        $amount = $_POST['default_amount'] !== '' ? (float) $_POST['default_amount'] : null;
        $carry = !empty($_POST['auto_carry_forward']) ? 1 : 0;

        // The recurrence controls are income-only - an expense preset
        // always lands on the 1st of the month, no choice exposed.
        if ($cat !== null && $cat['kind'] === 'income') {
            $interval = parse_carry_forward_interval($_POST['carry_forward_interval'] ?? '');
            $carryDay = $interval === 'monthly' ? parse_carry_forward_day($_POST['carry_forward_day'] ?? '') : null;
            $anchor = $interval === 'every_4_weeks' ? parse_anchor_date($_POST['carry_forward_anchor_date'] ?? '') : null;
        } else {
            $interval = 'monthly';
            $carryDay = null;
            $anchor = null;
        }

        if ($cat === null || $name === '') {
            Flash::error('Please provide a preset item name.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO preset_items (category_id, owner_user_id, name, default_amount, auto_carry_forward, carry_forward_day, carry_forward_interval, carry_forward_anchor_date, sort_order)
                 VALUES (:cat, :owner, :name, :amount, :carry, :day, :interval, :anchor, (SELECT next FROM (SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM preset_items WHERE category_id = :cat2) x))'
            );
            $stmt->execute([
                'cat' => $cat['id'], 'cat2' => $cat['id'], 'owner' => $ownerId, 'name' => $name, 'amount' => $amount,
                'carry' => $carry, 'day' => $carryDay, 'interval' => $interval, 'anchor' => $anchor,
            ]);
            Flash::success('Preset item added.');
        }
    } elseif ($action === 'update_preset') {
        $presetId = (int) ($_POST['preset_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT pi.*, c.kind AS category_kind FROM preset_items pi
             JOIN categories c ON c.id = pi.category_id
             WHERE pi.id = :id AND pi.owner_user_id = :owner'
        );
        $stmt->execute(['id' => $presetId, 'owner' => $ownerId]);
        $preset = $stmt->fetch();

        if ($preset !== false) {
            $name = trim((string) ($_POST['name'] ?? $preset['name']));
            $amount = $_POST['default_amount'] !== '' ? (float) $_POST['default_amount'] : null;
            $carry = !empty($_POST['auto_carry_forward']) ? 1 : 0;
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($preset['category_kind'] === 'income') {
                $interval = parse_carry_forward_interval($_POST['carry_forward_interval'] ?? '');
                $carryDay = $interval === 'monthly' ? parse_carry_forward_day($_POST['carry_forward_day'] ?? '') : null;
                $anchor = $interval === 'every_4_weeks' ? parse_anchor_date($_POST['carry_forward_anchor_date'] ?? '') : null;
            } else {
                $interval = 'monthly';
                $carryDay = null;
                $anchor = null;
            }

            $update = $pdo->prepare(
                'UPDATE preset_items SET name = :name, default_amount = :amount, auto_carry_forward = :carry,
                 carry_forward_day = :day, carry_forward_interval = :interval, carry_forward_anchor_date = :anchor,
                 is_active = :active WHERE id = :id'
            );
            $update->execute([
                'name' => $name, 'amount' => $amount, 'carry' => $carry, 'day' => $carryDay,
                'interval' => $interval, 'anchor' => $anchor, 'active' => $active, 'id' => $presetId,
            ]);
            Flash::success('Preset item updated.');
        }
    } elseif ($action === 'delete_preset') {
        $presetId = (int) ($_POST['preset_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM preset_items WHERE id = :id AND owner_user_id = :owner');
        $stmt->execute(['id' => $presetId, 'owner' => $ownerId]);
        Flash::success('Preset item removed.');
    } elseif ($action === 'update_shares') {
        $cat = category_belongs((int) ($_POST['category_id'] ?? 0), $ownerId);
        if ($cat !== null) {
            $selected = array_map('intval', $_POST['shared_with'] ?? []);
            $others = array_column(Rbac::otherActiveUsers($ownerId), 'id');
            $selected = array_intersect($selected, $others);

            $pdo->prepare('DELETE FROM category_shares WHERE category_id = :id')->execute(['id' => $cat['id']]);
            $insert = $pdo->prepare(
                'INSERT INTO category_shares (category_id, owner_user_id, shared_with_user_id) VALUES (:cat, :owner, :user)'
            );
            foreach ($selected as $userId) {
                $insert->execute(['cat' => $cat['id'], 'owner' => $ownerId, 'user' => $userId]);
            }
            Flash::success('Sharing updated for ' . Format::e($cat['name']) . '.');
        }
    }

    header('Location: /categories.php');
    exit;
}

$expenseCategories = Categories::forOwner($ownerId, 'expense', true);
$incomeCategories = Categories::forOwner($ownerId, 'income', true);
$otherUsers = Rbac::otherActiveUsers($ownerId);

$sharesStmt = Database::pdo()->prepare('SELECT category_id, shared_with_user_id FROM category_shares WHERE owner_user_id = :owner');
$sharesStmt->execute(['owner' => $ownerId]);
$sharesByCategory = [];
foreach ($sharesStmt->fetchAll() as $row) {
    $sharesByCategory[$row['category_id']][] = (int) $row['shared_with_user_id'];
}

function render_category_block(array $cat, array $otherUsers, array $sharesByCategory): void
{
    $presets = Categories::presetsForCategory((int) $cat['id'], false);
    $shared = $sharesByCategory[$cat['id']] ?? [];
    ?>
    <details class="card" <?= $cat['is_archived'] ? '' : 'open' ?>>
      <summary style="cursor:pointer;">
        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= Format::e($cat['color'] ?? '#4f8ef7') ?>;margin-right:8px;"></span>
        <strong><?= Format::e($cat['name']) ?></strong>
        <?php if ($cat['is_archived']): ?><span class="badge">Archived</span><?php endif; ?>
      </summary>

      <?php $isIncome = $cat['kind'] === 'income'; ?>
      <div style="margin-top:16px;">
        <h3>Preset items</h3>
        <p class="hint">Preset items give you one-click quick-add buttons on the Transactions page. Turn on "carry forward" for things that repeat automatically at the same amount<?= $isIncome ? ' - use "Repeats" to control when it lands each time' : ' (always generated on the 1st of the month)' ?>.</p>
        <table>
          <thead><tr><th>Name</th><th>Default amount</th><th>Carry forward</th><?php if ($isIncome): ?><th>Repeats</th><?php endif; ?><th>Active</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($presets as $p): $fid = 'preset-form-' . (int) $p['id']; $curDay = $p['carry_forward_day'] !== null ? (int) $p['carry_forward_day'] : null; ?>
            <tr>
                <td><input form="<?= $fid ?>" type="text" name="name" value="<?= Format::e($p['name']) ?>" style="min-width:140px;"></td>
                <td><input form="<?= $fid ?>" type="number" step="0.01" min="0" name="default_amount" value="<?= $p['default_amount'] !== null ? Format::e((string) $p['default_amount']) : '' ?>" style="width:110px;"></td>
                <td><input form="<?= $fid ?>" type="checkbox" name="auto_carry_forward" <?= $p['auto_carry_forward'] ? 'checked' : '' ?>></td>
                <?php if ($isIncome): ?>
                <td>
                  <div data-carry-controls>
                    <select form="<?= $fid ?>" name="carry_forward_interval" data-carry-interval style="min-width:130px;">
                      <option value="monthly" <?= $p['carry_forward_interval'] === 'monthly' ? 'selected' : '' ?>>Every month</option>
                      <option value="every_4_weeks" <?= $p['carry_forward_interval'] === 'every_4_weeks' ? 'selected' : '' ?>>Every 4 weeks</option>
                    </select>
                    <div data-carry-monthly style="margin-top:4px;">
                      <select form="<?= $fid ?>" name="carry_forward_day" style="min-width:150px;"><?= render_carry_forward_day_options($curDay) ?></select>
                    </div>
                    <div data-carry-4weekly style="margin-top:4px;">
                      <input form="<?= $fid ?>" type="date" name="carry_forward_anchor_date" value="<?= Format::e($p['carry_forward_anchor_date'] ?? '') ?>" style="min-width:150px;">
                    </div>
                  </div>
                </td>
                <?php endif; ?>
                <td><input form="<?= $fid ?>" type="checkbox" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>></td>
                <td style="white-space:nowrap;">
                  <button form="<?= $fid ?>" type="submit" name="form_action" value="update_preset" class="btn small secondary">Save</button>
                  <button form="<?= $fid ?>" type="submit" name="form_action" value="delete_preset" data-confirm="Delete this preset item?" class="btn small danger">Delete</button>
                </td>
            </tr>
            <tr style="display:none;"><td colspan="<?= $isIncome ? 6 : 5 ?>">
              <form id="<?= $fid ?>" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="preset_id" value="<?= (int) $p['id'] ?>">
              </form>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($isIncome): ?>
        <p class="hint">"Repeats" only matters for carry-forward items. Every month lands on the 1st by default, the last Friday for a month-end salary, or a specific day you choose. Every 4 weeks is for things not tied to the calendar month, like a benefit payment - give it one date it's actually due (past or future) and every occurrence 4 weeks apart is generated from there, which can be zero, one, or two dates within a given calendar month.</p>
        <?php endif; ?>
        <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <?= Csrf::field() ?>
          <input type="hidden" name="form_action" value="create_preset">
          <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
          <label style="margin:0;">New preset name<input type="text" name="name" required></label>
          <label style="margin:0;">Default amount<input type="number" step="0.01" min="0" name="default_amount" style="width:110px;"></label>
          <label class="checkbox" style="margin:0;"><input type="checkbox" name="auto_carry_forward"> Carry forward</label>
          <?php if ($isIncome): ?>
          <div data-carry-controls>
            <label style="margin:0;">Repeats
              <select name="carry_forward_interval" data-carry-interval style="min-width:130px;">
                <option value="monthly">Every month</option>
                <option value="every_4_weeks">Every 4 weeks</option>
              </select>
            </label>
            <div data-carry-monthly style="margin-top:4px;">
              <label style="margin:0;">Default day<select name="carry_forward_day" style="min-width:150px;"><?= render_carry_forward_day_options(null) ?></select></label>
            </div>
            <div data-carry-4weekly style="margin-top:4px;">
              <label style="margin:0;">A date it's due<input type="date" name="carry_forward_anchor_date" style="min-width:150px;"></label>
            </div>
          </div>
          <?php endif; ?>
          <button class="btn small" type="submit">Add preset</button>
        </form>

        <?php if ($otherUsers !== []): ?>
        <h3 style="margin-top:20px;">Share this category</h3>
        <p class="hint">Grant read-only access to this category's transactions and reports to other accounts on this installation.</p>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="form_action" value="update_shares">
          <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
          <?php foreach ($otherUsers as $u): ?>
            <label class="checkbox"><input type="checkbox" name="shared_with[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $shared, true) ? 'checked' : '' ?>> <?= Format::e($u['display_name']) ?> (<?= Format::e($u['email']) ?>)</label>
          <?php endforeach; ?>
          <button class="btn small" type="submit">Save sharing</button>
        </form>
        <?php endif; ?>

        <form method="post" style="margin-top:16px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
          <?php if ($cat['is_archived']): ?>
            <button type="submit" name="form_action" value="unarchive_category" class="btn small secondary">Restore category</button>
          <?php else: ?>
            <button type="submit" name="form_action" value="archive_category" class="btn small secondary">Archive category</button>
          <?php endif; ?>
          <?php
            $txnCount = Database::pdo()->prepare('SELECT COUNT(*) FROM transactions WHERE category_id = :id');
            $txnCount->execute(['id' => $cat['id']]);
            if ((int) $txnCount->fetchColumn() === 0):
          ?>
            <button type="submit" name="form_action" value="delete_category" class="btn small danger" data-confirm="Permanently delete this category and its preset items?">Delete permanently</button>
          <?php endif; ?>
        </form>
      </div>
    </details>
    <?php
}

render_header('Categories', 'categories', $currentUser);
?>
<div class="topbar"><h1>Categories</h1></div>

<div class="card">
  <h2>Add a category</h2>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_action" value="create_category">
    <label style="margin:0;">Name<input type="text" name="name" required></label>
    <label style="margin:0;">Type
      <select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select>
    </label>
    <label style="margin:0;">Colour<input type="color" name="color" value="#4f8ef7" style="padding:2px;height:40px;"></label>
    <button class="btn" type="submit">Add category</button>
  </form>
</div>

<h2>Expense categories</h2>
<?php foreach ($expenseCategories as $cat) { render_category_block($cat, $otherUsers, $sharesByCategory); } ?>

<h2 style="margin-top:24px;">Income categories</h2>
<?php foreach ($incomeCategories as $cat) { render_category_block($cat, $otherUsers, $sharesByCategory); } ?>

<?php render_footer(['/assets/js/app.js']); ?>
