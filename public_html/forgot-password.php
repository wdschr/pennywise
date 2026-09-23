<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::verify($_POST['csrf_token'] ?? null)) {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($email !== '') {
        $stmt = Database::pdo()->prepare('SELECT id, display_name FROM users WHERE email = :email AND is_active = 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user !== false) {
            $token = bin2hex(random_bytes(32));
            $insert = Database::pdo()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires)'
            );
            $insert->execute([
                'user_id' => $user['id'],
                'hash' => hash('sha256', $token),
                'expires' => date('Y-m-d H:i:s', time() + 3600),
            ]);

            $resetUrl = Config::siteUrl() . '/reset-password.php?token=' . $token;
            $body = '<p>Hi ' . Format::e($user['display_name']) . ',</p>'
                . '<p>Someone requested a password reset for your Pennywise account. If this was you, click the link below within the next hour:</p>'
                . '<p><a href="' . Format::e($resetUrl) . '">' . Format::e($resetUrl) . '</a></p>'
                . '<p>If you did not request this, you can safely ignore this email.</p>';

            Mailer::send($email, $user['display_name'], 'Reset your password', $body);
            Audit::log((int) $user['id'], 'password_reset_requested');
        }
    }

    // Always show the same message, whether or not the email existed.
    $sent = true;
}

$theme = $_COOKIE['bp_theme'] ?? 'dark';
?><!DOCTYPE html>
<html lang="en" data-theme="<?= Format::e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgotten password - <?= Format::e((string) Config::get('site_name', 'Pennywise')) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Forgotten password</h1>
    <?php if ($sent): ?>
      <p>If that email address is registered, a reset link has been sent. It expires in one hour.</p>
      <p><a href="/login.php">Back to log in</a></p>
    <?php else: ?>
      <form method="post">
        <?= Csrf::field() ?>
        <label>Email<input type="email" name="email" required autofocus></label>
        <button class="btn" type="submit" style="width:100%;">Send reset link</button>
      </form>
      <p class="hint" style="margin-top:16px;"><a href="/login.php">Back to log in</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
