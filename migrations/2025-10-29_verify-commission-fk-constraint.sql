-- Migration: Verify and Fix Commission Foreign Key Constraint
-- Date: 2025-10-29
-- Purpose: Ensure commissions.order_item_id FK constraint is properly configured

START TRANSACTION;

-- 0. First check if required tables exist
SELECT 
    CASE 
        WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions')
            THEN 'EXISTS'
        ELSE 'MISSING'
    END AS commissions_table_status,
    CASE 
        WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items')
            THEN 'EXISTS'
        ELSE 'MISSING'
    END AS order_items_table_status;

-- 1. Check column types match between order_items.id and commissions.order_item_id (only if both tables exist)
SELECT 
    'order_items.id' AS column_name,
    COLUMN_TYPE AS data_type,
    IS_NULLABLE,
    COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'order_items' 
  AND COLUMN_NAME = 'id'
UNION ALL
SELECT 
    'commissions.order_item_id' AS column_name,
    COLUMN_TYPE AS data_type,
    IS_NULLABLE,
    COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'commissions' 
  AND COLUMN_NAME = 'order_item_id';

-- 2. Check if FK constraint exists
SELECT 
    kcu.CONSTRAINT_NAME,
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.DELETE_RULE,
    rc.UPDATE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc 
    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA 
    AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
WHERE kcu.TABLE_SCHEMA = DATABASE()
  AND kcu.CONSTRAINT_NAME = 'fk_commissions_order_item';

-- 3. Verify both tables are InnoDB (required for FK)
SELECT 
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('order_items', 'commissions');

-- 4. Check for orphaned commissions (commissions with invalid order_item_id)
-- This query will show any data integrity issues (only if commissions table exists)
SELECT 
    c.id AS commission_id,
    c.order_id,
    c.order_item_id,
    c.commission_type,
    c.amount,
    'ORPHANED - No matching order_item' AS issue
FROM commissions c
LEFT JOIN order_items oi ON oi.id = c.order_item_id
WHERE c.order_item_id IS NOT NULL 
  AND oi.id IS NULL
  AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions')
LIMIT 10;

-- 5. Check if order_item_id column exists in commissions table, if not add it
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions' 
      AND COLUMN_NAME = 'order_item_id'
);

SET @table_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions'
);

-- Add column if table exists but column doesn't
SET @sql := IF(@table_exists > 0 AND @col_exists = 0,
    'ALTER TABLE commissions ADD COLUMN order_item_id BIGINT(20) UNSIGNED NULL AFTER order_id, ADD INDEX idx_commissions_item (order_item_id)',
    'SELECT "order_item_id column exists or commissions table missing" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. If column types don't match, align them
-- Get the type from order_items.id
SET @target_type := (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'order_items' 
      AND COLUMN_NAME = 'id'
);

-- Only modify if types differ
SET @current_type := (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'commissions' 
      AND COLUMN_NAME = 'order_item_id'
);

SET @sql := IF(@target_type IS NOT NULL AND @current_type != @target_type,
    CONCAT('ALTER TABLE commissions MODIFY order_item_id ', @target_type, ' NULL'),
    'SELECT "Column types already match" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Ensure FK constraint exists (guarded - won't fail if already exists)
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

-- Only add FK if both tables are InnoDB and FK doesn't exist
SET @sql := IF(
    @fk_exists = 0 AND @eng_c = 'InnoDB' AND @eng_o = 'InnoDB',
    'ALTER TABLE commissions ADD CONSTRAINT fk_commissions_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE',
    'SELECT "FK constraint already exists or tables not InnoDB" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Add index on order_item_id if missing (improves FK performance)
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

COMMIT;

