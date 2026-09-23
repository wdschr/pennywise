<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';

$lockFile = __DIR__ . '/../../private_html/install.lock';
$privateDir = realpath(__DIR__ . '/../../private_html');

if (is_file($lockFile) || Config::isInstalled()) {
    installer_render('already_installed', []);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['install'] = $_SESSION['install'] ?? [];
$step = $_GET['step'] ?? 'welcome';
$errors = [];

function installer_wizard_data(): array
{
    return $_SESSION['install'];
}

function installer_store(array $data): void
{
    $_SESSION['install'] = array_merge($_SESSION['install'], $data);
}

function check_requirements(?string $privateDir): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP version 8.1 or newer',
        'pass' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => 'Detected PHP ' . PHP_VERSION,
    ];

    foreach (['pdo_mysql', 'mbstring', 'openssl', 'session', 'zip', 'gd'] as $ext) {
        $checks[] = [
            'label' => "PHP extension: {$ext}",
            'pass' => extension_loaded($ext),
            'detail' => extension_loaded($ext) ? 'Enabled' : 'Missing - enable it in your hosting control panel\'s PHP settings',
        ];
    }

    $checks[] = [
        'label' => 'private_html directory found outside the web root',
        'pass' => $privateDir !== false && is_dir($privateDir),
        'detail' => $privateDir !== false ? $privateDir : 'Not found - expected a private_html folder alongside public_html',
    ];

    $checks[] = [
        'label' => 'private_html is writable',
        'pass' => $privateDir !== false && is_writable($privateDir),
        'detail' => 'Needed to write config.php and install.lock',
    ];

    return $checks;
}

function requirements_pass(array $checks): bool
{
    foreach ($checks as $check) {
        if (!$check['pass']) {
            return false;
        }
    }
    return true;
}

switch ($step) {
    case 'welcome':
        $checks = check_requirements($privateDir === false ? null : $privateDir);
        installer_render('welcome', ['checks' => $checks, 'ok' => requirements_pass($checks)]);
        break;

    case 'database':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'port' => (int) ($_POST['db_port'] ?? 3306),
                'database' => trim((string) ($_POST['db_name'] ?? '')),
                'username' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_pass'] ?? ''),
                'charset' => 'utf8mb4',
            ];

            foreach (['host', 'database', 'username'] as $field) {
                if ($db[$field === 'database' ? 'database' : $field] === '') {
                    $errors[] = 'Please fill in all required database fields.';
                    break;
                }
            }

            if ($errors === []) {
                try {
                    Database::connect($db);
                    installer_store(['db' => $db]);
                    header('Location: ?step=site');
                    exit;
                } catch (\Throwable $e) {
                    $errors[] = 'Could not connect to that database: ' . $e->getMessage();
                }
            }
        }

        installer_render('database', ['errors' => $errors, 'data' => installer_wizard_data()['db'] ?? []]);
        break;

    case 'site':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $site = [
                'site_name' => trim((string) ($_POST['site_name'] ?? 'Pennywise')) ?: 'Pennywise',
                'site_url' => trim((string) ($_POST['site_url'] ?? '')),
                'timezone' => trim((string) ($_POST['timezone'] ?? 'Europe/London')),
                'default_currency' => trim((string) ($_POST['default_currency'] ?? 'GBP')) ?: 'GBP',
                'force_https' => isset($_POST['force_https']),
            ];

            installer_store(['site' => $site]);
            header('Location: ?step=admin');
            exit;
        }

        installer_render('site', ['data' => installer_wizard_data()['site'] ?? []]);
        break;

    case 'admin':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $admin = [
                'display_name' => trim((string) ($_POST['display_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
                'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
            ];

            if ($admin['display_name'] === '' || $admin['email'] === '') {
                $errors[] = 'Please provide your name and email address.';
            }
            if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if (strlen($admin['password']) < 10) {
                $errors[] = 'Password must be at least 10 characters.';
            }
            if ($admin['password'] !== $admin['password_confirm']) {
                $errors[] = 'Passwords do not match.';
            }

            if ($errors === []) {
                installer_store(['admin' => $admin]);
                header('Location: ?step=confirm');
                exit;
            }
        }

        installer_render('admin', ['errors' => $errors, 'data' => installer_wizard_data()['admin'] ?? []]);
        break;

    case 'confirm':
        $wizard = installer_wizard_data();
        if (empty($wizard['db']) || empty($wizard['admin']) || empty($wizard['site'])) {
            header('Location: ?step=welcome');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                install_run($wizard);
                unset($_SESSION['install']);
                header('Location: ?step=done');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage();
            }
        }

        installer_render('confirm', ['errors' => $errors, 'wizard' => $wizard]);
        break;

    case 'done':
        installer_render('done', []);
        break;

    default:
        header('Location: ?step=welcome');
        exit;
}

