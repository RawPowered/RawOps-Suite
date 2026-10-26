-- User Consent and Email Verification System
-- Date: 2025-10-14
-- Purpose: Add consent management fields and email change verification system

START TRANSACTION;

-- 1) Add consent fields to users table
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `consent_sms` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SMS marketing consent',
  ADD COLUMN IF NOT EXISTS `consent_phone` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Phone call consent',
  ADD COLUMN IF NOT EXISTS `consent_email` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Email marketing consent',
  ADD COLUMN IF NOT EXISTS `consent_updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When consent was last updated';

-- 2) Create email change requests table
CREATE TABLE IF NOT EXISTS `email_change_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `old_email` VARCHAR(190) NOT NULL,
  `new_email` VARCHAR(190) NOT NULL,
  `verification_token` VARCHAR(128) NOT NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'verified', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`verification_token`),
  KEY `idx_status` (`status`, `expires_at`),
  CONSTRAINT `fk_email_change_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks email change requests with verification tokens';

-- 3) Create consent history table for audit trail
CREATE TABLE IF NOT EXISTS `user_consent_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `consent_type` ENUM('sms', 'phone', 'email') NOT NULL,
  `old_value` TINYINT(1) NOT NULL,
  `new_value` TINYINT(1) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_consent` (`user_id`, `consent_type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_consent_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all consent changes';

-- 4) Create index for email lookups on email_change_requests
ALTER TABLE `email_change_requests`
  ADD KEY IF NOT EXISTS `idx_new_email` (`new_email`);

COMMIT;

