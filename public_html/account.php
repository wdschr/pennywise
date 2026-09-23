<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

$currentUser = Auth::user();
if ($currentUser === null) {
    header('Location: /login.php');
    exit;
}

$forced = !empty($_GET['force']) || (int) $currentUser['must_change_password'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $currency = trim((string) ($_POST['currency'] ?? 'GBP')) ?: 'GBP';

        if ($displayName === '') {
            Flash::error('Name cannot be empty.');
        } else {
            $stmt = Database::pdo()->prepare('UPDATE users SET display_name = :name, currency = :currency WHERE id = :id');
            $stmt->execute(['name' => $displayName, 'currency' => $currency, 'id' => $currentUser['id']]);
            Flash::success('Profile updated.');
        }
        header('Location: /account.php');
        exit;
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!$forced && !password_verify($current, $currentUser['password_hash'])) {
            Flash::error('Current password is incorrect.');
        } elseif (strlen($new) < 10) {
            Flash::error('New password must be at least 10 characters.');
        } elseif ($new !== $confirm) {
            Flash::error('New passwords do not match.');
        } else {
            Auth::updatePassword((int) $currentUser['id'], $new);
            Audit::log((int) $currentUser['id'], 'password_changed');
            Flash::success('Password updated.');
            header('Location: /dashboard.php');
            exit;
        }
        header('Location: /account.php');
        exit;
    }
}

$currentUser = Auth::user();
render_header('Account', 'account', $currentUser);
?>
<div class="topbar"><h1>Account</h1></div>

<?php if ($forced): ?>
  <div class="flash error">You must set a new password before continuing.</div>
<?php endif; ?>

<?php if (!$forced): ?>
<div class="card">
  <h2>Profile</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="profile">
    <label>Display name<input type="text" name="display_name" value="<?= Format::e($currentUser['display_name']) ?>" required></label>
    <label>Currency
      <select name="currency">
        <?php foreach (['GBP', 'EUR', 'USD'] as $cur): ?>
          <option value="<?= $cur ?>" <?= $currentUser['currency'] === $cur ? 'selected' : '' ?>><?= $cur ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Save profile</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Change password</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="password">
    <?php if (!$forced): ?>
    <label>Current password<input type="password" name="current_password" required></label>
    <?php endif; ?>
    <label>New password (min. 10 characters)<input type="password" name="new_password" required></label>
    <label>Confirm new password<input type="password" name="new_password_confirm" required></label>
    <button class="btn" type="submit">Update password</button>
  </form>
</div>

<?php render_footer(); ?>
