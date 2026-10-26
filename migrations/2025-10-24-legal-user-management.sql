-- Legal User Management Enhancement
-- Date: 2025-10-24
-- Purpose: Add force password reset functionality for legal users created by admins

START TRANSACTION;

-- Add force_password_reset column to users table
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `force_password_reset` TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Force password change on next login';

-- Add index for performance on login checks
ALTER TABLE `users`
ADD INDEX IF NOT EXISTS `idx_force_password_reset` (`force_password_reset`);

COMMIT;