function install_run(array $wizard): void
{
    $pdo = Database::connect($wizard['db']);

    $schemaSql = file_get_contents(__DIR__ . '/../../private_html/schema.sql');
    if ($schemaSql === false) {
        throw new RuntimeException('Could not read schema.sql');
    }

    $statements = array_filter(array_map('trim', explode(";\n", preg_replace('/--.*$/m', '', $schemaSql))));
    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    // A fresh install already has the latest schema.sql, so mark it as
    // fully migrated up front - Migrate::ensureSchema() then has nothing
    // to do on this install's first request.
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('schema_version', :v)")
        ->execute(['v' => (string) Migrate::CURRENT_VERSION]);

    $appKey = bin2hex(random_bytes(32));

    $config = [
        'db' => $wizard['db'],
        'site_url' => $wizard['site']['site_url'],
        'site_name' => $wizard['site']['site_name'],
        'default_currency' => $wizard['site']['default_currency'],
        'timezone' => $wizard['site']['timezone'],
        'app_key' => $appKey,
        'force_https' => $wizard['site']['force_https'],
        'mail' => [
            'from_address' => '',
            'from_name' => $wizard['site']['site_name'],
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
        ],
        'schema_version' => 1,
    ];

    Config::write($config);

    $insertAdmin = $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, role, must_change_password)
         VALUES (:email, :hash, :name, 'admin', 0)"
    );
    $insertAdmin->execute([
        'email' => mb_strtolower($wizard['admin']['email']),
        'hash' => password_hash($wizard['admin']['password'], PASSWORD_DEFAULT),
        'name' => $wizard['admin']['display_name'],
    ]);
    $adminId = (int) $pdo->lastInsertId();

    Seed::forNewUser($adminId);

    file_put_contents(__DIR__ . '/../../private_html/install.lock', 'Installed ' . date('c') . "\n");
}

