-- User Preferences System
-- Date: 2025-10-22
-- Purpose: Add user preferences table for storing persistent user settings like walkthrough disable preference

START TRANSACTION;

-- Create user preferences table
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `disable_walkthroughs` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'User has disabled automatic walkthroughs',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-specific preference settings';

-- Add index for walkthrough queries
ALTER TABLE `user_preferences`
ADD KEY IF NOT EXISTS `idx_disable_walkthroughs` (`disable_walkthroughs`);

COMMIT;






