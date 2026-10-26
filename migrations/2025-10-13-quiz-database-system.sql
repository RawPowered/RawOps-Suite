-- Migration: 2025-10-13 Quiz Database System with Product Integration
-- Moves quiz questions from hardcoded PHP to database for dynamic management
-- Supports product linking, question rotation, and live context previews

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================================
-- 1. ENHANCE TRAINING_QUIZ_QUESTIONS TABLE
-- ============================================================================
ALTER TABLE `training_quiz_questions`
ADD COLUMN IF NOT EXISTS `product_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `module_id`,
ADD COLUMN IF NOT EXISTS `quiz_position` enum('intro','early','mid','late','final') NOT NULL DEFAULT 'mid' AFTER `product_id`,
ADD COLUMN IF NOT EXISTS `rotation_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `quiz_position`,
ADD COLUMN IF NOT EXISTS `rotation_priority` int(11) NOT NULL DEFAULT 50 AFTER `rotation_enabled`,
ADD COLUMN IF NOT EXISTS `context_preview` text DEFAULT NULL AFTER `rotation_priority`,
ADD COLUMN IF NOT EXISTS `requires_calculation` tinyint(1) NOT NULL DEFAULT 0 AFTER `context_preview`,
ADD COLUMN IF NOT EXISTS `calculation_template` text DEFAULT NULL AFTER `requires_calculation`,
ADD KEY IF NOT EXISTS `idx_product_id` (`product_id`),
ADD KEY IF NOT EXISTS `idx_quiz_position` (`quiz_position`),
ADD KEY IF NOT EXISTS `idx_module_position` (`module_id`, `quiz_position`);

-- ============================================================================
-- 2. QUIZ PRODUCT ASSIGNMENTS (Many-to-Many)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `quiz_product_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_question_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `assignment_weight` int(11) NOT NULL DEFAULT 100 COMMENT 'Higher weight = more likely to be selected',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Primary product for this question',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quiz_product` (`quiz_question_id`, `product_id`),
  KEY `idx_quiz_question_id` (`quiz_question_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. QUIZ QUESTION USAGE TRACKING
-- ============================================================================
CREATE TABLE IF NOT EXISTS `quiz_question_usage` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_question_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `module_id` int(10) UNSIGNED NOT NULL,
  `shown_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `answered_correctly` tinyint(1) DEFAULT NULL,
  `answer_given` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_module` (`user_id`, `module_id`),
  KEY `idx_quiz_question` (`quiz_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. QUIZ QUESTION POOL VIEW (Dynamic Selection)
-- ============================================================================
CREATE OR REPLACE VIEW `v_quiz_question_pool` AS
SELECT 
  tqq.id as question_id,
  tqq.module_id,
  tqq.quiz_position,
  tqq.question_text,
  tqq.question_type,
  tqq.options,
  tqq.correct_answer,
  tqq.explanation,
  tqq.rotation_enabled,
  tqq.rotation_priority,
  tqq.requires_calculation,
  tqq.product_id,
  p.sku as product_sku,
  p.name as product_name,
  p.wholesale_price as product_price,
  p.total_mg as product_total_mg,
  p.servings_10mg as product_servings,
  COUNT(qpa.id) as assigned_products_count,
  tqq.is_active
FROM training_quiz_questions tqq
LEFT JOIN products p ON p.id = tqq.product_id
LEFT JOIN quiz_product_assignments qpa ON qpa.quiz_question_id = tqq.id
WHERE tqq.is_active = 1
GROUP BY tqq.id;

-- ============================================================================
-- 5. MODIFY TRAINING_QUIZ_RESULTS FOR QUESTION TRACKING
-- ============================================================================
ALTER TABLE `training_quiz_results`
ADD COLUMN IF NOT EXISTS `questions_shown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of question IDs shown to user' AFTER `total_questions`;

-- ============================================================================
-- 6. CREATE QUIZ ROTATION ANALYTICS
-- ============================================================================
CREATE TABLE IF NOT EXISTS `quiz_rotation_analytics` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `times_shown` int(11) NOT NULL DEFAULT 0,
  `times_correct` int(11) NOT NULL DEFAULT 0,
  `times_incorrect` int(11) NOT NULL DEFAULT 0,
  `avg_answer_time_seconds` decimal(8,2) DEFAULT NULL,
  `last_shown_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_question_analytics` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- Migration complete: Quiz database system ready for product integration

