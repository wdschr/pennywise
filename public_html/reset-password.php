<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$success = false;

$stmt = Database::pdo()->prepare(
    'SELECT pr.*, u.email, u.display_name FROM password_resets pr
     JOIN users u ON u.id = pr.user_id
     WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()'
);
$stmt->execute(['hash' => hash('sha256', $token)]);
$reset = $stmt->fetch();

if ($token === '' || $reset === false) {
    $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            Auth::updatePassword((int) $reset['user_id'], $password);
            $mark = Database::pdo()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
            $mark->execute(['id' => $reset['id']]);
            Audit::log((int) $reset['user_id'], 'password_reset_completed');
            $success = true;
        }
    }
}

$theme = $_COOKIE['bp_theme'] ?? 'dark';
?><!DOCTYPE html>
<html lang="en" data-theme="<?= Format::e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password - <?= Format::e((string) Config::get('site_name', 'Pennywise')) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Reset password</h1>
    <?php foreach ($errors as $e): ?><p class="error"><?= Format::e($e) ?></p><?php endforeach; ?>
    <?php if ($success): ?>
      <p>Your password has been updated. You can now log in.</p>
      <p><a class="btn" href="/login.php">Go to log in</a></p>
    <?php elseif ($reset !== false): ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= Format::e($token) ?>">
        <label>New password (min. 10 characters)<input type="password" name="password" required autofocus></label>
        <label>Confirm new password<input type="password" name="password_confirm" required></label>
        <button class="btn" type="submit" style="width:100%;">Set new password</button>
      </form>
    <?php else: ?>
      <p><a href="/forgot-password.php">Request a new reset link</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