function installer_render(string $view, array $data): void
{
    extract($data);
    ?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pennywise - Installer</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="install-body">
<div class="install-wrap">
<div class="install-card">
<h1>Pennywise Installer</h1>
<?php if ($view === 'already_installed'): ?>
    <p>Pennywise is already installed.</p>
    <p>If you need to reinstall, delete <code>private_html/config.php</code> and <code>private_html/install.lock</code> via FTP first, then reload this page.</p>
    <p><a class="btn" href="/login.php">Go to login</a></p>

<?php elseif ($view === 'welcome'): ?>
    <p>This wizard will set up your database, create your admin account, and write your configuration file to <code>private_html/config.php</code>, outside the public web root.</p>
    <h2>System requirements</h2>
    <ul class="req-list">
    <?php foreach ($checks as $check): ?>
        <li class="<?= $check['pass'] ? 'pass' : 'fail' ?>">
            <span class="req-icon"><?= $check['pass'] ? '✓' : '✕' ?></span>
            <strong><?= Format::e($check['label']) ?></strong>
            <div class="req-detail"><?= Format::e($check['detail']) ?></div>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($ok): ?>
        <p><a class="btn" href="?step=database">Continue</a></p>
    <?php else: ?>
        <p class="error">Please resolve the failed checks above, then reload this page.</p>
    <?php endif; ?>

<?php elseif ($view === 'database'): ?>
    <h2>Step 1 of 4: Database connection</h2>
    <?php foreach ($errors as $e): ?><p class="error"><?= Format::e($e) ?></p><?php endforeach; ?>
    <p class="hint">Use the database name, username and password your hosting control panel gave you for this app - most shared hosting control panels have a "MySQL Databases" section for creating these.</p>
    <form method="post">
        <label>Database host<input type="text" name="db_host" value="<?= Format::e($data['host'] ?? '127.0.0.1') ?>" required></label>
        <label>Port<input type="number" name="db_port" value="<?= Format::e((string) ($data['port'] ?? 3306)) ?>" required></label>
        <label>Database name<input type="text" name="db_name" value="<?= Format::e($data['database'] ?? '') ?>" required></label>
        <label>Database username<input type="text" name="db_user" value="<?= Format::e($data['username'] ?? '') ?>" required></label>
        <label>Database password<input type="password" name="db_pass" value=""></label>
        <button class="btn" type="submit">Test connection &amp; continue</button>
    </form>

<?php elseif ($view === 'site'): ?>
    <h2>Step 2 of 4: Site settings</h2>
    <form method="post">
        <label>Site name<input type="text" name="site_name" value="<?= Format::e($data['site_name'] ?? 'Pennywise') ?>" required></label>
        <label>
            Site URL (leave blank if you haven't pointed a hostname at this hosting yet - the app will auto-detect it, and you can set it later by editing the config file)
            <input type="text" name="site_url" placeholder="https://budget.example.com" value="<?= Format::e($data['site_url'] ?? '') ?>">
        </label>
        <label>Timezone
            <select name="timezone">
                <?php foreach (['Europe/London', 'UTC', 'Europe/Dublin'] as $tz): ?>
                    <option value="<?= $tz ?>" <?= ($data['timezone'] ?? 'Europe/London') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Default currency
            <select name="default_currency">
                <?php foreach (['GBP', 'EUR', 'USD'] as $cur): ?>
                    <option value="<?= $cur ?>" <?= ($data['default_currency'] ?? 'GBP') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="checkbox"><input type="checkbox" name="force_https" <?= !empty($data['force_https']) ? 'checked' : '' ?>> Force HTTPS (only enable once your SSL certificate is active)</label>
        <button class="btn" type="submit">Continue</button>
    </form>

<?php elseif ($view === 'admin'): ?>
    <h2>Step 3 of 4: Your admin account</h2>
    <?php foreach ($errors as $e): ?><p class="error"><?= Format::e($e) ?></p><?php endforeach; ?>
    <form method="post">
        <label>Your name<input type="text" name="display_name" value="<?= Format::e($data['display_name'] ?? '') ?>" required></label>
        <label>Email address<input type="email" name="email" value="<?= Format::e($data['email'] ?? '') ?>" required></label>
        <label>Password (min. 10 characters)<input type="password" name="password" required></label>
        <label>Confirm password<input type="password" name="password_confirm" required></label>
        <button class="btn" type="submit">Continue</button>
    </form>

<?php elseif ($view === 'confirm'): ?>
    <h2>Step 4 of 4: Confirm &amp; install</h2>
    <?php foreach ($errors as $e): ?><p class="error"><?= Format::e($e) ?></p><?php endforeach; ?>
    <ul class="summary">
        <li>Database: <?= Format::e($wizard['db']['database']) ?>@<?= Format::e($wizard['db']['host']) ?></li>
        <li>Site name: <?= Format::e($wizard['site']['site_name']) ?></li>
        <li>Site URL: <?= Format::e($wizard['site']['site_url'] !== '' ? $wizard['site']['site_url'] : '(auto-detect)') ?></li>
        <li>Admin: <?= Format::e($wizard['admin']['display_name']) ?> &lt;<?= Format::e($wizard['admin']['email']) ?>&gt;</li>
    </ul>
    <p class="hint">This will create the database tables and your admin account. This cannot be safely re-run once complete.</p>
    <form method="post">
        <button class="btn" type="submit">Install now</button>
    </form>

<?php elseif ($view === 'done'): ?>
    <h2>Installation complete</h2>
    <p>Pennywise is ready. Log in with the admin account you just created.</p>
    <p class="error"><strong>Important:</strong> for security, now restrict or delete the <code>public_html/install/</code> directory (e.g. via FTP). A lock file prevents this wizard being re-run, but the folder should not stay publicly reachable.</p>
    <p><a class="btn" href="/login.php">Go to login</a></p>
<?php endif; ?>
</div>
</div>
</body>
</html>
<?php
}
