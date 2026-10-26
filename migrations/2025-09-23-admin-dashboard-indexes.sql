-- Admin Dashboard Supporting Indexes
-- Uses SHOW INDEX guard pattern where IF NOT EXISTS is not supported.

START TRANSACTION;

-- orders(created_at, status)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_created_status'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE orders ADD INDEX idx_orders_created_status (created_at, status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- orders(wholesale_account_id, created_at)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_wholesale_created'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE orders ADD INDEX idx_orders_wholesale_created (wholesale_account_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- commissions(status, created_at, user_id)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND INDEX_NAME = 'idx_commissions_status_created_user'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE commissions ADD INDEX idx_commissions_status_created_user (status, created_at, user_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- training_progress(user_id, module_id, status, completed_at)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_progress' AND INDEX_NAME = 'idx_training_user_module_status_completed'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE training_progress ADD INDEX idx_training_user_module_status_completed (user_id, module_id, status, completed_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- user_roles(user_id, role_id)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND INDEX_NAME = 'idx_user_roles_user_role'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE user_roles ADD INDEX idx_user_roles_user_role (user_id, role_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_chat_logs(created_at)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_chat_logs' AND INDEX_NAME = 'idx_ai_chat_logs_created'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE ai_chat_logs ADD INDEX idx_ai_chat_logs_created (created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;


