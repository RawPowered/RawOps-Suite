-- Migration: 2025-10-13 Multi-Course Architecture
-- Enables support for multiple training courses beyond single sales training program
-- Binary safe, uses INSERT...ON DUPLICATE KEY UPDATE to prevent errors

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================================
-- 1. COURSES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS `courses` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_course_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. COURSE MODULES JUNCTION TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS `course_modules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` int(10) UNSIGNED NOT NULL,
  `module_id` int(10) UNSIGNED NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_course_module` (`course_id`, `module_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_module_id` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. COURSE ENROLLMENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS `course_enrollments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED NOT NULL,
  `status` enum('enrolled','in_progress','completed','suspended') NOT NULL DEFAULT 'enrolled',
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completion_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_course` (`user_id`, `course_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. MODIFY TRAINING_MODULES - ADD COURSE_ID
-- ============================================================================
ALTER TABLE `training_modules` 
ADD COLUMN IF NOT EXISTS `course_id` int(10) UNSIGNED DEFAULT NULL AFTER `id`,
ADD KEY IF NOT EXISTS `idx_course_id` (`course_id`);

-- ============================================================================
-- 5. SEED INITIAL COURSES
-- ============================================================================
INSERT INTO `courses` (`id`, `name`, `description`, `duration_hours`, `sort_order`, `active`, `created_at`)
VALUES 
  (1, 'Sales Fundamentals', 'Simplified core training covering Product basics, Product superiority, 3-stage filter process, and essential sales skills.', 3.5, 1, 1, NOW()),
  (2, 'Advanced Product Training', 'Future expansion: Advanced product knowledge, market strategies, and specialized sales techniques.', 0, 2, 0, NOW())
ON DUPLICATE KEY UPDATE 
  `description` = VALUES(`description`),
  `duration_hours` = VALUES(`duration_hours`),
  `sort_order` = VALUES(`sort_order`),
  `active` = VALUES(`active`);

-- ============================================================================
-- 6. AUTO-ENROLL EXISTING TRAINEES IN SALES FUNDAMENTALS
-- ============================================================================
INSERT INTO `course_enrollments` (`user_id`, `course_id`, `status`, `enrolled_at`, `started_at`, `completion_percent`, `last_activity_at`)
SELECT DISTINCT 
  u.id as user_id,
  1 as course_id,
  CASE 
    WHEN EXISTS(SELECT 1 FROM training_progress tp WHERE tp.user_id = u.id AND tp.status = 'completed') THEN 'in_progress'
    ELSE 'enrolled'
  END as status,
  u.created_at as enrolled_at,
  (SELECT MIN(tp.started_at) FROM training_progress tp WHERE tp.user_id = u.id) as started_at,
  ROUND((
    SELECT COUNT(DISTINCT tp.module_id) * 100.0 / NULLIF((SELECT COUNT(*) FROM training_modules WHERE required = 1), 0)
    FROM training_progress tp
    WHERE tp.user_id = u.id AND tp.status = 'completed'
  ), 2) as completion_percent,
  (SELECT MAX(tp.completed_at) FROM training_progress tp WHERE tp.user_id = u.id) as last_activity_at
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
WHERE r.name IN ('trainee', 'sales_agent')
ON DUPLICATE KEY UPDATE
  `status` = CASE 
    WHEN `completed_at` IS NOT NULL THEN 'completed'
    WHEN `started_at` IS NOT NULL THEN 'in_progress'
    ELSE VALUES(`status`)
  END,
  `completion_percent` = VALUES(`completion_percent`),
  `last_activity_at` = VALUES(`last_activity_at`);

-- ============================================================================
-- 7. UPDATE EXISTING MODULES TO COURSE 1 (SALES FUNDAMENTALS)
-- ============================================================================
UPDATE `training_modules` SET `course_id` = 1 WHERE `course_id` IS NULL;

SET FOREIGN_KEY_CHECKS=1;

-- Migration complete: Multi-course architecture ready



