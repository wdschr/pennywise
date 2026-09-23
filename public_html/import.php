<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::requireLogin();
$ownerId = (int) $currentUser['id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        Flash::error('Your session expired. Please try again.');
        header('Location: /import.php');
        exit;
    }

    $action = $_POST['form_action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a .csv or .xlsx file to upload.';
        } elseif ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File is too large (max 5MB).';
        } else {
            $originalName = $_FILES['file']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
                $errors[] = 'Please upload a .csv or .xlsx file.';
            } elseif (!is_uploaded_file($_FILES['file']['tmp_name'])) {
                $errors[] = 'Upload failed. Please try again.';
            } else {
                try {
                    $rows = Import::parseFile($_FILES['file']['tmp_name'], $originalName);
                    if ($rows === []) {
                        $errors[] = 'That file has no data rows.';
                    } else {
                        $result = Import::validateRows($ownerId, $rows);
                        $_SESSION['import_preview'] = [
                            'filename' => $originalName,
                            'valid' => $result['valid'],
                            'errors' => $result['errors'],
                        ];
                        header('Location: /import.php?step=preview');
                        exit;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Could not read that file: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'commit') {
        $preview = $_SESSION['import_preview'] ?? null;
        if ($preview === null || $preview['valid'] === []) {
            Flash::error('Nothing to import - please upload a file first.');
        } else {
            $batchId = Import::commit($ownerId, $preview['valid'], $preview['filename']);
            unset($_SESSION['import_preview']);
            Flash::success('Imported ' . count($preview['valid']) . ' row(s) from ' . Format::e($preview['filename']) . '.');
        }
        header('Location: /import.php');
        exit;
    } elseif ($action === 'cancel_preview') {
        unset($_SESSION['import_preview']);
        header('Location: /import.php');
        exit;
    } elseif ($action === 'undo') {
        Import::undo($ownerId, (int) ($_POST['batch_id'] ?? 0));
        Flash::success('Import undone.');
        header('Location: /import.php');
        exit;
    }
}

$step = $_GET['step'] ?? 'start';
if ($step === 'preview' && empty($_SESSION['import_preview'])) {
    $step = 'start';
}

$recentImports = Database::pdo()->prepare(
    'SELECT * FROM import_batches WHERE owner_user_id = :owner ORDER BY created_at DESC LIMIT 10'
);
$recentImports->execute(['owner' => $ownerId]);
$recentImports = $recentImports->fetchAll();

render_header('Import data', 'import', $currentUser);
?>
<div class="topbar"><h1>Import data</h1></div>
<?php foreach ($errors as $e): ?><div class="flash error"><?= Format::e($e) ?></div><?php endforeach; ?>

<?php if ($step === 'preview'): $preview = $_SESSION['import_preview']; ?>
  <div class="card">
    <h2>Preview: <?= Format::e($preview['filename']) ?></h2>
    <p><?= count($preview['valid']) ?> row(s) ready to import. <?= count($preview['errors']) ?> row(s) have problems and will be skipped.</p>

    <?php if ($preview['errors'] !== []): ?>
    <h3>Rows that will be skipped</h3>
    <table>
      <thead><tr><th>Row</th><th>Problem</th></tr></thead>
      <tbody>
        <?php foreach ($preview['errors'] as $err): ?>
          <tr><td><?= (int) $err['row'] ?></td><td><?= Format::e($err['message']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php
      $newCategories = array_unique(array_map(
          fn($r) => $r['kind'] . ': ' . ($r['category_name'] ?? $r['pot_name']),
          array_filter($preview['valid'], fn($r) => !($r['category_known'] ?? $r['pot_known'] ?? true))
      ));
    ?>
    <?php if ($newCategories !== []): ?>
      <div class="flash success">These categories/pots don't exist yet and will be created automatically: <?= Format::e(implode(', ', $newCategories)) ?></div>
    <?php endif; ?>

    <h3>Sample of rows to import</h3>
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Category / Pot</th><th>Description</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($preview['valid'], 0, 20) as $r): ?>
          <tr>
            <td><?= Format::date($r['date']) ?></td>
            <td><?= Format::e(ucfirst($r['kind'])) ?></td>
            <td><?= Format::e($r['category_name'] ?? $r['pot_name']) ?></td>
            <td><?= Format::e($r['description'] ?? $r['note'] ?? '') ?></td>
            <td><?= Format::money($r['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($preview['valid']) > 20): ?><p class="hint">...and <?= count($preview['valid']) - 20 ?> more.</p><?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:16px;">
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="form_action" value="commit">
        <button class="btn" type="submit" <?= $preview['valid'] === [] ? 'disabled' : '' ?>>Confirm import of <?= count($preview['valid']) ?> row(s)</button>
      </form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="form_action" value="cancel_preview">
        <button class="btn secondary" type="submit">Cancel</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <h2>1. Download the template</h2>
    <p class="hint">The template is pre-filled with your current categories and savings pots so you know exactly what to type. Columns: Date, Type (Income/Expense/Saving), Category (or pot name for Saving rows), Description, Amount, Notes.</p>
    <a class="btn" href="/export/import-template.php">Download template (.xlsx)</a>
  </div>

  <div class="card">
    <h2>2. Upload your filled-in file</h2>
    <p class="hint">Accepts .csv or .xlsx, up to 5MB. You'll get a preview to check before anything is saved.</p>
    <form method="post" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="form_action" value="upload">
      <label>File<input type="file" name="file" accept=".csv,.xlsx,.xls" required></label>
      <button class="btn" type="submit">Upload &amp; preview</button>
    </form>
  </div>

  <?php if ($recentImports !== []): ?>
  <div class="card">
    <h2>Recent imports</h2>
    <table>
      <thead><tr><th>When</th><th>File</th><th>Rows</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recentImports as $b): ?>
          <tr>
            <td><?= Format::date($b['created_at']) ?></td>
            <td><?= Format::e($b['filename']) ?></td>
            <td><?= (int) $b['rows_imported'] ?></td>
            <td>
              <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="undo">
                <input type="hidden" name="batch_id" value="<?= (int) $b['id'] ?>">
                <button type="submit" class="btn small danger" data-confirm="Undo this import and remove its rows?">Undo</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php render_footer(['/assets/js/app.js']); ?>
