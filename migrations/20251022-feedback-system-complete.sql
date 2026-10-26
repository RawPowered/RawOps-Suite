-- ============================================================================
-- Complete Feedback System Migration
-- Date: 2025-10-22
-- Description: Creates all required tables for feedback system
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- 1. Page Visit Tracking Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `sales_agent_page_visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `page_url` VARCHAR(500) NOT NULL,
  `visit_count` INT UNSIGNED DEFAULT 1,
  `last_visited_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `has_given_feedback` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_page` (`user_id`, `page_url`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_feedback_status` (`has_given_feedback`),
  KEY `idx_visit_count` (`visit_count`),
  CONSTRAINT `fk_page_visits_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks page visits for sales agents to prompt feedback';

-- ============================================================================
-- 2. Admin Dev Tasks Table (for feedback storage)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `admin_dev_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `category` VARCHAR(100) DEFAULT 'General',
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled', 'on_hold') DEFAULT 'pending',
  `created_by_admin_id` BIGINT UNSIGNED NOT NULL,
  `assigned_to_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_category` (`category`),
  KEY `idx_assigned_to` (`assigned_to_admin_id`),
  KEY `idx_created_by` (`created_by_admin_id`),
  KEY `idx_due_date` (`due_date`),
  CONSTRAINT `fk_tasks_created_by` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_assigned_to` FOREIGN KEY (`assigned_to_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Admin development tasks and feedback tracking';

-- ============================================================================
-- 3. Admin Dev Task History Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `admin_dev_task_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `action_type` VARCHAR(50) NOT NULL COMMENT 'create, update, status_change, assign, comment',
  `action_description` TEXT,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_task_history_task` FOREIGN KEY (`task_id`) REFERENCES `admin_dev_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_history_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for admin dev tasks';

-- ============================================================================
-- 4. Verify Tables Were Created
-- ============================================================================

SELECT 
    'sales_agent_page_visits' as table_name,
    COUNT(*) as row_count
FROM sales_agent_page_visits
UNION ALL
SELECT 
    'admin_dev_tasks' as table_name,
    COUNT(*) as row_count
FROM admin_dev_tasks
UNION ALL
SELECT 
    'admin_dev_task_history' as table_name,
    COUNT(*) as row_count
FROM admin_dev_task_history;

COMMIT;

-- ============================================================================
-- Success Message
-- ============================================================================
SELECT '✅ Feedback system tables created successfully!' as message;





