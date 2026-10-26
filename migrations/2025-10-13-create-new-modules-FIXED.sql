-- Migration: 2025-10-13 Create New Simplified Modules (FIXED)
-- Creates the 5 new modules for Sales Fundamentals course
-- Does NOT delete (to avoid foreign key constraints)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- 1. ARCHIVE OLD MODULES (don't delete - foreign key constraints)
-- ============================================================================
UPDATE `training_modules` SET
  `required` = 0,
  `sort_order` = 999,
  `course_id` = NULL
WHERE `slug` IN (
  'products-product categories',
  'products-and-product categories',
  'products-product categories-new',
  'crm-mastery',
  'commission-structure',
  'profit-calculator-training',
  'ten-step-close',
  'ai-assisted-sales',
  'compliance-sales',
  'final-exam',
  'objection-mastery'
);

-- ============================================================================
-- 2. CREATE THE 5 NEW MODULES
-- ============================================================================

-- Module 1: intro-product (likely exists, just update)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('intro-product', 'Module 1: Introduction to Product & Product Superiority', 30, 'History, competitive advantage, product categories', 1, 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = 1,
  `sort_order` = 1,
  `course_id` = 1;

-- Module 2: three-stage-filter (NEW)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('three-stage-filter', 'Module 2: The 3-Stage Filter Process', 20, 'catalog-ready filtration competitive advantage', 1, 2, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = 1,
  `sort_order` = 2,
  `course_id` = 1;

-- Module 3: product-mastery (NEW)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('product-mastery', 'Module 3: Product Mastery - Blue 10, Product B, Black 80', 35, 'Master the three core products', 1, 3, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = 1,
  `sort_order` = 3,
  `course_id` = 1;

-- Module 4: objection-essentials (NEW)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('objection-essentials', 'Module 4: Objection Handling Essentials', 15, 'Brief practical objection handling', 1, 4, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = 1,
  `sort_order` = 4,
  `course_id` = 1;

-- Module 5: final-assessment (likely exists, just update)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('final-assessment', 'Module 5: Final Assessment & Certification', 20, 'Comprehensive certification exam', 1, 5, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `content` = VALUES(`content`),
  `required` = 1,
  `sort_order` = 5,
  `course_id` = 1;

-- ============================================================================
-- 3. VERIFY RESULTS
-- ============================================================================
SELECT id, slug, title, required, sort_order, course_id 
FROM training_modules 
WHERE required = 1 AND sort_order < 100
ORDER BY sort_order;

-- Should show exactly 5 modules

-- Migration complete: New modules created



