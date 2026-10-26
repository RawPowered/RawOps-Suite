-- Add payment_method_id column to orders table
-- Date: 2025-10-29

START TRANSACTION;

-- Check if column exists, add if not
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME = 'payment_method_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN payment_method_id BIGINT UNSIGNED DEFAULT NULL AFTER payment_status',
    'SELECT "Column payment_method_id already exists" AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add payment_date column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME = 'payment_date'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN payment_date DATETIME DEFAULT NULL AFTER payment_method_id',
    'SELECT "Column payment_date already exists" AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND CONSTRAINT_NAME = 'fk_orders_payment_method'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE orders ADD CONSTRAINT fk_orders_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL',
    'SELECT "Foreign key fk_orders_payment_method already exists" AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;

-- Verify
SHOW COLUMNS FROM orders LIKE 'payment%';














