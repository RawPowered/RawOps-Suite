START TRANSACTION;

CREATE TABLE IF NOT EXISTS commission_types (
  type VARCHAR(64) NOT NULL PRIMARY KEY,
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO commission_types (type, description) VALUES
  ('sales_agent', 'Sales agent line commission'),
  ('developer',   'Developer administrative services commission'),
  ('ai_assist',   'AI assisted sales commission');

SET @has_idx := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND INDEX_NAME = 'idx_commissions_type'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE commissions ADD INDEX idx_commissions_type (commission_type);', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
