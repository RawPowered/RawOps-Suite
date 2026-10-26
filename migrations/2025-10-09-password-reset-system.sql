-- Password Reset System and User Profile Updates
-- Generated: 2025-10-09
-- Description: Adds password reset token management, analytics tracking, and user profile update tracking

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================================
-- Password Reset Tokens Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `email` (`email`),
  KEY `expires_at` (`expires_at`),
  KEY `used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Password reset tokens with 1-hour expiry';

-- ============================================================================
-- User Profile Update History Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_profile_updates` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `field_name` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `field_name` (`field_name`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all user profile field updates for security and audit';

-- ============================================================================
-- Update auth_events to ensure forgot_password and password_reset are supported
-- ============================================================================

-- The auth_events table already supports these event types in the enum
-- But let's ensure the table exists and has proper indexes

ALTER TABLE `auth_events` 
ADD INDEX IF NOT EXISTS `idx_event_type` (`event_type`),
ADD INDEX IF NOT EXISTS `idx_user_id_event` (`user_id`, `event_type`),
ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`);

-- ============================================================================
-- Analytics Views for Admin Dashboard
-- ============================================================================

-- Password Reset Analytics View
CREATE OR REPLACE VIEW `password_reset_analytics` AS
SELECT 
  DATE(created_at) as reset_date,
  COUNT(*) as total_requests,
  COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as completed_resets,
  COUNT(CASE WHEN used_at IS NULL AND expires_at < NOW() THEN 1 END) as expired_tokens,
  COUNT(CASE WHEN used_at IS NULL AND expires_at >= NOW() THEN 1 END) as pending_tokens,
  ROUND(COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as completion_rate
FROM `password_reset_tokens`
GROUP BY DATE(created_at)
ORDER BY reset_date DESC;

-- User Activity Summary (includes password resets and profile updates)
CREATE OR REPLACE VIEW `user_activity_summary` AS
SELECT 
  u.id as user_id,
  u.email,
  u.first_name,
  u.last_name,
  u.status,
  u.last_login_at,
  (SELECT COUNT(*) FROM auth_events WHERE user_id = u.id AND event_type = 'login') as total_logins,
  (SELECT COUNT(*) FROM auth_events WHERE user_id = u.id AND event_type = 'forgot_password') as password_reset_requests,
  (SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = u.id AND used_at IS NOT NULL) as completed_password_resets,
  (SELECT COUNT(*) FROM user_profile_updates WHERE user_id = u.id) as profile_updates,
  (SELECT MAX(created_at) FROM user_profile_updates WHERE user_id = u.id) as last_profile_update
FROM users u;

-- ============================================================================
-- Clean up expired tokens (run periodically via cron or admin action)
-- ============================================================================

-- Stored procedure to clean up expired tokens older than 7 days
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `CleanupExpiredPasswordTokens`()
BEGIN
  DELETE FROM `password_reset_tokens` 
  WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  SELECT ROW_COUNT() as deleted_count;
END$$

DELIMITER ;

-- ============================================================================
-- Sample Analytics Queries (commented out - for admin reference)
-- ============================================================================

-- Get password reset statistics for last 30 days
-- SELECT * FROM password_reset_analytics WHERE reset_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY);

-- Get users with most password resets (potential security concern)
-- SELECT user_id, email, COUNT(*) as reset_count
-- FROM password_reset_tokens
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
-- GROUP BY user_id, email
-- ORDER BY reset_count DESC
-- LIMIT 20;

-- Get profile update activity by field
-- SELECT field_name, COUNT(*) as update_count, COUNT(DISTINCT user_id) as unique_users
-- FROM user_profile_updates
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
-- GROUP BY field_name
-- ORDER BY update_count DESC;

COMMIT;



