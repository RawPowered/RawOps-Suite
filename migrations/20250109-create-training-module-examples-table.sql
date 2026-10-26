-- Migration: Create Training Module Examples Table
-- Date: 2025-01-09
-- Description: Create table to track product usage in training modules and quiz questions

-- Drop existing table if it exists to ensure clean creation
DROP TABLE IF EXISTS `training_module_examples`;

-- Create training_module_examples table to track product assignments
CREATE TABLE `training_module_examples` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_name` varchar(100) NOT NULL COMMENT 'Module file name (e.g., intro-product.php)',
  `section_name` varchar(100) DEFAULT NULL COMMENT 'Section within module (e.g., Product Overview)',
  `product_sku` varchar(50) NOT NULL COMMENT 'Product SKU being referenced',
  `example_type` enum('example','quiz','calculation','reference') NOT NULL DEFAULT 'example' COMMENT 'Type of usage',
  `quiz_question_num` int(10) UNSIGNED DEFAULT NULL COMMENT 'Quiz question number if applicable',
  `correct_answer_key` varchar(10) DEFAULT NULL COMMENT 'Correct answer (A, B, C, D) for quiz questions',
  `usage_context` text DEFAULT NULL COMMENT 'Context description of how product is used',
  `notes` text DEFAULT NULL COMMENT 'Additional notes or instructions',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether this example is active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_module_product_type_quiz` (`module_name`, `product_sku`, `example_type`, `quiz_question_num`),
  KEY `idx_module_product` (`module_name`, `product_sku`),
  KEY `idx_product_type` (`product_sku`, `example_type`),
  KEY `idx_quiz_questions` (`module_name`, `quiz_question_num`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Insert training examples for new products with binary INSERT/UPDATE rules
INSERT INTO `training_module_examples` (`module_name`, `section_name`, `product_sku`, `example_type`, `quiz_question_num`, `usage_context`, `enabled`, `created_at`) VALUES
-- BLUE-10-32 (Beginner level) - Product introductions and basic examples
('intro-product.php', 'Product Overview', 'BLUE-10-32', 'example', NULL, 'Basic product introduction for beginners', 1, NOW()),
('products-product categories-new.php', 'Product Lineup', 'BLUE-10-32', 'example', NULL, 'Primary product for beginner training', 1, NOW()),
('profit-calculator-training.php', 'Basic Calculations', 'BLUE-10-32', 'calculation', NULL, 'Basic profit calculations for new users', 1, NOW()),
('crm-mastery.php', 'Pricing Examples', 'BLUE-10-32', 'example', NULL, 'Basic pricing examples in CRM training', 1, NOW()),

-- GOLD-30-32 (Intermediate level) - More complex examples
('products-product categories-new.php', 'Product Lineup', 'GOLD-30-32', 'example', NULL, 'Intermediate product for stronger doses', 1, NOW()),
('profit-calculator-training.php', 'Advanced Calculations', 'GOLD-30-32', 'calculation', NULL, 'Intermediate profit calculations', 1, NOW()),
('ten-step-close.php', 'Value Proposition', 'GOLD-30-32', 'example', NULL, 'Value proposition examples for intermediate users', 1, NOW()),
('objection-mastery.php', 'Pricing Objections', 'GOLD-30-32', 'example', NULL, 'Handling pricing objections with intermediate product', 1, NOW()),

-- BLACK-60-32 (Advanced level) - Complex scenarios and high-volume examples
('products-product categories-new.php', 'Product Lineup', 'BLACK-60-32', 'example', NULL, 'Advanced product for high-volume operations', 1, NOW()),
('profit-calculator-training.php', 'Advanced Calculations', 'BLACK-60-32', 'calculation', NULL, 'Advanced profit calculations for high-volume', 1, NOW()),
('ten-step-close.php', 'High-Value Sales', 'BLACK-60-32', 'example', NULL, 'High-value sales scenarios', 1, NOW()),
('ai-assisted-sales.php', 'AI Examples', 'BLACK-60-32', 'example', NULL, 'AI-assisted sales examples for advanced users', 1, NOW()),
('final-assessment.php', 'Comprehensive Test', 'BLACK-60-32', 'example', NULL, 'Final assessment scenarios', 1, NOW()),
('final-exam.php', 'Final Exam', 'BLACK-60-32', 'example', NULL, 'Final exam comprehensive testing', 1, NOW())
ON DUPLICATE KEY UPDATE
  `section_name` = VALUES(`section_name`),
  `usage_context` = VALUES(`usage_context`),
  `enabled` = VALUES(`enabled`),
  `updated_at` = NOW();

-- Insert quiz questions for new products with binary INSERT/UPDATE rules
INSERT INTO `training_module_examples` (`module_name`, `section_name`, `product_sku`, `example_type`, `quiz_question_num`, `correct_answer_key`, `usage_context`, `enabled`, `created_at`) VALUES
-- BLUE-10-32 Quiz Questions
('crm-mastery.php', 'Pricing Mastery', 'BLUE-10-32', 'quiz', 1, 'A', 'Basic pricing calculation for 10 bottles', 1, NOW()),
('profit-calculator-training.php', 'Basic Calculations', 'BLUE-10-32', 'quiz', 1, 'B', 'Basic profit calculation quiz', 1, NOW()),

-- GOLD-30-32 Quiz Questions  
('crm-mastery.php', 'Pricing Mastery', 'GOLD-30-32', 'quiz', 2, 'C', 'Intermediate pricing calculation for 15 bottles', 1, NOW()),
('ten-step-close.php', 'Value Proposition', 'GOLD-30-32', 'quiz', 1, 'A', 'Value proposition understanding quiz', 1, NOW()),

-- BLACK-60-32 Quiz Questions
('profit-calculator-training.php', 'Advanced Calculations', 'BLACK-60-32', 'quiz', 2, 'D', 'Advanced profit calculation quiz', 1, NOW()),
('final-assessment.php', 'Comprehensive Test', 'BLACK-60-32', 'quiz', 1, 'B', 'Final assessment comprehensive quiz', 1, NOW()),
('final-exam.php', 'Final Exam', 'BLACK-60-32', 'quiz', 1, 'C', 'Final exam comprehensive quiz', 1, NOW())
ON DUPLICATE KEY UPDATE
  `section_name` = VALUES(`section_name`),
  `correct_answer_key` = VALUES(`correct_answer_key`),
  `usage_context` = VALUES(`usage_context`),
  `enabled` = VALUES(`enabled`),
  `updated_at` = NOW();

-- Log the migration completion
INSERT INTO `migration_log` (`migration_name`, `execution_date`, `status`, `notes`) VALUES
('20250109-create-training-module-examples-table', NOW(), 'completed', 'Successfully created training_module_examples table and populated with examples and quiz questions for new product line (BLUE-10-32, GOLD-30-32, BLACK-60-32).');
