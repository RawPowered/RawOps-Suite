-- Migration: Optimize Admin Training Access and Role Simulation
-- Date: 2025-09-11
-- Purpose: Ensure admin can always access training and role simulation works properly
-- Compatible with existing foggksfx_product database structure

-- Create welcome_walkthrough_tracking table
CREATE TABLE IF NOT EXISTS `welcome_walkthrough_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `skipped` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Create admin_training_access table to track admin training reviews
CREATE TABLE IF NOT EXISTS `admin_training_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) UNSIGNED NOT NULL,
  `accessed_module_id` int(10) UNSIGNED DEFAULT NULL,
  `access_type` enum('review','simulation','direct') NOT NULL DEFAULT 'review',
  `simulated_role` varchar(50) DEFAULT NULL,
  `access_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_user` (`admin_user_id`),
  KEY `idx_module` (`accessed_module_id`),
  KEY `idx_access_type` (`access_type`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Create role_simulation_log table to track admin role simulations
CREATE TABLE IF NOT EXISTS `role_simulation_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) UNSIGNED NOT NULL,
  `simulated_role` varchar(50) NOT NULL,
  `target_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `actions_taken` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_user` (`admin_user_id`),
  KEY `idx_simulated_role` (`simulated_role`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Add admin override columns to existing training_progress table
ALTER TABLE `training_progress` 
ADD COLUMN `admin_override` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'True if admin marked complete for testing/review';

ALTER TABLE `training_progress` 
ADD COLUMN `override_admin_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Admin who performed override';

ALTER TABLE `training_progress` 
ADD COLUMN `override_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for admin override';

ALTER TABLE `training_progress` 
ADD COLUMN `override_timestamp` timestamp NULL DEFAULT NULL COMMENT 'When override was applied';

-- Create index for admin overrides
ALTER TABLE `training_progress` 
ADD INDEX `idx_training_progress_admin_override` (`admin_override`, `override_admin_id`);

-- Insert/update all required training modules (using correct column structure)
INSERT IGNORE INTO `training_modules` (`slug`, `title`, `content`, `duration_minutes`, `required`, `sort_order`) VALUES
('intro-product', 'Introduction to Product', 'Comprehensive introduction to Product botany, history, and market positioning', 45, 1, 1),
('products-and-product categories', 'Products and product categories', 'Complete guide to product catalog, product categories characteristics, and quality indicators', 60, 1, 2),
('compliance-sales', 'Compliance and Ethical Sales', 'Legal compliance, ethical sales practices, and regulatory awareness', 30, 1, 3),
('crm-mastery', 'CRM Mastery', 'Customer relationship management and sales toolkit mastery', 40, 1, 4),
('final-assessment', 'Knowledge Review', 'Comprehensive review of key concepts and knowledge check', 20, 1, 5),
('ten-step-close', 'Ten-Step Close', 'Master the proven ten-step sales closing methodology', 90, 1, 6),
('ai-assisted-sales', 'AI-Assisted Sales', 'Leverage AI tools to multiply sales effectiveness', 35, 1, 7),
('objection-mastery', 'Advanced Objection Handling', 'Advanced techniques for handling customer objections', 50, 1, 8),
('final-exam', 'Final Examination', 'Comprehensive final exam covering all training materials', 60, 1, 9);

-- Update any existing incomplete walkthrough records
UPDATE `welcome_walkthrough_tracking` 
SET `completed_at` = `viewed_at` 
WHERE `completed_at` IS NULL AND `skipped` = 0 AND `viewed_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Add table comments for documentation
ALTER TABLE `welcome_walkthrough_tracking` COMMENT = 'Tracks completion of user welcome walkthrough tours';
ALTER TABLE `admin_training_access` COMMENT = 'Logs admin access to training modules for review and simulation purposes';
ALTER TABLE `role_simulation_log` COMMENT = 'Tracks admin role simulation sessions for debugging and testing';
