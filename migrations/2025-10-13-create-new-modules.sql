-- Migration: 2025-10-13 Create New Simplified Modules
-- Creates the 5 new modules for Sales Fundamentals course
-- Run this BEFORE module-cleanup.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- ARCHIVE OLD MODULE ENTRIES (Don't delete due to foreign keys)
-- ============================================================================
UPDATE `training_modules` SET
  `required` = 0,
  `sort_order` = 999,
  `course_id` = NULL
WHERE `slug` IN (
  'products-product categories',
  'products-and-product categories',
  'products-product categories-new'
);

-- ============================================================================
-- INSERT/UPDATE NEW MODULES
-- ============================================================================

-- Module 1: intro-product (UPDATE existing)
INSERT INTO `training_modules` (`id`, `slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES (1, 'intro-product', 'Module 1: Introduction to Product & Product Superiority', 30, 'Learn Product history, market landscape, and competitive advantage', 1, 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 2: three-stage-filter (NEW)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('three-stage-filter', 'Module 2: The 3-Stage Filter Process', 20, 'Master our catalog-ready filtration competitive advantage', 1, 2, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 3: product-mastery (NEW - replaces products-product categories)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('product-mastery', 'Module 3: Product Mastery - Blue 10, Product B, Black 80', 35, 'Master the three core products and their use cases', 1, 3, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 4: objection-essentials (NEW)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('objection-essentials', 'Module 4: Objection Handling Essentials', 15, 'Brief practical objection handling for common scenarios', 1, 4, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 5: final-assessment (UPDATE existing)
INSERT INTO `training_modules` (`id`, `slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES (5, 'final-assessment', 'Module 5: Final Assessment & Certification', 20, 'Comprehensive certification exam - 80% required to pass', 1, 5, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- ============================================================================
-- ARCHIVE ALL OTHER MODULES
-- ============================================================================
UPDATE `training_modules` SET 
  `required` = 0,
  `sort_order` = 999,
  `course_id` = NULL
WHERE `slug` NOT IN (
  'intro-product',
  'three-stage-filter',
  'product-mastery',
  'objection-essentials',
  'final-assessment'
);

-- ============================================================================
-- VERIFY RESULTS
-- ============================================================================
SELECT id, slug, title, required, sort_order, course_id 
FROM training_modules 
WHERE required = 1
ORDER BY sort_order;

-- Should show exactly 5 rows

-- Migration complete: New modules created, old modules archived

