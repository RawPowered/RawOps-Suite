-- Migration: Fix Commission Foreign Key Constraint (Safe Version)
-- Date: 2025-10-29
-- Purpose: Ensure commissions.order_item_id FK constraint is properly configured
-- NOTE: This version only performs fixes, no diagnostic queries

START TRANSACTION;

-- 1. Add order_item_id column if it doesn't exist
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions' 
      AND COLUMN_NAME = 'order_item_id'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE commissions ADD COLUMN order_item_id BIGINT(20) UNSIGNED NULL AFTER order_id',
    'SELECT "order_item_id column already exists" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Align column types if they don't match
SET @target_type := (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'order_items' 
      AND COLUMN_NAME = 'id'
);

SET @current_type := (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions' 
      AND COLUMN_NAME = 'order_item_id'
);

SET @sql := IF(@target_type IS NOT NULL AND @current_type IS NOT NULL AND @current_type != @target_type,
    CONCAT('ALTER TABLE commissions MODIFY order_item_id ', @target_type, ' NULL'),
    'SELECT "Column types already match or NULL" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add index if missing
SET @idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'commissions'
      AND INDEX_NAME = 'idx_commissions_item'
);

SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE commissions ADD INDEX idx_commissions_item (order_item_id)',
    'SELECT "Index already exists" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add FK constraint if missing (only if both tables are InnoDB)
SET @fk_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_commissions_order_item'
);

SET @eng_c := (
    SELECT ENGINE 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions'
);

SET @eng_o := (
    SELECT ENGINE 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'order_items'
);

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions' 
      AND COLUMN_NAME = 'order_item_id'
);

-- Only add FK if all conditions are met
SET @sql := IF(
    @fk_exists = 0 AND @eng_c = 'InnoDB' AND @eng_o = 'InnoDB' AND @col_exists > 0,
    'ALTER TABLE commissions ADD CONSTRAINT fk_commissions_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE',
    'SELECT "FK constraint already exists or preconditions not met" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;

-- Success message
SELECT 'Migration complete - commission FK constraint configured' AS result;















