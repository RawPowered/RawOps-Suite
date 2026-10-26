-- Legal Case Number System
-- Date: 2025-10-04
-- Purpose: Add sequential case numbering with format RAWOPS-MMYYYY-######

START TRANSACTION;

-- Add case_number column to legal_cases
ALTER TABLE `legal_cases` 
ADD COLUMN `case_number` VARCHAR(24) DEFAULT NULL AFTER `case_key`,
ADD UNIQUE KEY `idx_case_number` (`case_number`);

-- Create sequence table for case number generation
CREATE TABLE IF NOT EXISTS `legal_case_number_sequence` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_year` CHAR(6) NOT NULL COMMENT 'Format: MMYYYY',
  `last_sequence` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_month_year` (`month_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Case-specific backfill excluded from public source.
COMMIT;
