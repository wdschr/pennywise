<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';
require __DIR__ . '/partials/layout.php';

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        $result = Auth::attempt($email, $password, $remember);
        if ($result['success']) {
            $next = $_GET['next'] ?? '/dashboard.php';
            $next = str_starts_with($next, '/') ? $next : '/dashboard.php';
            header('Location: ' . $next);
            exit;
        }

        $errors[] = $result['error'];
    }
}

$theme = resolve_theme(null);
?><!DOCTYPE html>
<html lang="en" data-theme="<?= Format::e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in - <?= Format::e((string) Config::get('site_name', 'Pennywise')) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <h1><?= Format::e((string) Config::get('site_name', 'Pennywise')) ?></h1>
    <?php foreach ($errors as $e): ?><p class="error"><?= Format::e($e) ?></p><?php endforeach; ?>
    <form method="post">
      <?= Csrf::field() ?>
      <label>Email<input type="email" name="email" value="<?= Format::e($email) ?>" required autofocus></label>
      <label>Password<input type="password" name="password" required></label>
      <label class="checkbox"><input type="checkbox" name="remember"> Remember me on this device</label>
      <button class="btn" type="submit" style="width:100%;">Log in</button>
    </form>
    <p class="hint" style="margin-top:16px;"><a href="/forgot-password.php">Forgotten your password?</a></p>
    <p style="margin-top:16px;"><button id="theme-toggle" class="theme-toggle" type="button">Toggle theme</button></p>
  </div>
</div>
<script src="/assets/js/theme.js"></script>
</body>
</html>
