-- Lab Results Schema — Public COA Directory
-- Date: 2025-09-26
-- Notes:
--  - Idempotent creation per 002-SQL-FORMATTING-RULES.md
--  - Binary-safe, explicit column lists, and guarded indexes
--  - Supports product linkage, batch/lots, and file storage with metadata

START TRANSACTION;

-- Core table for lab results / COAs
CREATE TABLE IF NOT EXISTS lab_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NULL,
  batch_code VARCHAR(100) NOT NULL,
  result_title VARCHAR(200) NOT NULL,
  lab_name VARCHAR(200) NULL,
  result_date DATE NULL,
  received_date DATE NULL,
  analytes_json JSON NULL,
  file_path VARCHAR(500) NULL,
  file_name VARCHAR(255) NULL,
  file_mime VARCHAR(100) NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lab_results_batch (batch_code),
  KEY idx_lab_results_product (product_id),
  KEY idx_lab_results_public_date (is_public, result_date),
  CONSTRAINT fk_lab_results_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guarded composite index on (batch_code, result_date) if not exists
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_results' AND INDEX_NAME = 'idx_lab_results_batch_date'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE lab_results ADD INDEX idx_lab_results_batch_date (batch_code, result_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional helper view for public listings
DROP VIEW IF EXISTS v_public_lab_results;
CREATE VIEW v_public_lab_results AS
  SELECT 
    lr.id,
    lr.product_id,
    p.sku AS product_sku,
    p.name AS product_name,
    lr.batch_code,
    lr.result_title,
    lr.lab_name,
    lr.result_date,
    lr.received_date,
    lr.analytes_json,
    lr.file_path,
    lr.file_name,
    lr.file_mime,
    lr.file_size_bytes,
    lr.notes,
    lr.created_at,
    lr.updated_at
  FROM lab_results lr
  LEFT JOIN products p ON p.id = lr.product_id
  WHERE lr.is_public = 1;

COMMIT;




