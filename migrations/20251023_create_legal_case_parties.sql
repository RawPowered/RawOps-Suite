-- Migration: Create legal_case_parties table
-- Date: 2025-10-23
-- Description: Add parties tracking for legal cases (plaintiffs, defendants, witnesses, etc.)

CREATE TABLE IF NOT EXISTS `legal_case_parties` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `party_type` ENUM('plaintiff', 'defendant', 'witness', 'expert', 'other') NOT NULL DEFAULT 'other',
  `full_name` VARCHAR(255) NOT NULL,
  `entity_type` ENUM('individual', 'organization') DEFAULT 'individual',
  `contact_info` JSON DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `legal_cases`(`id`) ON DELETE CASCADE,
  INDEX `idx_case_id` (`case_id`),
  INDEX `idx_party_type` (`party_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private party seed records excluded from public source.
