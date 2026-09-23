<?php

declare(strict_types=1);

/**
 * @param array|null $currentUser
 */
function resolve_theme(?array $currentUser): string
{
    if ($currentUser !== null && !empty($currentUser['theme_preference'])) {
        return $currentUser['theme_preference'];
    }

    $cookieTheme = $_COOKIE['bp_theme'] ?? null;
    if ($cookieTheme === 'light' || $cookieTheme === 'dark') {
        return $cookieTheme;
    }

    return 'dark';
}

function render_header(string $title, string $active, ?array $currentUser, array $viewContext = []): void
{
    $theme = resolve_theme($currentUser);
    $siteName = (string) Config::get('site_name', 'Pennywise');
    $isAuthed = $currentUser !== null;
    ?><!DOCTYPE html>
<html lang="en" data-theme="<?= Format::e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($isAuthed): ?>
<meta name="bp-authenticated" content="1">
<meta name="bp-csrf" content="<?= Format::e(Csrf::token()) ?>">
<?php endif; ?>
<title><?= Format::e($title) ?> - <?= Format::e($siteName) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php if ($isAuthed): ?>
<div class="app-shell">
  <div class="sidebar">
    <div class="brand"><?= Format::e($siteName) ?></div>
    <nav>
      <a href="/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="/transactions.php" class="<?= $active === 'transactions' ? 'active' : '' ?>">Transactions</a>
      <a href="/categories.php" class="<?= $active === 'categories' ? 'active' : '' ?>">Categories</a>
      <a href="/savings.php" class="<?= $active === 'savings' ? 'active' : '' ?>">Savings</a>
      <a href="/reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">Reports</a>
      <a href="/import.php" class="<?= $active === 'import' ? 'active' : '' ?>">Import data</a>
      <?php
        $shared = Rbac::ownersSharedWithMe((int) $currentUser['id']);
        if ($shared !== [] || $currentUser['role'] === 'admin'):
      ?>
      <a href="/shared.php" class="<?= $active === 'shared' ? 'active' : '' ?>">Shared with me</a>
      <?php endif; ?>
      <?php if ($currentUser['role'] === 'admin'): ?>
      <a href="/admin/users.php" class="<?= $active === 'admin' ? 'active' : '' ?>">Manage users</a>
      <?php endif; ?>
      <a href="/account.php" class="<?= $active === 'account' ? 'active' : '' ?>">Account</a>
    </nav>
    <div class="sidebar-footer">
      <button id="theme-toggle" class="theme-toggle" type="button">Toggle theme</button>
      <p class="hint" style="margin-top:10px;"><?= Format::e($currentUser['display_name']) ?><br><a href="/logout.php">Log out</a></p>
    </div>
  </div>
  <div class="main">
<?php else: ?>
<div class="main" style="max-width:100%;">
<?php endif; ?>
    <?php foreach (Flash::pull() as $flash): ?>
      <div class="flash <?= Format::e($flash['type']) ?>"><?= Format::e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if (!empty($viewContext) && !$viewContext['is_self']): ?>
      <div class="flash success">Viewing <strong><?= Format::e($viewContext['owner_name']) ?></strong>'s budget (read-only)<?= $viewContext['category_ids'] !== null ? ' - restricted to categories shared with you' : ' - admin oversight view' ?>. <a href="?<?= http_build_query(array_diff_key($_GET, ['owner' => true])) ?>">Return to your own budget</a></div>
    <?php endif; ?>
<?php
}

function render_footer(array $extraScripts = []): void
{
    ?>
  </div>
</div>
<script src="/assets/js/theme.js"></script>
<?php foreach ($extraScripts as $script): ?>
<script src="<?= Format::e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
