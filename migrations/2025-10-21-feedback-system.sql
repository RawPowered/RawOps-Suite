-- ============================================================================
-- Sales Agent Feedback System Migration
-- Date: 2025-10-21
-- Description: Page visit tracking and feedback system integration
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- Page Visit Tracking Table
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
-- Update admin_dev_tasks to ensure it supports feedback category
-- (Table already exists, just ensuring compatibility)
-- ============================================================================

-- No changes needed to admin_dev_tasks table structure
-- It already supports category field which we'll use for "Feedback"

COMMIT;


