<?php

declare(strict_types=1);

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const REMEMBER_COOKIE = 'bp_remember';
    private const REMEMBER_DAYS = 30;

    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * Returns the current user's row (array) or null if not authenticated.
     * Checks the session first, then falls back to a remember-me cookie.
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        if (!empty($_SESSION['user_id'])) {
            $user = self::findActiveUser((int) $_SESSION['user_id']);
            if ($user !== null) {
                self::$user = $user;
                return self::$user;
            }

            // Stale/invalid session user id.
            unset($_SESSION['user_id']);
        }

        $user = self::attemptRememberLogin();
        self::$user = $user;

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    private static function findActiveUser(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{success: bool, error?: string, user?: array}
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $user = self::findByEmail($email);

        if ($user === null) {
            // Constant-ish time: still hash a dummy password to reduce user enumeration timing signal.
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuQ4c8m0d1o1k1o1k1o1k1o1k1o1k1o1');
            return ['success' => false, 'error' => 'Incorrect email or password.'];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['success' => false, 'error' => 'This account has been disabled. Contact your administrator.'];
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "Too many failed attempts. Try again in {$minutes} minute(s)."];
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailedAttempt((int) $user['id'], (int) $user['failed_login_attempts']);
            return ['success' => false, 'error' => 'Incorrect email or password.'];
        }

        self::clearFailedAttempts((int) $user['id']);
        self::login($user);

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }

        Audit::log((int) $user['id'], 'login');

        return ['success' => true, 'user' => $user];
    }

    private static function registerFailedAttempt(int $userId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        $lockedUntil = null;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $attempts = 0;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked WHERE id = :id'
        );
        $stmt->execute(['attempts' => $attempts, 'locked' => $lockedUntil, 'id' => $userId]);

        Audit::log($userId, 'login_failed');
    }

    private static function clearFailedAttempts(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$user = $user;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        $user = self::user();

        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            [$selector] = array_pad(explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2), 2, '');
            if ($selector !== '') {
                $stmt = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
                $stmt->execute(['selector' => $selector]);
            }
            self::forgetCookie();
        }

        if ($user !== null) {
            Audit::log((int) $user['id'], 'logout');
        }

        $_SESSION = [];
        session_destroy();
    }

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (:user_id, :selector, :hash, :expires)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'hash' => hash('sha256', $validator),
            'expires' => $expiresAt,
        ]);

        self::setCookie($selector . ':' . $validator, time() + self::REMEMBER_DAYS * 86400);
    }

    private static function attemptRememberLogin(): ?array
    {
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return null;
        }

        $parts = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2) {
            self::forgetCookie();
            return null;
        }

        [$selector, $validator] = $parts;

        $stmt = Database::pdo()->prepare('SELECT * FROM remember_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);
        $token = $stmt->fetch();

        if ($token === false || strtotime($token['expires_at']) < time()) {
            self::forgetCookie();
            return null;
        }

        if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
            // Possible token theft: revoke it.
            $del = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE id = :id');
            $del->execute(['id' => $token['id']]);
            self::forgetCookie();
            return null;
        }

        $user = self::findActiveUser((int) $token['user_id']);
        if ($user === null) {
            self::forgetCookie();
            return null;
        }

        // Rotate the token on use.
        $del = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE id = :id');
        $del->execute(['id' => $token['id']]);

        self::login($user);
        self::issueRememberToken((int) $user['id']);
        self::clearFailedAttempts((int) $user['id']);

        return $user;
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Config::requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function forgetCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => Config::requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
            header('Location: /login.php?next=' . $next);
            exit;
        }

        if ((int) $user['must_change_password'] === 1 && !str_contains($_SERVER['REQUEST_URI'] ?? '', 'account.php')) {
            header('Location: /account.php?force=1');
            exit;
        }

        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden: administrator access required.';
            exit;
        }

        return $user;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function updatePassword(int $userId, string $newPassword, bool $clearForceFlag = true): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = :force, failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
        );
        $stmt->execute([
            'hash' => self::hashPassword($newPassword),
            'force' => $clearForceFlag ? 0 : 1,
            'id' => $userId,
        ]);

        // Revoke all remember-me tokens on password change for safety.
        $del = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE user_id = :id');
        $del->execute(['id' => $userId]);
    }
}
