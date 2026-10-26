-- Migration: 2025-10-13 Module Cleanup and Course Assignment
-- Archives obsolete modules and assigns remaining modules to Sales Fundamentals course
-- Simplifies training to 5 core modules only

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================================
-- 1. ARCHIVE OBSOLETE MODULES
-- ============================================================================
UPDATE `training_modules` SET 
  `required` = 0,
  `sort_order` = 999,
  `course_id` = NULL
WHERE `slug` IN (
  'crm-mastery',
  'commission-structure',
  'profit-calculator-training',
  'ten-step-close',
  'ai-assisted-sales',
  'compliance-sales',
  'final-exam',
  'objection-mastery',
  'products-product categories',
  'products-and-product categories',
  'products-product categories-new'
);

-- ============================================================================
-- 2. UPDATE REMAINING CORE MODULES
-- ============================================================================
-- Module 1: Introduction to Product & Product Superiority
UPDATE `training_modules` SET
  `title` = 'Module 1: Introduction to Product & Product Superiority',
  `duration_minutes` = 30,
  `required` = 1,
  `sort_order` = 1,
  `course_id` = 1
WHERE `slug` = 'intro-product';

-- Module 2: The 3-Stage Filter Process (will be created as new file)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('three-stage-filter', 'Module 2: The 3-Stage Filter Process', 20, 'Competitive advantage through catalog-ready filtration', 1, 2, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 3: Product Mastery (rename from products-product categories-new)
UPDATE `training_modules` SET
  `slug` = 'product-mastery',
  `title` = 'Module 3: Product Mastery - Blue 10, Product B, Black 80',
  `duration_minutes` = 35,
  `content` = 'Master the three core Products and their ideal use cases',
  `required` = 1,
  `sort_order` = 3,
  `course_id` = 1
WHERE `slug` IN ('products-product categories', 'products-product categories-new');

-- Module 4: Brief Objection Handling (create new simplified version)
INSERT INTO `training_modules` (`slug`, `title`, `duration_minutes`, `content`, `required`, `sort_order`, `course_id`)
VALUES ('objection-essentials', 'Module 4: Objection Handling Essentials', 15, 'Brief, practical objection handling for common sales scenarios', 1, 4, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `required` = VALUES(`required`),
  `sort_order` = VALUES(`sort_order`),
  `course_id` = VALUES(`course_id`);

-- Module 5: Final Assessment
UPDATE `training_modules` SET
  `title` = 'Module 5: Final Assessment & Certification',
  `duration_minutes` = 20,
  `content` = 'Comprehensive knowledge check covering all training modules',
  `required` = 1,
  `sort_order` = 5,
  `course_id` = 1
WHERE `slug` = 'final-assessment';

-- ============================================================================
-- 3. ASSIGN MODULES TO COURSE 1 (SALES FUNDAMENTALS)
-- ============================================================================
INSERT INTO `course_modules` (`course_id`, `module_id`, `sort_order`, `required`)
SELECT 
  1 as course_id,
  tm.id as module_id,
  tm.sort_order,
  tm.required
FROM `training_modules` tm
WHERE tm.slug IN ('intro-product', 'three-stage-filter', 'product-mastery', 'objection-essentials', 'final-assessment')
  AND tm.required = 1
ON DUPLICATE KEY UPDATE
  `sort_order` = VALUES(`sort_order`),
  `required` = VALUES(`required`);

-- ============================================================================
-- 4. UPDATE COURSE DURATION
-- ============================================================================
UPDATE `courses` SET
  `duration_hours` = (
    SELECT SUM(tm.duration_minutes) / 60.0
    FROM course_modules cm
    JOIN training_modules tm ON tm.id = cm.module_id
    WHERE cm.course_id = 1 AND cm.required = 1
  )
WHERE `id` = 1;

SET FOREIGN_KEY_CHECKS=1;

-- Migration complete: Modules cleaned up and assigned to Sales Fundamentals course

