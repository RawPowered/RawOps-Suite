-- -----------------------------------------------------------------------------
-- Commission Incinerator Snapshots Schema
-- -----------------------------------------------------------------------------
-- Context:
--   The admin commission center relies on `incinerator_snapshots` to archive
--   order payloads before destructive actions (incineration, cancellation).
--   Production currently throws `SQLSTATE[42S02]` because the table was never
--   created after porting the incinerator utilities from development.
--
-- Goal:
--   Provide an idempotent migration that creates the missing table with the
--   columns expected by `required/admin_incinerator.php` and
--   `required/commission-utils.php`. The migration also adds supporting
--   indexes so snapshot restores remain fast even as the archive grows.
-- -----------------------------------------------------------------------------

START TRANSACTION;

-- Create primary storage table for serialized snapshot payloads.
CREATE TABLE IF NOT EXISTS incinerator_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(64) NOT NULL,
    source_table VARCHAR(191) NOT NULL,
    source_column VARCHAR(191) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    snapshot_data LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata JSON NULL,

    PRIMARY KEY (id),
    KEY idx_incinerator_entity (entity_type, source_table),
    KEY idx_incinerator_source (source_table, source_id),
    KEY idx_incinerator_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure commissions can reference snapshots without duplicate columns.
SET @has_snapshot_col := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'commissions'
      AND COLUMN_NAME = 'snapshot_id'
);

SET @alter_snapshot_col := IF(
    @has_snapshot_col = 0,
    'ALTER TABLE commissions ADD COLUMN snapshot_id BIGINT UNSIGNED NULL AFTER order_item_id',
    'DO 0'
);

PREPARE stmt FROM @alter_snapshot_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Align column definition if it already exists but uses a different type.
SET @snapshot_col_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'commissions'
      AND COLUMN_NAME = 'snapshot_id'
);

SET @modify_snapshot_col := IF(
    @snapshot_col_type IS NOT NULL AND @snapshot_col_type NOT LIKE 'bigint%unsigned%',
    'ALTER TABLE commissions MODIFY COLUMN snapshot_id BIGINT UNSIGNED NULL',
    'DO 0'
);

PREPARE stmt FROM @modify_snapshot_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lookup indexes only if missing to keep migration idempotent.
SET @has_snapshot_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'commissions'
      AND INDEX_NAME = 'idx_commissions_snapshot'
);

SET @add_snapshot_idx := IF(
    @has_snapshot_idx = 0,
    'ALTER TABLE commissions ADD INDEX idx_commissions_snapshot (snapshot_id)',
    'DO 0'
);

PREPARE stmt FROM @add_snapshot_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Email notification delivery logs (used by automation debugger)
CREATE TABLE IF NOT EXISTS email_notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    payload_json JSON NULL,
    response_json JSON NULL,
    delivered TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_event (event_key, created_at),
    KEY idx_notification_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- -----------------------------------------------------------------------------
-- End migration
-- -----------------------------------------------------------------------------

