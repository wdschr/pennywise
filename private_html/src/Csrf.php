<?php

declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
    }

    public static function verify(?string $submitted): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || $submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }

    /**
     * Verify the current request (POST field csrf_token, or X-CSRF-Token
     * header for JSON API calls). Sends a 419-style JSON error and exits
     * on failure for API endpoints; caller handles it for form pages.
     */
    public static function requireValid(): void
    {
        $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!self::verify(is_string($submitted) ? $submitted : null)) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Your session has expired. Please refresh and try again.']);
            exit;
        }
    }
}
