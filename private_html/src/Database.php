<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Config::get('db', []));
        }

        return self::$pdo;
    }

    public static function connect(array $db): PDO
    {
        $host = $db['host'] ?? '127.0.0.1';
        $port = $db['port'] ?? 3306;
        $name = $db['database'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        return new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Used by the installer to test/connect without a database name selected yet.
     */
    public static function connectServer(array $db): PDO
    {
        $host = $db['host'] ?? '127.0.0.1';
        $port = $db['port'] ?? 3306;
        $charset = $db['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";

        return new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
