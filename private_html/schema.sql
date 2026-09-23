-- Pennywise database schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- Applied automatically by the installer. Safe to re-read for reference.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS users (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email                  VARCHAR(190) NOT NULL,
    password_hash          VARCHAR(255) NOT NULL,
    display_name           VARCHAR(100) NOT NULL,
    role                   ENUM('admin','user') NOT NULL DEFAULT 'user',
    theme_preference       ENUM('dark','light') NOT NULL DEFAULT 'dark',
    currency               VARCHAR(3) NOT NULL DEFAULT 'GBP',
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password   TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts  INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until           DATETIME NULL,
    last_login_at          DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    selector        VARCHAR(24) NOT NULL,
    validator_hash  VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id   INT UNSIGNED NOT NULL,
    kind            ENUM('income','expense') NOT NULL DEFAULT 'expense',
    name            VARCHAR(100) NOT NULL,
    color           VARCHAR(7) NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_archived     TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_owner_kind_name (owner_user_id, kind, name),
    CONSTRAINT fk_categories_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preset_items (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id         INT UNSIGNED NOT NULL,
    owner_user_id       INT UNSIGNED NOT NULL,
    name                VARCHAR(100) NOT NULL,
    default_amount      DECIMAL(10,2) NULL,
    auto_carry_forward  TINYINT(1) NOT NULL DEFAULT 0,
    carry_forward_day   TINYINT UNSIGNED NULL COMMENT 'Only used when carry_forward_interval = monthly. NULL = 1st of month, 0 = last Friday of month, 1-31 = that day (clamped to month length)',
    carry_forward_interval    ENUM('monthly','every_4_weeks') NOT NULL DEFAULT 'monthly',
    carry_forward_anchor_date DATE NULL COMMENT 'Only used when carry_forward_interval = every_4_weeks: one known occurrence date, from which every 28 days is generated',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    sort_order          INT NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_preset_items_category (category_id),
    KEY idx_preset_items_owner (owner_user_id),
    CONSTRAINT fk_preset_items_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_preset_items_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_batches (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id   INT UNSIGNED NOT NULL,
    filename        VARCHAR(255) NULL,
    rows_imported   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_batches_owner (owner_user_id),
    CONSTRAINT fk_import_batches_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id               INT UNSIGNED NOT NULL,
    kind                        ENUM('income','expense') NOT NULL,
    category_id                 INT UNSIGNED NOT NULL,
    preset_item_id              INT UNSIGNED NULL,
    description                 VARCHAR(255) NULL,
    amount                      DECIMAL(10,2) NOT NULL,
    txn_date                    DATE NOT NULL,
    source                      ENUM('manual','import','recurring') NOT NULL DEFAULT 'manual',
    recurring_origin_item_id    INT UNSIGNED NULL,
    import_batch_id             INT UNSIGNED NULL,
    notes                       TEXT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transactions_owner_date (owner_user_id, txn_date),
    KEY idx_transactions_category (category_id),
    KEY idx_transactions_import_batch (import_batch_id),
    CONSTRAINT fk_transactions_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_preset_item FOREIGN KEY (preset_item_id) REFERENCES preset_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_import_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_pots (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id   INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    pot_type        ENUM('general','goal') NOT NULL DEFAULT 'general',
    target_amount   DECIMAL(10,2) NULL,
    target_date     DATE NULL,
    color           VARCHAR(7) NULL,
    is_archived     TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_pots_owner (owner_user_id),
    CONSTRAINT fk_savings_pots_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_transactions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pot_id          INT UNSIGNED NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    txn_date        DATE NOT NULL,
    note            VARCHAR(255) NULL,
    import_batch_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_transactions_pot_date (pot_id, txn_date),
    CONSTRAINT fk_savings_transactions_pot FOREIGN KEY (pot_id) REFERENCES savings_pots(id) ON DELETE CASCADE,
    CONSTRAINT fk_savings_transactions_import_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS category_shares (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id             INT UNSIGNED NOT NULL,
    owner_user_id           INT UNSIGNED NOT NULL,
    shared_with_user_id     INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_shares (category_id, shared_with_user_id),
    KEY idx_category_shares_shared_with (shared_with_user_id),
    CONSTRAINT fk_category_shares_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_shares_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_shares_shared_with FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,
    entity_type     VARCHAR(50) NULL,
    entity_id       INT UNSIGNED NULL,
    meta            TEXT NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_user (user_id),
    KEY idx_audit_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key     VARCHAR(100) NOT NULL,
    setting_value   TEXT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
