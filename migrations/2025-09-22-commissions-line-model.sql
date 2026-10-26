START TRANSACTION;

-- Link commissions to specific order items when applicable
ALTER TABLE commissions 
  ADD COLUMN IF NOT EXISTS order_item_id INT NULL,
  ADD INDEX IF NOT EXISTS idx_commissions_item (order_item_id);

-- Try to align commissions.order_item_id type to order_items.id to avoid FK errors
SET @oid_type := (
  SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'id'
);
SET @ci_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'order_item_id'
);
SET @sql := IF(@ci_exists = 1 AND @oid_type IS NOT NULL,
  CONCAT('ALTER TABLE commissions MODIFY order_item_id ', @oid_type, ' NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Guarded FK add: only when engines are InnoDB and constraint missing
SET @eng_c := (SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions');
SET @eng_o := (SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items');
SET @fk_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_commissions_order_item'
);
SET @sql := IF(@fk_exists = 0 AND @eng_c = 'InnoDB' AND @eng_o = 'InnoDB',
  'ALTER TABLE commissions ADD CONSTRAINT fk_commissions_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Per-line commission fields on order_items
ALTER TABLE order_items 
  ADD COLUMN IF NOT EXISTS agent_commission_pct DECIMAL(6,3) NULL AFTER unit_price,
  ADD COLUMN IF NOT EXISTS agent_commission_amount DECIMAL(10,2) NULL AFTER agent_commission_pct,
  ADD COLUMN IF NOT EXISTS developer_commission_amount DECIMAL(10,2) NULL AFTER agent_commission_amount,
  ADD COLUMN IF NOT EXISTS ai_commission_amount DECIMAL(10,2) NULL AFTER developer_commission_amount;

COMMIT;
