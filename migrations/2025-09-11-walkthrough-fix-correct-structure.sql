-- Migration: Fix Walkthrough Tracking - Correct Database Structure
-- Date: 2025-09-11
-- Purpose: Create walkthrough tracking table matching exact database structure from SQL dump

-- The welcome_walkthrough_tracking table already exists in the database with this exact structure:
-- CREATE TABLE `welcome_walkthrough_tracking` (
--   `id` int(11) NOT NULL,
--   `user_id` int(11) NOT NULL,
--   `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
--   `completed_at` timestamp NULL DEFAULT NULL,
--   `skipped` tinyint(1) DEFAULT 0
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks completion of user welcome walkthrough tours';

-- Since the table already exists, we just need to ensure it has the right data types
-- The issue was that our migration was using bigint(20) UNSIGNED for user_id, but the actual table uses int(11)

-- Update any existing walkthrough records to mark old ones as completed
UPDATE `welcome_walkthrough_tracking` 
SET `completed_at` = `viewed_at` 
WHERE `completed_at` IS NULL AND `skipped` = 0 AND `viewed_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR);

-- Add some sample walkthrough completion tracking for testing (optional)
-- This would normally be inserted by the JavaScript walkthrough system
INSERT IGNORE INTO `welcome_walkthrough_tracking` (`user_id`, `viewed_at`, `completed_at`, `skipped`) VALUES
(1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY, 0),
(3, NOW() - INTERVAL 2 HOURS, NULL, 0);

-- Ensure proper indexing exists (should already exist from dump)
-- ALTER TABLE `welcome_walkthrough_tracking` ADD PRIMARY KEY (`id`);
-- ALTER TABLE `welcome_walkthrough_tracking` ADD KEY `idx_user_id` (`user_id`);
-- ALTER TABLE `welcome_walkthrough_tracking` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
