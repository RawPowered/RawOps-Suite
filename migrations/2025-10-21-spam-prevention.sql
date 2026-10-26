-- ============================================================================
-- Spam Prevention System Migration
-- Date: 2025-10-21
-- Description: Creates tables for spam blacklist and registration attempt logging
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================================
-- Table: spam_blacklist
-- Purpose: Store blacklisted emails, domains, names, phone patterns, and IPs
-- ============================================================================

CREATE TABLE IF NOT EXISTS `spam_blacklist` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_type` ENUM('email', 'domain', 'name_pattern', 'phone', 'ip_address') NOT NULL,
  `entry_value` VARCHAR(255) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `added_by_admin_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_entry_type_value` (`entry_type`, `entry_value`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: spam_registration_attempts
-- Purpose: Log all blocked spam registration attempts for analysis
-- ============================================================================

CREATE TABLE IF NOT EXISTS `spam_registration_attempts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) DEFAULT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `phone_number` VARCHAR(30) DEFAULT NULL,
  `business_name` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `detected_violations` JSON DEFAULT NULL COMMENT 'Array of detected spam patterns',
  `form_data` JSON DEFAULT NULL COMMENT 'Full form submission data',
  `spam_score` INT DEFAULT 0 COMMENT 'Calculated spam score',
  `blocked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_blocked_at` (`blocked_at`),
  KEY `idx_phone` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Insert default blacklist entries for known spam domains
-- ============================================================================

INSERT IGNORE INTO `spam_blacklist` (`entry_type`, `entry_value`, `reason`, `is_active`) VALUES
('domain', '.ru', 'High spam domain - Russian Federation TLD', 1),
('domain', '.sx', 'High spam domain - Sint Maarten TLD', 1),
('domain', '.mx', 'High spam domain - Mexico TLD', 1),
('domain', '.ci', 'High spam domain - Ivory Coast TLD', 1),
('domain', '.cx', 'High spam domain - Christmas Island TLD', 1),
('domain', '.cn', 'High spam domain - China TLD', 1),
('domain', '.br', 'High spam domain - Brazil TLD', 1),
('domain', '.ai', 'High spam domain - AI service TLD', 1),
('domain', '.io', 'High spam domain - Common bot service TLD', 1),
('domain', '.bot', 'High spam domain - Bot service TLD', 1);

-- ============================================================================
-- End of migration
-- ============================================================================

