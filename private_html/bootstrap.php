<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/src/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

// Not installed yet? Send everything to the installer, except the
// installer's own assets and itself.
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$isInstallerRequest = str_contains($scriptPath, '/install/');

if (!Config::isInstalled() && !$isInstallerRequest) {
    header('Location: /install/');
    exit;
}

if (Config::isInstalled()) {
    date_default_timezone_set((string) Config::get('timezone', 'Europe/London'));

    if (Config::get('force_https', false) && !Config::requestIsHttps() && php_sapi_name() !== 'cli-server') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    // Picks up schema changes shipped in an app-code-only update, so an
    // existing install stays in sync without a separate migration step.
    Migrate::ensureSchema();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => Config::requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('bp_session');
    session_start();
}

// Baseline security headers for every response.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:;");
