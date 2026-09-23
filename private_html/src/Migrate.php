<?php

declare(strict_types=1);

/**
 * Small, dependency-free schema migration runner for installs that were
 * set up before a schema change shipped. A fresh install already has the
 * latest schema.sql and its own app_settings row set to CURRENT_VERSION,
 * so this is a no-op there - it only does real work for existing
 * installs picking up new application code via a plain file upload.
 */
final class Migrate
{
    public const CURRENT_VERSION = 3;

    private const DUPLICATE_COLUMN_ERROR = 1060;

    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'");
        $stmt->execute();
        $version = (int) ($stmt->fetchColumn() ?: 1);

        if ($version >= self::CURRENT_VERSION) {
            return;
        }

        if ($version < 2) {
            self::v2AddCarryForwardDay($pdo);
            $version = 2;
        }

        if ($version < 3) {
            self::v3AddCarryForwardInterval($pdo);
            $version = 3;
        }

        $update = $pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value) VALUES ('schema_version', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $update->execute(['v' => (string) $version]);
    }

    private static function v2AddCarryForwardDay(\PDO $pdo): void
    {
        self::addColumnIfMissing(
            $pdo,
            'preset_items',
            'carry_forward_day',
            "TINYINT UNSIGNED NULL COMMENT 'NULL = 1st of month, 0 = last Friday of month, 1-31 = that day' AFTER auto_carry_forward"
        );

        // Preserve existing behaviour for whatever was relying on the old
        // hard-coded "income carry-forward defaults to last Friday" rule:
        // a preset literally named "Salary" carries on defaulting to the
        // last Friday of the month, while anything else (e.g. a
        // four-weekly benefit payment that was being forced onto Fridays
        // it doesn't actually land on) is freed to the plain 1st-of-month
        // default until the account holder picks something else.
        $pdo->exec(
            "UPDATE preset_items pi
             JOIN categories c ON c.id = pi.category_id
             SET pi.carry_forward_day = 0
             WHERE c.kind = 'income' AND pi.auto_carry_forward = 1
               AND pi.carry_forward_day IS NULL AND LOWER(pi.name) LIKE '%salary%'"
        );
    }

    private static function v3AddCarryForwardInterval(\PDO $pdo): void
    {
        self::addColumnIfMissing(
            $pdo,
            'preset_items',
            'carry_forward_interval',
            "ENUM('monthly','every_4_weeks') NOT NULL DEFAULT 'monthly' AFTER carry_forward_day"
        );
        self::addColumnIfMissing(
            $pdo,
            'preset_items',
            'carry_forward_anchor_date',
            "DATE NULL AFTER carry_forward_interval"
        );

        // Expense presets never expose a default-day/interval choice in
        // the UI any more - make sure any that had one set (from before
        // this restriction existed) go back to the plain 1st-of-month
        // default rather than silently keeping a value nobody can see or
        // change any more.
        $pdo->exec(
            "UPDATE preset_items pi
             JOIN categories c ON c.id = pi.category_id
             SET pi.carry_forward_day = NULL, pi.carry_forward_interval = 'monthly', pi.carry_forward_anchor_date = NULL
             WHERE c.kind = 'expense'"
        );
    }

    private static function addColumnIfMissing(\PDO $pdo, string $table, string $column, string $definitionAndPosition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definitionAndPosition}");
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== self::DUPLICATE_COLUMN_ERROR) {
                throw $e;
            }
        }
    }
}
